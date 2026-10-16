"""Staff recovery for scripts that need a human: approve-with-edit, retry auto repair, rewrite, cancel.

Every entry point claims the job atomically (status precondition) before doing slow LLM work, so a
double click cannot resume the media pipeline twice. The server alone decides whether a script passes.
"""
from __future__ import annotations

from dataclasses import dataclass, field

from config import logger
from core.purpose import PRODUCT_VIDEO, purpose_of
from core.script_text import sanitize_external_retailer_mentions
from core.state_machine import object_id, utcnow

EXHAUSTED = "AUTO_REPAIR_EXHAUSTED"
LEGACY_REJECTED = "REJECTED_BY_VALIDATOR"
AWAITING = "AWAITING_APPROVAL"
CANCELLED = "CANCELLED"
MIN_CHARS, MAX_CHARS = 40, 20000

REWRITE_INSTRUCTIONS = {
    "NATURAL": "Viết lại cho tự nhiên, gần gũi như đang trò chuyện, bớt cứng nhắc.",
    "SHORTER": "Rút gọn kịch bản, bỏ ý thừa, giữ các ý chính và đủ các phần.",
    "STRONGER_SALES": "Tăng sức thuyết phục bán hàng và lời kêu gọi hành động nhưng không thổi phồng công dụng.",
    "PROFESSIONAL": "Dùng giọng chuyên nghiệp, đáng tin cậy như chuyên gia chăm sóc da.",
    "HOOK_STRONGER": "Làm phần mở đầu thu hút hơn ngay vài giây đầu.",
    "LESS_JARGON": "Giảm thuật ngữ, giải thích đơn giản cho người mới.",
    "CUSTOM": "",
}


class RecoveryError(Exception):
    def __init__(self, message: str, status: int):
        super().__init__(message)
        self.status = status


@dataclass
class Outcome:
    status: str
    script_version: int
    repair_attempt: int = 0
    issues: list = field(default_factory=list)
    repaired: bool = False
    resume: bool = False
    script_text: str = ""
    message: str = ""

    def body(self, job_id: str) -> dict:
        data = {"ok": True, "job_id": job_id, "status": self.status, "script_version": self.script_version,
                "repair_attempt": self.repair_attempt, "issues": self.issues, "script_text": self.script_text}
        if self.repaired:
            data["repaired"] = True
        if self.message:
            data["message"] = self.message
        return data


def _artifacts(job: dict) -> dict:
    return job.get("artifacts") or {}


def _is_exhausted(job: dict) -> bool:
    return job.get("status") in (EXHAUSTED, LEGACY_REJECTED) or (
        job.get("status") == "FAILED" and job.get("script_status") == LEGACY_REJECTED)


def _load(db, job_id: str) -> dict:
    job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)})
    if not job:
        raise RecoveryError("Job not found", 404)
    return job


def _claim(db, job: dict) -> dict | None:
    return db.ai_idol_jobs.find_one_and_update(
        {"_id": job["_id"], "status": job.get("status")},
        {"$set": {"status": "IN_PROGRESS", "current_stage": "VALIDATING_SCRIPT", "updated_at": utcnow()}},
    )


def _release(db, job: dict) -> None:
    """Put the job back where it was after an unexpected error, so staff can try again."""
    db.ai_idol_jobs.update_one({"_id": job["_id"], "status": "IN_PROGRESS"}, {"$set": {
        "status": job.get("status"), "current_stage": job.get("current_stage"), "updated_at": utcnow()}})


def _is_auto(job: dict) -> bool:
    from core.pipeline import should_auto_approve_script
    config = job.get("configuration") or {}
    mode = str(config.get("approval_mode") or "").upper()
    return should_auto_approve_script(config) or (purpose_of(job) == PRODUCT_VIDEO and mode != "MANUAL")


def _tracking(job: dict, result, source: str) -> dict:
    """Append this run's versions/validation/repairs after the ones already stored."""
    artifacts = _artifacts(job)
    versions = list(artifacts.get("script_versions") or [])
    history = list(artifacts.get("validation_history") or [])
    repairs = list(artifacts.get("repair_history") or [])
    offset, now = len(versions), utcnow()
    for entry in result.history:
        v = offset + entry["version"]
        versions.append({"version": v, "text": entry.get("text", ""), "created_at": now,
                         "source": source if entry["version"] == 1 else "auto_repair"})
        history.append({"version": v, "passed": entry["passed"], "issues": entry["issues"]})
        repair = entry.get("repair") or {}
        if repair.get("to_version"):
            repairs.append({"from_version": v, "to_version": offset + repair["to_version"],
                            "issue_codes": repair.get("issue_codes", [])})
    return {"artifacts.script_versions": versions, "artifacts.validation_history": history,
            "artifacts.repair_history": repairs, "artifacts.repair_attempt": result.repair_attempts,
            "artifacts.script_version": len(versions)}


def _sync_segment(db, job: dict, segment_status: str, error: str | None = None, reopen_campaign: bool = False) -> None:
    now = utcnow()
    if job.get("segment_id"):
        db.ai_idol_segments.update_one({"_id": object_id(str(job["segment_id"]))}, {"$set": {
            "status": segment_status, "error_message": error, "updated_at": now}})
    campaign_id = job.get("campaign_id")
    if not campaign_id:
        return
    values = {"progress.failed": db.ai_idol_segments.count_documents(
        {"campaign_id": str(campaign_id), "status": "FAILED"}), "updated_at": now}
    if reopen_campaign:
        values.update({"status": "GENERATING", "error_message": None})
    db.ai_idol_campaigns.update_one({"_id": object_id(str(campaign_id))}, {"$set": values})


def _next_script_version(db, job_id: str) -> int:
    latest = db.ai_idol_scripts.find_one({"job_id": job_id}, sort=[("version", -1)], projection={"version": 1})
    return int((latest or {}).get("version", 0)) + 1


def _script_record(db, job: dict, text: str, status: str, **extra) -> int:
    job_id, now = str(job["_id"]), utcnow()
    version = _next_script_version(db, job_id)
    db.ai_idol_scripts.insert_one({
        "job_id": job_id, "product_id": job.get("product_id"), "content_mode": job.get("content_mode"),
        "script_text": text, "version": version, "status": status, "created_at": now, "updated_at": now, **extra})
    return version


def _park_exhausted(db, job: dict, result, tracking: dict) -> Outcome:
    feedback = str(result.feedback or "")[:4000]
    attempts = result.repair_attempts
    db.ai_idol_jobs.update_one({"_id": job["_id"]}, {"$set": {
        **tracking, "artifacts.script_text": "", "artifacts.rejected_script_text": str(result.script or "")[:MAX_CHARS],
        "artifacts.validation_feedback": feedback, "status": EXHAUSTED, "current_stage": EXHAUSTED,
        "error_message": f"Kịch bản chưa đạt sau {attempts} lần tự sửa: {feedback}"[:4000],
        "failed_stage": None, "updated_at": utcnow()}})
    _sync_segment(db, job, "FAILED", feedback or None)
    return Outcome(EXHAUSTED, tracking["artifacts.script_version"], attempts,
                   [i.to_dict() for i in result.issues], script_text=result.script)


def _park_awaiting(db, job: dict, text: str, tracking: dict, result, repaired: bool) -> Outcome:
    _script_record(db, job, text, "PENDING_APPROVAL")
    db.ai_idol_jobs.update_one({"_id": job["_id"]}, {"$set": {
        **tracking, "artifacts.script_text": text, "artifacts.rejected_script_text": "",
        "artifacts.validation_feedback": "", "script_status": "PENDING_APPROVAL", "status": AWAITING,
        "current_stage": "AWAITING_SCRIPT_APPROVAL", "failed_stage": None, "error_message": None,
        "updated_at": utcnow()}})
    _sync_segment(db, job, "AWAITING_APPROVAL", reopen_campaign=True)
    return Outcome(AWAITING, tracking["artifacts.script_version"], result.repair_attempts,
                   [i.to_dict() for i in result.issues], repaired=repaired, script_text=text)


def _approve_and_resume(db, job: dict, text: str, tracking: dict, result, approved_by: str) -> Outcome:
    now = utcnow()
    version = _script_record(db, job, text, "APPROVED", approved_at=now, approved_by=approved_by)
    unset = {"artifacts.avatar_video_path": "", "artifacts.final_video_path": "", "final_video_path": "",
             "video_id": "", "artifacts.rejected_script_text": ""}
    if text != str(_artifacts(job).get("script_text", "")).strip():
        unset.update({"artifacts.audio_path": "", "artifacts.audio_pipeline_version": ""})
    db.ai_idol_jobs.update_one({"_id": job["_id"]}, {"$set": {
        **tracking, "artifacts.script_text": text, "script_status": "APPROVED", "script_version": version,
        "script_approved_at": now, "status": "QUEUED", "current_stage": "SCRIPT_APPROVED",
        "failed_stage": None, "error_message": None, "updated_at": now}, "$unset": unset})
    _sync_segment(db, job, "GENERATING", reopen_campaign=True)
    return Outcome("QUEUED", tracking["artifacts.script_version"], result.repair_attempts,
                   [i.to_dict() for i in result.issues], resume=True, script_text=text)


def _run(db, job: dict, text: str, source: str, finish) -> Outcome:
    """Claim, validate-and-repair `text`, then let `finish(result, tracking)` choose the outcome."""
    from engines.script_validator import validate_and_repair_script
    if not _claim(db, job):
        raise RecoveryError("Kịch bản đang được xử lý hoặc đã đổi trạng thái.", 409)
    try:
        result = validate_and_repair_script(text, job.get("product_snapshot") or {}, purpose_of(job))
        tracking = _tracking(job, result, source)
        if not result.passed:
            return _park_exhausted(db, job, result, tracking)
        return finish(result, tracking)
    except Exception:
        logger.exception("[SCRIPT-RECOVERY] job %s: unexpected error, restoring state", job["_id"])
        _release(db, job)
        raise


def approve(db, job_id: str, submitted: str | None) -> Outcome:
    job = _load(db, job_id)
    if job.get("status") not in (AWAITING, EXHAUSTED, LEGACY_REJECTED, "FAILED"):
        raise RecoveryError("Kịch bản không ở trạng thái chờ duyệt.", 409)
    artifacts = _artifacts(job)
    if submitted is not None and str(submitted).strip():
        text, source = sanitize_external_retailer_mentions(submitted).strip(), "admin_edit"
    else:
        text = str(artifacts.get("script_text") or artifacts.get("rejected_script_text") or "").strip()
        source = "approve_revalidate"
    if len(text) < MIN_CHARS:
        raise RecoveryError("Kịch bản cần ít nhất 40 ký tự.", 400)
    if len(text) > MAX_CHARS:
        raise RecoveryError("Kịch bản không được vượt quá 20.000 ký tự.", 400)

    def finish(result, tracking):
        if result.script.strip() == text:
            return _approve_and_resume(db, job, text, tracking, result, "ADMIN")
        return _park_awaiting(db, job, result.script, tracking, result, repaired=True)

    return _run(db, job, text, source, finish)


def _draft(job: dict) -> str:
    artifacts = _artifacts(job)
    return str(artifacts.get("rejected_script_text") or artifacts.get("script_text") or "").strip()


def _finish_automatic(db, job: dict, was_exhausted: bool):
    def finish(result, tracking):
        if was_exhausted and _is_auto(job):
            return _approve_and_resume(db, job, result.script, tracking, result, "AUTO_REPAIR_RECOVERY")
        return _park_awaiting(db, job, result.script, tracking, result, repaired=result.repair_attempts > 0)
    return finish


def retry_repair(db, job_id: str) -> Outcome:
    job = _load(db, job_id)
    if not _is_exhausted(job):
        raise RecoveryError("Kịch bản không ở trạng thái cần sửa lại.", 409)
    text = _draft(job)
    if not text:
        raise RecoveryError("Không có bản nháp để sửa lại.", 400)
    return _run(db, job, text, "retry_repair", _finish_automatic(db, job, True))


def _rewrite(job: dict, text: str, instruction: str, custom_text: str) -> str:
    from engines.script_repair import _product_facts
    from engines.script_validator import _revision_structure
    from providers.llm_provider import LLMProvider
    from core.text_budget import clip_text
    directive = REWRITE_INSTRUCTIONS[instruction] if instruction != "CUSTOM" else custom_text
    snapshot, purpose = job.get("product_snapshot") or {}, purpose_of(job)
    system = ("Bạn là biên tập viên kịch bản bán mỹ phẩm tiếng Việt. Viết lại kịch bản theo yêu cầu, "
              "giữ đúng sự thật sản phẩm, không cam kết trị khỏi, không kể trải nghiệm cá nhân, không nhắc sàn "
              "thương mại ngoài SkinSyntax, không dùng ký hiệu, emoji hay số viết tắt. "
              + _revision_structure(purpose) + " Chỉ trả về kịch bản, không giải thích.")
    user = (f"KỊCH BẢN HIỆN TẠI:\n\"\"\"\n{clip_text(text, 6000)}\n\"\"\"\n\nYÊU CẦU CHỈNH SỬA: {directive}\n\n"
            f"THÔNG TIN SẢN PHẨM GỐC:\n{_product_facts(snapshot)}")
    return sanitize_external_retailer_mentions(LLMProvider().invoke(system, user)).strip()


def rewrite(db, job_id: str, instruction: str, custom_text: str = "") -> Outcome:
    instruction = str(instruction or "").upper()
    custom_text = str(custom_text or "").strip()
    if instruction not in REWRITE_INSTRUCTIONS:
        raise RecoveryError("Yêu cầu viết lại không hợp lệ.", 400)
    if instruction == "CUSTOM" and not (3 <= len(custom_text) <= 1000):
        raise RecoveryError("Yêu cầu tùy chỉnh cần từ 3 đến 1000 ký tự.", 400)
    job = _load(db, job_id)
    was_exhausted = _is_exhausted(job)
    if job.get("status") != AWAITING and not was_exhausted:
        raise RecoveryError("Kịch bản không ở trạng thái có thể viết lại.", 409)
    text = _draft(job)
    if not text:
        raise RecoveryError("Không có bản nháp để viết lại.", 400)
    if not _claim(db, job):
        raise RecoveryError("Kịch bản đang được xử lý hoặc đã đổi trạng thái.", 409)
    try:
        rewritten = _rewrite(job, text, instruction, custom_text)
    except Exception:
        logger.exception("[SCRIPT-RECOVERY] job %s: rewrite LLM failed", job["_id"])
        _release(db, job)
        raise RecoveryError("Không viết lại được kịch bản lúc này, vui lòng thử lại.", 502)
    if len(rewritten) < MIN_CHARS:
        _release(db, job)
        raise RecoveryError("Bản viết lại quá ngắn, vui lòng thử lại.", 502)
    # Hand the claim back so _run can take it again with the same precondition.
    _release(db, job)
    return _run(db, job, rewritten, f"rewrite_{instruction.lower()}", _finish_automatic(db, job, was_exhausted))


def cancel(db, job_id: str) -> Outcome:
    job = _load(db, job_id)
    if job.get("status") != AWAITING and not _is_exhausted(job):
        raise RecoveryError("Kịch bản không ở trạng thái có thể hủy.", 409)
    if not db.ai_idol_jobs.find_one_and_update({"_id": job["_id"], "status": job.get("status")}, {"$set": {
            "status": CANCELLED, "current_stage": CANCELLED, "error_message": "Đã hủy bởi quản trị viên.",
            "updated_at": utcnow()}}):
        raise RecoveryError("Kịch bản đã đổi trạng thái.", 409)
    _sync_segment(db, job, "CANCELLED", "Đã hủy bởi quản trị viên.")
    artifacts = _artifacts(job)
    return Outcome(CANCELLED, int(artifacts.get("script_version") or 0), int(artifacts.get("repair_attempt") or 0))
