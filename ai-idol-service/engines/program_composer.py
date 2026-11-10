from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

from config import PROGRAM_DIR, TEMP_DIR, logger
from core.show_types import ClipInfo, ProgramItem
from core.state_machine import get_db, object_id, utcnow
from engines.show_scheduler import build_program


def probe_duration(path: str) -> float:
    result = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", path],
        capture_output=True, text=True, timeout=30, check=True,
    )
    return round(float(result.stdout.strip()), 3)


def _concat_line(path: str) -> str:
    normalized = str(Path(path).resolve()).replace("\\", "/")
    return "file '" + normalized.replace("'", "'\\''") + "'\n"


def concat_lines(program: list[ProgramItem], paths: dict[str, str]) -> str:
    """Concat-demuxer text for a timeline; a clip may appear several times."""
    return "".join(_concat_line(paths[item.segment_id]) for item in program)


def _file_size(path: str) -> int:
    return os.path.getsize(path)


def _free_bytes(path) -> int:
    return shutil.disk_usage(path).free


def _needs_attention(db, campaign_id: str, message: str) -> None:
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "NEEDS_ATTENTION", "error_message": message, "updated_at": utcnow(),
    }})


def _run_concat(concat_path, output_path) -> None:
    copy_cmd = [
        "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat_path),
        "-c", "copy", "-movflags", "+faststart", str(output_path),
    ]
    result = subprocess.run(copy_cmd, capture_output=True, text=True, timeout=1800)
    if result.returncode != 0:
        logger.warning("[PROGRAM] Stream-copy concat failed; retrying with normalized encoding")
        encode_cmd = [
            "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat_path),
            "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
            "-r", "30", "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
            "-movflags", "+faststart", str(output_path),
        ]
        result = subprocess.run(encode_cmd, capture_output=True, text=True, timeout=3600)
    if result.returncode != 0 or not output_path.exists():
        raise RuntimeError(f"Unable to assemble campaign: {result.stderr[-2000:]}")


def _assemble_library(db, campaign: dict, campaign_id: str) -> str | None:
    segments = list(db.ai_idol_segments.find({"campaign_id": campaign_id, "status": "READY"}).sort("position", 1))
    ready_products = {str(s.get("product_id")) for s in segments if s.get("kind") == "product"}
    product_order = [str(item["product_id"]) for item in (campaign.get("show_plan") or {}).get("products", [])
                     if str(item["product_id"]) in ready_products]
    paths: dict[str, str] = {}
    clips: list[ClipInfo] = []
    for segment in segments:
        path = str(segment.get("video_path") or "")
        if not path or not os.path.isfile(path):
            raise FileNotFoundError(f"Thiếu file video của clip {segment.get('kind')}")
        duration = segment.get("duration_seconds")
        if not duration:
            duration = probe_duration(path)
            db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {"duration_seconds": duration}})
        sid = str(segment["_id"])
        paths[sid] = path
        clips.append(ClipInfo(
            segment_id=sid, kind=segment["kind"], duration=float(duration),
            product_id=str(segment["product_id"]) if segment.get("product_id") else None,
            angle=segment.get("angle"), interlude_type=segment.get("interlude_type"),
            variant=int(segment.get("variant") or 0),
        ))
    target = int(campaign.get("duration_minutes") or 30) * 60
    try:
        program = build_program(clips, float(target), product_order)
    except ValueError as exc:
        _needs_attention(db, campaign_id, f"Không dựng được lịch phát buổi live: {exc}")
        return None

    estimate = sum(_file_size(paths[item.segment_id]) for item in program)
    if _free_bytes(PROGRAM_DIR) < 2 * estimate:
        _needs_attention(db, campaign_id, "Không đủ dung lượng ổ đĩa để ghép buổi live")
        return None

    concat_path = TEMP_DIR / f"campaign_{campaign_id}.txt"
    concat_path.write_text(concat_lines(program, paths), encoding="utf-8")
    output_path = PROGRAM_DIR / f"campaign_{campaign_id}.mp4"
    _run_concat(concat_path, output_path)

    duration = probe_duration(str(output_path))
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "master_video_path": str(output_path),
        "duration_seconds": duration,
        "program": [{"segment_id": i.segment_id, "kind": i.kind, "product_id": i.product_id,
                     "angle": i.angle, "start": i.start, "end": i.end} for i in program],
        "status": "SCHEDULED",
        "error_message": None,
        "updated_at": utcnow(),
    }})
    logger.info("[PROGRAM] Library campaign %s assembled (%ss, %d clips)", campaign_id, duration, len(program))
    return str(output_path)


def assemble_campaign_program(campaign_id: str) -> str | None:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)}) or {}
    if campaign.get("show_format") == "clip_library_v1":
        return _assemble_library(db, campaign, campaign_id)
    segments = list(db.ai_idol_segments.find({"campaign_id": campaign_id, "status": "READY"}).sort("position", 1))
    if not segments:
        raise RuntimeError("Campaign has no ready video segments")
    paths = [str(segment.get("video_path") or "") for segment in segments]
    missing = [path for path in paths if not path or not os.path.isfile(path)]
    if missing:
        raise FileNotFoundError(f"Missing {len(missing)} segment file(s)")

    concat_path = TEMP_DIR / f"campaign_{campaign_id}.txt"
    concat_path.write_text("".join(_concat_line(path) for path in paths), encoding="utf-8")
    output_path = PROGRAM_DIR / f"campaign_{campaign_id}.mp4"
    copy_cmd = [
        "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat_path),
        "-c", "copy", "-movflags", "+faststart", str(output_path),
    ]
    result = subprocess.run(copy_cmd, capture_output=True, text=True, timeout=1800)
    if result.returncode != 0:
        logger.warning("[PROGRAM] Stream-copy concat failed; retrying with normalized encoding")
        encode_cmd = [
            "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat_path),
            "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
            "-r", "30", "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
            "-movflags", "+faststart", str(output_path),
        ]
        result = subprocess.run(encode_cmd, capture_output=True, text=True, timeout=3600)
    if result.returncode != 0 or not output_path.exists():
        raise RuntimeError(f"Unable to assemble campaign: {result.stderr[-2000:]}")

    duration = probe_duration(str(output_path))
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "master_video_path": str(output_path),
        "duration_seconds": duration,
        "status": "SCHEDULED",
        "error_message": None,
        "updated_at": utcnow(),
    }})
    logger.info("[PROGRAM] Campaign %s assembled (%ss)", campaign_id, duration)
    return str(output_path)

