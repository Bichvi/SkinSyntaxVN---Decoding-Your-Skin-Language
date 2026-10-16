import os
from bson import ObjectId
from core.state_machine import checkpoint_job, update_job_stage, fail_job, update_job_snapshot, get_job, get_db, utcnow
from config import AUDIO_PIPELINE_VERSION, logger
from core.purpose import LIVE_ONLY, PRODUCT_VIDEO, purpose_of
from core.script_text import sanitize_external_retailer_mentions
from engines.audio_mixer import lead_in_seconds


def should_auto_approve_script(config: dict) -> bool:
    """Explicit approval_mode wins; otherwise auto approval only for agent-managed safe policies."""
    mode = str(config.get("approval_mode") or "").upper()
    if mode == "AUTO":
        return True
    if mode == "MANUAL":
        return False
    return bool(
        config.get("agent_managed")
        and config.get("script_approval_mode") == "auto"
        and config.get("agent_autonomy_policy") in {"auto_preview", "auto_publish"}
    )

def start_pipeline_thread(job_id: str):
    """Compatibility entry point; work is now persisted in the Celery queue."""
    from tasks import generate_job_task
    result = generate_job_task.delay(job_id)
    logger.info("[PIPELINE] Queued durable task %s for job %s", result.id, job_id)
    return result.id

def run_ai_idol_pipeline(job_id: str) -> bool:
    logger.info("[PIPELINE] Starting/recovering job %s", job_id)
    db = get_db()

    # 1. Load Job
    job = get_job(job_id)
    if not job:
        logger.error(f"[PIPELINE-CORE] Job {job_id} not found.")
        return False

    product_id = job["product_id"]
    content_mode = job["content_mode"]
    config = job["configuration"]
    purpose = purpose_of(job)

    # Import engines dynamically to prevent circular imports
    from engines.product_collector import collect_product_and_snapshot
    from engines.knowledge_retriever import retrieve_knowledge
    from engines.content_agent import generate_content_and_script
    from engines.script_validator import validate_and_repair_script

    # Every completed stage stores a checkpoint. Retry therefore resumes instead of regenerating.
    artifacts = job.get("artifacts") or {}
    snapshot = job.get("product_snapshot")
    if not snapshot:
        try:
            update_job_stage(job_id, "COLLECTING_PRODUCT")
            snapshot = collect_product_and_snapshot(product_id)
            update_job_snapshot(job_id, snapshot)
        except Exception as e:
            fail_job(job_id, "COLLECTING_PRODUCT", f"Lỗi lấy dữ liệu sản phẩm: {e}")
            return False

    # STAGE 2: Knowledge Retriever (RAG)
    try:
        update_job_stage(job_id, "RETRIEVING_KNOWLEDGE")
        knowledge = artifacts.get("knowledge")
        if knowledge is None:
            knowledge = retrieve_knowledge(snapshot)
            checkpoint_job(job_id, {"artifacts.knowledge": knowledge})
    except Exception as e:
        fail_job(job_id, "RETRIEVING_KNOWLEDGE", f"Lỗi RAG truy xuất kiến thức: {e}")
        return False

    # STAGE 3 & 4: Content Generation & Script Validation (Revision Loop)
    script_text = artifacts.get("script_text", "")
    cleaned_script_text = sanitize_external_retailer_mentions(script_text)
    if cleaned_script_text != script_text:
        logger.warning("[PIPELINE] Removed external retailer mention from job %s before approval/media", job_id)
        script_text = cleaned_script_text
        job.setdefault("artifacts", {})["script_text"] = script_text
        db.ai_idol_jobs.update_one(
            {"_id": ObjectId(job_id)},
            {"$set": {"artifacts.script_text": script_text, "updated_at": utcnow()}},
        )
        scripts_collection = getattr(db, "ai_idol_scripts", None)
        if scripts_collection is not None:
            latest_script = scripts_collection.find_one({"job_id": job_id}, sort=[("version", -1)])
            if latest_script:
                scripts_collection.update_one(
                    {"_id": latest_script["_id"]},
                    {"$set": {"script_text": script_text, "updated_at": utcnow()}},
                )
    try:
        if not script_text:
            update_job_stage(job_id, "GENERATING_CONTENT")
            draft = generate_content_and_script(snapshot, knowledge, content_mode, config, purpose)
            result = validate_and_repair_script(
                draft, snapshot, purpose,
                on_event=lambda kind, version, _text: update_job_stage(
                    job_id, "VALIDATING_SCRIPT" if kind == "validating" else "REPAIRING_SCRIPT"),
            )
            script_text = result.script
            versions, history, repairs = _version_records(draft, result)
            tracking = {
                "artifacts.script_versions": versions, "artifacts.validation_history": history,
                "artifacts.repair_history": repairs, "artifacts.repair_attempt": result.repair_attempts,
                "artifacts.script_version": len(versions),
            }
            if not result.passed:
                # Keep the last draft for staff, but never store it as artifacts.script_text
                # (that field means "usable script"). The job is parked, not failed or cancelled.
                feedback = result.feedback
                checkpoint_job(job_id, {
                    **tracking,
                    "artifacts.rejected_script_text": str(script_text or "")[:20000],
                    "artifacts.validation_feedback": str(feedback or "")[:4000],
                    "status": "AUTO_REPAIR_EXHAUSTED", "current_stage": "AUTO_REPAIR_EXHAUSTED",
                    "error_message": f"Kịch bản chưa đạt sau {result.repair_attempts} lần tự sửa: {feedback}"[:4000],
                    "failed_stage": None,
                })
                logger.warning("[PIPELINE] Job %s: auto repair exhausted: %s", job_id, feedback)
                return False
            db.ai_idol_scripts.update_one(
                {"job_id": job_id, "version": 1},
                {"$set": {"product_id": product_id, "content_mode": content_mode,
                           "script_text": script_text, "status": "PENDING_APPROVAL",
                           "updated_at": utcnow()},
                 "$setOnInsert": {"created_at": utcnow()}},
                upsert=True,
            )
            checkpoint_job(job_id, {**tracking, "artifacts.script_text": script_text})
    except Exception as e:
        fail_job(job_id, "GENERATING_CONTENT", f"Lỗi lập kịch bản/kiểm duyệt: {e}")
        return False

    # Product videos publish themselves (spec D12): the validated script needs no human gate.
    mode = str(config.get("approval_mode") or "").upper()
    product_video_auto = purpose == PRODUCT_VIDEO and mode != "MANUAL"
    if job.get("script_status") != "APPROVED" and (product_video_auto or should_auto_approve_script(config)):
        now = utcnow()
        db.ai_idol_scripts.update_one(
            {"job_id": job_id, "version": 1},
            {"$set": {
                "status": "APPROVED",
                "approved_by": "AUTO_APPROVAL_MODE" if mode == "AUTO" else ("PRODUCT_VIDEO_AUTO" if product_video_auto else "AI_IDOL_AGENT_POLICY"),
                "approved_at": now,
                "updated_at": now,
            }},
        )
        db.ai_idol_jobs.update_one(
            {"_id": ObjectId(job_id)},
            {"$set": {
                "script_status": "APPROVED",
                "script_version": 1,
                "script_approved_at": now,
                "current_stage": "SCRIPT_AUTO_APPROVED",
                "updated_at": now,
            }},
        )
        job["script_status"] = "APPROVED"
        logger.info("[PIPELINE] Job %s script auto-approved by stored Agent policy", job_id)

    # Manual campaigns still require a human to approve the exact words before
    # any voice or video is generated. Legacy jobs therefore keep their behavior.
    if job.get("script_status") != "APPROVED":
        now = utcnow()
        db.ai_idol_jobs.update_one(
            {"_id": ObjectId(job_id)},
            {"$set": {
                "status": "AWAITING_APPROVAL",
                "current_stage": "AWAITING_SCRIPT_APPROVAL",
                "script_status": "PENDING_APPROVAL",
                "failed_stage": None,
                "error_message": None,
                "updated_at": now,
            }},
        )
        if job.get("segment_id"):
            db.ai_idol_segments.update_one(
                {"_id": ObjectId(job["segment_id"])},
                {"$set": {
                    "status": "AWAITING_APPROVAL",
                    "error_message": None,
                    "updated_at": now,
                }},
            )
        logger.info("[PIPELINE] Job %s is waiting for human script approval", job_id)
        return False

    # Heavy media engines are loaded only after human approval. This keeps the
    # review step fast and avoids reserving media resources while an editor types.
    from engines.voice_engine import generate_voice_audio

    # STAGE 5: Voice Generation (Edge TTS)
    audio_path = artifacts.get("audio_path", "")
    audio_refreshed = artifacts.get("audio_pipeline_version") != AUDIO_PIPELINE_VERSION
    try:
        if audio_refreshed or not audio_path or not os.path.isfile(audio_path):
            update_job_stage(job_id, "GENERATING_VOICE")
            audio_path = generate_voice_audio(job_id, script_text, config)
            checkpoint_job(job_id, {
                "artifacts.audio_path": audio_path,
                "artifacts.audio_pipeline_version": AUDIO_PIPELINE_VERSION,
            })
    except Exception as e:
        fail_job(job_id, "GENERATING_VOICE", f"Lỗi sinh giọng nói: {e}")
        return False

    # Native Syna uses the approved speech and product data directly. Do not send
    # the mascot or the full presentation scene to a human-face lip-sync model.
    if config.get("avatar_mode") == "syna_3d":
        try:
            from engines.syna_engine import RendererUnavailable, render_syna_video
            update_job_stage(job_id, "RENDERING")
            final_video_path = render_syna_video(job_id, audio_path, script_text, snapshot, config, purpose=purpose)
            checkpoint_job(job_id, {"artifacts.final_video_path": final_video_path,
                                    "artifacts.video_renderer": "syna-native-v4"})
        except RendererUnavailable as e:
            # Not a job failure: script and voice are checkpointed, so park the job
            # and let the resume beat requeue it when Syna answers again.
            logger.warning("[PIPELINE] Job %s waits for the Syna renderer: %s", job_id, e)
            update_job_stage(job_id, "WAITING_RENDERER", status="WAITING_RENDERER")
            checkpoint_job(job_id, {
                "error_message": f"Đang chờ Syna: {e}"[:1000],
                "failed_stage": None,
                "waiting_renderer_since": utcnow(),
            })
            return False
        except Exception as e:
            fail_job(job_id, "RENDERING", f"Lỗi tạo video Syna 3D: {e}")
            return False
    else:
        final_video_path = _render_legacy_media(job_id, audio_path, script_text, snapshot, config, artifacts, audio_refreshed)
        if not final_video_path:
            return False

    # STAGE 7b: Audio mastering + background music. Runs on every attempt from
    # the raw render and the clean voice, so resumed jobs never double-mix.
    from engines.audio_mixer import mix_program_audio
    from engines.body_clip import board_price, product_page_clip
    padded = config.get("avatar_mode") == "syna_3d"
    rendered_path = final_video_path
    mix_snapshot = snapshot
    if purpose != LIVE_ONLY and snapshot.get("pricing"):
        # Board shows the resolved price (flash if running, else regular).
        board = board_price(snapshot, purpose)
        mix_snapshot = {**snapshot, "price": board, "sale_price": board}
    elif purpose == PRODUCT_VIDEO:
        # Evergreen product videos without a price claim get no price title card.
        mix_snapshot = {**snapshot, "price": None, "sale_price": None}
    applied: dict = {}
    final_video_path = mix_program_audio(
        job_id, final_video_path, audio_path, config, mix_snapshot,
        # Syna renders the lead-in/tail herself (greeting and goodbye wave).
        video_padded=padded, applied=applied,
    )
    page_path, block_reason = product_page_clip(
        job_id, purpose, final_video_path, audio_path,
        intro=applied_intro(job_id, config, padded=padded, rendered=rendered_path,
                            final=final_video_path, applied=applied),
    )

    return _complete_video_job(job_id, product_id, config, final_video_path, audio_path, db,
                               purpose=purpose, campaign_id=job.get("campaign_id"),
                               page_path=page_path, block_reason=block_reason, snapshot=snapshot,
                               script_text=script_text)


def _version_records(draft: str, result) -> tuple[list, list, list]:
    now = utcnow()
    versions, history, repairs = [], [], []
    for entry in result.history:
        v = entry["version"]
        versions.append({"version": v, "text": entry.get("text", ""), "created_at": now,
                         "source": "generated" if v == 1 else "auto_repair"})
        history.append({"version": v, "passed": entry["passed"], "issues": entry["issues"]})
        repair = entry.get("repair") or {}
        if repair.get("to_version"):
            repairs.append({"from_version": v, "to_version": repair["to_version"],
                            "issue_codes": repair.get("issue_codes", [])})
    return versions, history, repairs


def final_machine_check(db, job_id: str, product_id: str, snapshot: dict, script_text, video_path: str,
                        duration, purpose: str) -> list[str]:
    """Last gate before READY. Returns problem strings (empty = pass)."""
    from engines.script_validator import validate_script
    problems = []
    try:
        job = db.ai_idol_jobs.find_one({"_id": ObjectId(job_id)}) or {}
    except Exception:
        job = {}
    if str(job.get("product_id") or product_id) != str(product_id):
        problems.append("product_id không khớp job")
    if not video_path or not os.path.isfile(video_path):
        problems.append("File video không tồn tại")
    try:
        if not duration or float(duration) <= 0:
            problems.append("Video có thời lượng bằng 0")
    except (TypeError, ValueError):
        problems.append("Không đo được thời lượng video")
    if script_text is not None:
        try:
            problems.extend(f"Kịch bản: {i.reason}" for i in validate_script(script_text, snapshot or {}, purpose)
                            if i.severity == "error")
        except Exception as e:
            problems.append(f"Không kiểm tra lại được kịch bản: {e}")
    return problems


def applied_intro(job_id: str, config: dict, *, padded: bool, rendered: str, final: str, applied: dict) -> float:
    """Seconds before the first word in `final`, so script sections can be located on its timeline."""
    if final != rendered:
        return float(applied.get("intro", 0.0))  # what the mixer actually placed
    # Mix skipped or failed: only a Syna render carries its own lead-in.
    return lead_in_seconds(job_id, config)[0] if padded else 0.0


def _render_legacy_media(job_id, audio_path, script_text, snapshot, config, artifacts, audio_refreshed):
    from engines.avatar_engine import generate_avatar_video
    from engines.video_composer import compose_final_video
    # STAGE 6: Avatar Generation (Interface Provider)
    avatar_video_path = "" if audio_refreshed else artifacts.get("avatar_video_path", "")
    try:
        if not avatar_video_path or not os.path.isfile(avatar_video_path):
            update_job_stage(job_id, "GENERATING_AVATAR")
            avatar_video_path = generate_avatar_video(job_id, audio_path, config)
            checkpoint_job(job_id, {"artifacts.avatar_video_path": avatar_video_path})
    except Exception as e:
        fail_job(job_id, "GENERATING_AVATAR", f"Lỗi tạo video avatar nhép môi: {e}")
        return False

    # STAGE 7: Video Composer (FFmpeg Compositing)
    final_video_path = "" if audio_refreshed else artifacts.get("final_video_path", "")
    try:
        if not final_video_path or not os.path.isfile(final_video_path):
            update_job_stage(job_id, "RENDERING")
            final_video_path = compose_final_video(
                job_id, avatar_video_path, audio_path, script_text, snapshot, config
            )
            checkpoint_job(job_id, {"artifacts.final_video_path": final_video_path})
    except Exception as e:
        fail_job(job_id, "RENDERING", f"Lỗi FFmpeg render composite video: {e}")
        return False
    return final_video_path


def _complete_video_job(job_id, product_id, config, final_video_path, audio_path, db, *,
                        purpose="live_only", campaign_id=None, page_path=None, block_reason=None, snapshot=None,
                        script_text=None):
    from core.pricing import claim_from_pricing
    from engines.video_composer import get_audio_duration
    # STAGE 8: Complete and set status to READY
    try:
        snapshot = snapshot or {}
        claim = None
        if purpose != LIVE_ONLY:
            claim = claim_from_pricing(str(snapshot.get("id") or product_id), snapshot.get("pricing"))
        # Probe the final file: the music intro/outro makes it longer than the voice.
        duration = get_audio_duration(final_video_path)
        problems = final_machine_check(db, job_id, product_id, snapshot, script_text, final_video_path, duration, purpose)
        if problems:
            fail_job(job_id, "FINAL_CHECK", "Kiểm tra cuối thất bại: " + "; ".join(problems))
            return False
        # Save complete video metadata
        video_doc = {
            "job_id": job_id,
            "product_id": product_id,
            "file_path": final_video_path,
            "duration": duration,
            "format": config.get("format", "9:16"),
            "resolution": "720x1280" if config.get("format") == "9:16" else "1280x720",
            "purpose": purpose,
            "campaign_id": campaign_id,
            # What media publishing uploads for product pages (None: never shown there).
            "product_page_file_path": page_path,
            "product_page_block_reason": block_reason,
            # Prices the video may speak; the storefront hides it once they stop holding.
            "price_claim": claim,
            "created_at": utcnow()
        }
        existing_video = db.ai_idol_videos.find_one({"job_id": job_id})
        if existing_video:
            db.ai_idol_videos.update_one({"_id": existing_video["_id"]}, {"$set": video_doc})
            video_id = str(existing_video["_id"])
        else:
            video_id = str(db.ai_idol_videos.insert_one(video_doc).inserted_id)

        db.ai_idol_jobs.update_one(
            {"_id": ObjectId(job_id)},
            {
                "$set": {
                    "status": "READY",
                    "current_stage": "READY",
                    "video_id": video_id,
                    "final_video_path": final_video_path,
                    "failed_stage": None,
                    "error_message": None,
                    "updated_at": utcnow()
                }
            }
        )
        logger.info(f"[PIPELINE-CORE] Job {job_id} completed successfully! Video ID: {video_id}")
        return True
    except Exception as e:
        fail_job(job_id, "READY", f"Lỗi lưu trữ metadata video hoàn chỉnh: {e}")
        return False


def run_ai_idol_pipeline_async(job_id: str):
    """Legacy name kept for callers created before the durable queue migration."""
    return run_ai_idol_pipeline(job_id)
