"""Product-page clip for a rendered product segment.

A livestream segment talks money from its [Price] section onward. The product
page keeps only what comes before, so one render serves both destinations.
"""
from __future__ import annotations

import json
import os
import subprocess

from config import logger
from core.purpose import LIVE_AND_PRODUCT, LIVE_ONLY, PRODUCT_VIDEO
from core.script_text import SALE_LABELS


def body_cut_seconds(timings: list[dict], intro: float) -> float | None:
    """Video time where the first sale section starts, or None when the script has none."""
    for item in timings:
        if item.get("label") in SALE_LABELS:
            return round(float(intro) + float(item.get("start", 0.0)), 3)
    return None


def price_from_for(purpose: str, timings: list[dict], intro: float) -> float | None:
    """When the on-screen price may appear: never for product videos, at the sale cut for both-mode."""
    if purpose == PRODUCT_VIDEO:
        return None
    if purpose == LIVE_AND_PRODUCT:
        cut = body_cut_seconds(timings, intro)
        # Without a marker the livestream keeps its price board; the clip is blocked elsewhere.
        return 0.0 if cut is None else cut
    return 0.0


def cut_body_clip(src: str, dst: str, cut_seconds: float, fade: float = 0.6) -> str:
    fade_start = max(0.0, cut_seconds - fade)
    command = [
        "ffmpeg", "-y", "-i", src, "-to", f"{cut_seconds:.3f}",
        "-vf", f"fade=t=out:st={fade_start:.3f}:d={fade:g}",
        "-af", f"afade=t=out:st={fade_start:.3f}:d={fade:g}",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "192k", "-movflags", "+faststart", dst,
    ]
    result = subprocess.run(command, capture_output=True, text=True, timeout=900)
    if result.returncode != 0 or os.path.getsize(dst) < 1024:
        raise RuntimeError(result.stderr[-1500:])
    return dst


def product_page_clip(job_id: str, purpose: str, video_path: str, audio_path: str,
                      *, intro: float) -> tuple[str | None, str | None]:
    """(clip path for the product page, reason it was withheld). Never fails the job.

    `intro` is the lead-in actually present before the first word of `video_path`.
    """
    if purpose == LIVE_ONLY:
        return None, None
    if purpose == PRODUCT_VIDEO:
        return video_path, None
    try:
        with open(audio_path + ".timings.json", encoding="utf-8") as handle:
            timings = json.load(handle)
    except (OSError, ValueError):
        return None, "missing_price_marker"
    cut = body_cut_seconds(timings, intro)
    if cut is None:
        return None, "missing_price_marker"
    stem, _ = os.path.splitext(video_path)
    try:
        return cut_body_clip(video_path, stem + ".body.mp4", cut), None
    except Exception as exc:
        logger.warning("[BODY-CLIP] Unable to cut product-page clip for job %s: %s", job_id, exc)
        return None, "body_cut_failed"
