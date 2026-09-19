import os
import uuid
from flask import Flask, request, jsonify, send_file
from agents.service import cancel_agent_run, confirm_agent_run
from config import AGENT_ENABLED, AVATARS_DIR, BACKGROUNDS_DIR, PORT, logger
from core.campaigns import create_campaign, get_campaign, list_campaigns
from core.agent_runs import create_agent_run, get_agent_run, list_agent_runs, update_agent_run
from core.state_machine import create_job, ensure_indexes, get_job, get_db, object_id, utcnow
from core.pipeline import start_pipeline_thread
from core.script_text import sanitize_external_retailer_mentions
from tasks import prepare_campaign_task, generate_job_task, process_agent_run_task

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 40 * 1024 * 1024

try:
    ensure_indexes()
except Exception as exc:
    logger.warning("[APP] Index initialization deferred: %s", exc)


@app.route("/api/health", methods=["GET"])
def api_health():
    try:
        get_db().command("ping")
        mongo = "ok"
    except Exception as exc:
        mongo = f"error: {exc}"
    healthy = mongo == "ok"
    return jsonify({
        "ok": healthy,
        "service": "ai-idol",
        "mongo": mongo,
        "agent_enabled": AGENT_ENABLED,
    }), 200 if healthy else 503


def _agent_disabled_response():
    return jsonify({"ok": False, "message": "Luồng AI Idol Agent đang tắt trong cấu hình."}), 503


@app.route("/api/ai-idol/agent/runs", methods=["POST"])
def api_create_agent_run():
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    try:
        payload = request.get_json(silent=True) or {}
        created_by = str(payload.pop("created_by", "") or "").strip()[:120] or None
        run_id, created = create_agent_run(payload, created_by=created_by)
        if created:
            process_agent_run_task.delay(run_id)
        return jsonify({"ok": True, "run_id": run_id, "created": created}), 202 if created else 200
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400
    except Exception as exc:
        logger.exception("[APP] Unable to create AI Idol Agent run")
        return jsonify({"ok": False, "message": str(exc)}), 500


@app.route("/api/ai-idol/agent/runs", methods=["GET"])
def api_list_agent_runs():
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    return jsonify({
        "ok": True,
        "runs": list_agent_runs(request.args.get("limit", 20, type=int)),
    })


@app.route("/api/ai-idol/agent/runs/<run_id>", methods=["GET"])
def api_get_agent_run(run_id):
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    try:
        run = get_agent_run(run_id)
        if not run:
            return jsonify({"ok": False, "message": "Agent run not found"}), 404
        return jsonify({"ok": True, "data": run})
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/agent/runs/<run_id>/confirm", methods=["POST"])
def api_confirm_agent_run(run_id):
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    try:
        run = confirm_agent_run(run_id, request.get_json(silent=True) or {})
        process_agent_run_task.delay(run_id)
        return jsonify({"ok": True, "data": run}), 202
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/agent/runs/<run_id>/retry", methods=["POST"])
def api_retry_agent_run(run_id):
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    try:
        run = get_agent_run(run_id)
        if not run:
            return jsonify({"ok": False, "message": "Agent run not found"}), 404
        if run.get("status") not in {"NEEDS_ATTENTION", "NEEDS_INPUT"}:
            return jsonify({"ok": False, "message": "Agent run chưa ở trạng thái có thể thử lại."}), 409
        update_agent_run(run_id, {
            "status": "RECEIVED",
            "current_stage": "RECEIVED",
            "error_message": None,
            "retry_count": int(run.get("retry_count") or 0) + 1,
        })
        process_agent_run_task.delay(run_id)
        return jsonify({"ok": True, "run_id": run_id}), 202
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/agent/runs/<run_id>/cancel", methods=["POST"])
def api_cancel_agent_run(run_id):
    if not AGENT_ENABLED:
        return _agent_disabled_response()
    try:
        return jsonify({"ok": True, "data": cancel_agent_run(run_id)})
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/assets", methods=["POST"])
def api_upload_asset():
    """Store verified campaign media in the isolated AI Idol volume."""
    kind = str(request.form.get("kind", "")).strip().lower()
    target_dir = {"avatar": AVATARS_DIR, "background": BACKGROUNDS_DIR}.get(kind)
    upload = request.files.get("file")
    if target_dir is None or upload is None:
        return jsonify({"ok": False, "message": "kind và file là bắt buộc"}), 400
    max_bytes = (35 if kind == "avatar" else 10) * 1024 * 1024
    content = upload.read(max_bytes + 1)
    if len(content) > max_bytes:
        limit = 35 if kind == "avatar" else 10
        return jsonify({"ok": False, "message": f"Tệp không được lớn hơn {limit} MB"}), 413
    from engines.video_composer import detect_avatar_extension, detect_image_extension
    extension = (
        detect_avatar_extension(content) if kind == "avatar" else detect_image_extension(content)
    )
    allowed = {".png", ".jpg", ".webp", ".mp4", ".webm"} if kind == "avatar" else {
        ".png", ".jpg", ".webp"
    }
    if extension not in allowed:
        message = (
            "Nhân vật phải là ảnh PNG/JPG/WebP hoặc video MP4/WebM hợp lệ"
            if kind == "avatar"
            else "Background phải là ảnh PNG, JPG hoặc WebP hợp lệ"
        )
        return jsonify({"ok": False, "message": message}), 415
    filename = f"{kind}_{uuid.uuid4().hex}{extension}"
    path = target_dir / filename
    path.write_bytes(content)
    return jsonify({"ok": True, "kind": kind, "filename": filename})


@app.route("/api/ai-idol/campaigns", methods=["POST"])
def api_create_campaign():
    try:
        campaign_id, created = create_campaign(request.get_json(silent=True) or {})
        if created:
            prepare_campaign_task.delay(campaign_id)
        return jsonify({"ok": True, "campaign_id": campaign_id, "created": created}), 202 if created else 200
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400
    except Exception as exc:
        logger.exception("[APP] Unable to create campaign")
        return jsonify({"ok": False, "message": str(exc)}), 500


@app.route("/api/ai-idol/campaigns", methods=["GET"])
def api_list_campaigns():
    return jsonify({"ok": True, "campaigns": list_campaigns(request.args.get("limit", 30, type=int))})


@app.route("/api/ai-idol/campaigns/<campaign_id>", methods=["GET"])
def api_get_campaign(campaign_id):
    try:
        campaign = get_campaign(campaign_id)
        if not campaign:
            return jsonify({"ok": False, "message": "Campaign not found"}), 404
        return jsonify({"ok": True, "data": campaign})
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/campaigns/<campaign_id>/retry", methods=["POST"])
def api_retry_campaign(campaign_id):
    try:
        db = get_db()
        campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
        if not campaign:
            return jsonify({"ok": False, "message": "Campaign not found"}), 404
        failed_segments = list(db.ai_idol_segments.find({"campaign_id": campaign_id, "status": "FAILED"}))
        if not failed_segments:
            return jsonify({"ok": False, "message": "Campaign has no failed segments"}), 400
        db.ai_idol_campaigns.update_one({"_id": campaign["_id"]}, {"$set": {
            "status": "GENERATING", "error_message": None, "updated_at": utcnow(),
        }})
        queued = 0
        for segment in failed_segments:
            job_id = segment.get("job_id")
            if job_id:
                db.ai_idol_jobs.update_one({"_id": object_id(job_id)}, {"$set": {
                    "status": "CREATED", "error_message": None, "updated_at": utcnow(),
                }, "$inc": {"retry_count": 1}})
                db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {
                    "status": "GENERATING", "error_message": None, "updated_at": utcnow(),
                }})
                generate_job_task.delay(job_id)
                queued += 1
        if queued == 0:
            prepare_campaign_task.delay(campaign_id)
        return jsonify({"ok": True, "queued": queued})
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/jobs/<job_id>/script/approve", methods=["POST"])
def api_approve_job_script(job_id):
    try:
        db = get_db()
        job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)})
        if not job:
            return jsonify({"ok": False, "message": "Job not found"}), 404
        if job.get("status") not in {"AWAITING_APPROVAL", "FAILED"}:
            return jsonify({
                "ok": False,
                "message": "Kịch bản không ở trạng thái chờ duyệt.",
            }), 409
        payload = request.get_json(silent=True) or {}
        script_text = sanitize_external_retailer_mentions(payload.get("script_text", "")).strip()
        if len(script_text) < 40:
            return jsonify({
                "ok": False,
                "message": "Kịch bản cần ít nhất 40 ký tự.",
            }), 400
        if len(script_text) > 20000:
            return jsonify({
                "ok": False,
                "message": "Kịch bản không được vượt quá 20.000 ký tự.",
            }), 400

        now = utcnow()
        previous_text = str((job.get("artifacts") or {}).get("script_text", "")).strip()
        latest = db.ai_idol_scripts.find_one(
            {"job_id": job_id}, sort=[("version", -1)], projection={"version": 1}
        )
        version = int((latest or {}).get("version", 0)) + 1
        db.ai_idol_scripts.insert_one({
            "job_id": job_id,
            "product_id": job.get("product_id"),
            "content_mode": job.get("content_mode"),
            "script_text": script_text,
            "version": version,
            "status": "APPROVED",
            "approved_at": now,
            "created_at": now,
            "updated_at": now,
        })

        unset_values = {
            "artifacts.avatar_video_path": "",
            "artifacts.final_video_path": "",
            "final_video_path": "",
            "video_id": "",
        }
        if script_text != previous_text:
            unset_values.update({
                "artifacts.audio_path": "",
                "artifacts.audio_pipeline_version": "",
            })
        db.ai_idol_jobs.update_one(
            {"_id": job["_id"]},
            {
                "$set": {
                    "artifacts.script_text": script_text,
                    "script_status": "APPROVED",
                    "script_version": version,
                    "script_approved_at": now,
                    "status": "QUEUED",
                    "current_stage": "SCRIPT_APPROVED",
                    "failed_stage": None,
                    "error_message": None,
                    "updated_at": now,
                },
                "$unset": unset_values,
            },
        )
        campaign_id = job.get("campaign_id")
        if job.get("segment_id"):
            db.ai_idol_segments.update_one(
                {"_id": object_id(job["segment_id"])},
                {"$set": {
                    "status": "GENERATING",
                    "error_message": None,
                    "updated_at": now,
                }},
            )
        if campaign_id:
            failed = db.ai_idol_segments.count_documents({
                "campaign_id": str(campaign_id), "status": "FAILED"
            })
            db.ai_idol_campaigns.update_one(
                {"_id": object_id(str(campaign_id))},
                {"$set": {
                    "status": "GENERATING",
                    "progress.failed": failed,
                    "error_message": None,
                    "updated_at": now,
                }},
            )
        generate_job_task.delay(job_id)
        return jsonify({
            "ok": True,
            "job_id": job_id,
            "script_version": version,
            "status": "QUEUED",
        }), 202
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400


@app.route("/api/ai-idol/campaigns/<campaign_id>/video", methods=["GET"])
def api_get_campaign_video(campaign_id):
    try:
        campaign = get_db().ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
        if not campaign:
            return jsonify({"ok": False, "message": "Campaign not found"}), 404
        path = campaign.get("master_video_path")
        if not path or not os.path.isfile(path):
            return jsonify({"ok": False, "message": "Master video is not ready"}), 404
        return send_file(path, mimetype="video/mp4", conditional=True)
    except ValueError as exc:
        return jsonify({"ok": False, "message": str(exc)}), 400

@app.route("/api/ai-idol/jobs", methods=["POST"])
def api_create_job():
    data = request.json or {}
    product_id = data.get("product_id")
    content_mode = data.get("content_mode", "Product Intro")
    configuration = data.get("configuration", {})
    
    if not product_id:
        return jsonify({"ok": False, "message": "Missing product_id"}), 400
        
    try:
        job_id = create_job(product_id, content_mode, configuration)
        # Launch background process thread
        start_pipeline_thread(job_id)
        return jsonify({"ok": True, "job_id": job_id, "status": "CREATED"})
    except Exception as e:
        logger.error(f"[APP] Error creating job: {e}")
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/jobs/<job_id>", methods=["GET"])
def api_get_job(job_id):
    try:
        job = get_job(job_id)
        if not job:
            return jsonify({"ok": False, "message": "Job not found"}), 404
        return jsonify({"ok": True, "data": job})
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/jobs/<job_id>/retry", methods=["POST"])
def api_retry_job(job_id):
    try:
        job = get_job(job_id)
        if not job:
            return jsonify({"ok": False, "message": "Job not found"}), 404
            
        if job.get("status") != "FAILED":
            return jsonify({"ok": False, "message": "Only FAILED jobs can be retried"}), 400
            
        db = get_db()
        db.ai_idol_jobs.update_one(
            {"_id": object_id(job_id)},
            {
                "$set": {
                    "status": "CREATED",
                    "error_message": None,
                    "updated_at": utcnow()
                },
                "$inc": {"retry_count": 1}
            }
        )
        
        # Resume background thread
        generate_job_task.delay(job_id)
        return jsonify({"ok": True, "message": "Retry initiated", "job_id": job_id})
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/jobs/<job_id>/approve", methods=["POST"])
def api_approve_job(job_id):
    try:
        db = get_db()
        job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)})
        if not job:
            return jsonify({"ok": False, "message": "Job not found"}), 404
            
        if job.get("status") != "READY":
            return jsonify({"ok": False, "message": "Only READY jobs can be approved"}), 409
        db.ai_idol_jobs.update_one(
            {"_id": object_id(job_id)},
            {
                "$set": {
                    "status": "APPROVED",
                    "updated_at": utcnow()
                }
            }
        )
        return jsonify({"ok": True, "message": "Job APPROVED successfully"})
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/jobs/<job_id>/schedule", methods=["POST"])
def api_schedule_job(job_id):
    data = request.json or {}
    schedule_time_str = data.get("schedule_time", "20:00")
    
    try:
        db = get_db()
        job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)})
        if not job:
            return jsonify({"ok": False, "message": "Job not found"}), 404
            
        schedule_doc = {
            "job_id": job_id,
            "product_id": job.get("product_id"),
            "video_id": job.get("video_id"),
            "schedule_time": schedule_time_str,
            "status": "SCHEDULED",
            "created_at": utcnow(),
            "updated_at": utcnow()
        }
        res_sched = db.ai_idol_schedules.insert_one(schedule_doc)
        
        db.ai_idol_jobs.update_one(
            {"_id": object_id(job_id)},
            {
                "$set": {
                    "status": "SCHEDULED",
                    "updated_at": utcnow()
                }
            }
        )
        return jsonify({
            "ok": True, 
            "message": f"Successfully scheduled for {schedule_time_str}", 
            "schedule_id": str(res_sched.inserted_id)
        })
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/schedules", methods=["GET"])
def api_list_schedules():
    try:
        db = get_db()
        cursor = db.ai_idol_schedules.find().sort("created_at", -1)
        schedules = []
        for doc in cursor:
            doc["_id"] = str(doc["_id"])
            for key in ["created_at", "updated_at"]:
                if key in doc and hasattr(doc[key], "isoformat"):
                    doc[key] = doc[key].isoformat()
            schedules.append(doc)
        return jsonify({"ok": True, "schedules": schedules})
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

@app.route("/api/ai-idol/videos/<video_id>", methods=["GET"])
def api_get_video_file(video_id):
    try:
        db = get_db()
        video = db.ai_idol_videos.find_one({"_id": object_id(video_id)})
        if not video:
            return jsonify({"ok": False, "message": "Video not found"}), 404
            
        file_path = video.get("file_path")
        if not os.path.exists(file_path):
            return jsonify({"ok": False, "message": f"Video file not found at {file_path}"}), 404
            
        return send_file(file_path, mimetype="video/mp4", conditional=True)
    except Exception as e:
        return jsonify({"ok": False, "message": str(e)}), 500

if __name__ == "__main__":
    logger.info(f"[APP] Launching Flask AI Idol Service on Port {PORT}...")
    app.run(host="0.0.0.0", port=PORT, debug=False)
