from __future__ import annotations

import os
import subprocess
from pathlib import Path

from config import PROGRAM_DIR, TEMP_DIR, logger
from core.state_machine import get_db, object_id, utcnow


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


def assemble_campaign_program(campaign_id: str) -> str:
    db = get_db()
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

