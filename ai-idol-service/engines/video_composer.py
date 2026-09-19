import json
import os
import re
import subprocess
import requests
from config import OUTPUT_DIR, logger
from core.script_text import clean_script_for_speech

def get_audio_duration(audio_path: str) -> float:
    """Get precise audio duration in seconds using ffprobe."""
    cmd = [
        "ffprobe", "-v", "quiet",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        audio_path
    ]
    try:
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=True)
        duration = float(res.stdout.strip())
        logger.info(f"[VIDEO-COMPOSER] Audio duration resolved: {duration}s")
        return duration
    except Exception as e:
        logger.warning(f"[VIDEO-COMPOSER] Failed to get audio duration with ffprobe: {e}. Defaulting to 30.0s")
        return 30.0

def format_srt_time(seconds: float) -> str:
    """Format seconds into SRT timestamp format: HH:MM:SS,mmm."""
    hrs = int(seconds // 3600)
    mins = int((seconds % 3600) // 60)
    secs = int(seconds % 60)
    millis = int((seconds % 1) * 1000)
    return f"{hrs:02d}:{mins:02d}:{secs:02d},{millis:03d}"

def _caption_chunks(text: str, max_chars: int = 76) -> list[str]:
    sentences = re.split(r"(?<=[.!?])\s+", clean_script_for_speech(text))
    chunks = []
    for sentence in (item.strip() for item in sentences if item.strip()):
        words = sentence.split()
        current = []
        for word in words:
            candidate = " ".join([*current, word])
            if current and len(candidate) > max_chars:
                chunks.append(" ".join(current))
                current = [word]
            else:
                current.append(word)
        if current:
            chunks.append(" ".join(current))
    return chunks


def build_subtitle_cues(script_text: str, duration: float, timings: list[dict] | None = None):
    sections = timings or [{"start": 0.0, "end": duration, "text": clean_script_for_speech(script_text)}]
    cues = []
    for section in sections:
        start = max(0.0, float(section.get("start", 0.0)))
        end = min(duration, max(start, float(section.get("end", duration))))
        chunks = _caption_chunks(str(section.get("text", "")))
        if not chunks or end <= start:
            continue
        weights = [max(1, len(re.sub(r"\s+", "", chunk))) for chunk in chunks]
        total_weight = sum(weights)
        cursor = start
        for index, (chunk, weight) in enumerate(zip(chunks, weights)):
            cue_end = end if index == len(chunks) - 1 else cursor + ((end - start) * weight / total_weight)
            cues.append((cursor, max(cursor + 0.05, cue_end), chunk))
            cursor = cue_end
    return cues


def generate_srt_file(script_text: str, duration: float, srt_path: str, audio_path: str = "") -> None:
    """Write subtitles from measured per-section TTS durations without cumulative drift."""
    timings = None
    timing_path = audio_path + ".timings.json" if audio_path else ""
    if timing_path and os.path.isfile(timing_path):
        try:
            with open(timing_path, "r", encoding="utf-8") as handle:
                timings = json.load(handle)
        except (OSError, ValueError, TypeError) as exc:
            logger.warning("[VIDEO-COMPOSER] Unable to read speech timings: %s", exc)
    cues = build_subtitle_cues(script_text, duration, timings)

    with open(srt_path, "w", encoding="utf-8") as f:
        for idx, (start_time, end_time, sentence) in enumerate(cues):
            start_str = format_srt_time(start_time)
            end_str = format_srt_time(min(duration, end_time))
            f.write(f"{idx + 1}\n")
            f.write(f"{start_str} --> {end_str}\n")
            f.write(f"{sentence}\n\n")

    logger.info(f"[VIDEO-COMPOSER] SRT file written: {srt_path}")

def detect_image_extension(content: bytes) -> str:
    """Detect formats FFmpeg can decode; never trust a URL suffix or Content-Type."""
    if content.startswith(b"\x89PNG\r\n\x1a\n"):
        return ".png"
    if content.startswith(b"\xff\xd8\xff"):
        return ".jpg"
    if content.startswith((b"GIF87a", b"GIF89a")):
        return ".gif"
    if len(content) >= 12 and content[:4] == b"RIFF" and content[8:12] == b"WEBP":
        return ".webp"
    return ""


def detect_avatar_extension(content: bytes) -> str:
    """Accept verified portrait images plus MP4/WebM motion templates."""
    image_extension = detect_image_extension(content)
    if image_extension:
        return image_extension
    # MP4/MOV are ISO base-media containers and identify themselves with an
    # ftyp box at byte 4. Store the uploaded container under a safe .mp4 name;
    # FFmpeg performs the actual stream validation before rendering.
    if len(content) >= 12 and content[4:8] == b"ftyp":
        return ".mp4"
    if content.startswith(b"\x1a\x45\xdf\xa3"):
        return ".webm"
    return ""


def download_product_image(image_url: str, target_stem: str) -> str:
    """Download the first valid product image and return its real local path."""
    candidates = [item.strip() for item in str(image_url or "").split("|") if item.strip()]
    for candidate in candidates[:5]:
        if not candidate.startswith(("http://", "https://")):
            continue
        try:
            logger.info("[VIDEO-COMPOSER] Downloading product image from %s...", candidate)
            res = requests.get(candidate, timeout=10)
            if res.status_code != 200:
                continue
            content = res.content
            extension = detect_image_extension(content)
            if not extension:
                logger.warning(
                    "[VIDEO-COMPOSER] Product image response is not a supported image (%s bytes)",
                    len(content),
                )
                continue
            if len(content) > 15 * 1024 * 1024:
                logger.warning("[VIDEO-COMPOSER] Product image is larger than 15 MB; skipping")
                continue
            target_path = target_stem + extension
            with open(target_path, "wb") as f:
                f.write(content)
            logger.info("[VIDEO-COMPOSER] Valid image saved to %s", target_path)
            return target_path
        except Exception as e:
            logger.warning("[VIDEO-COMPOSER] Image download failed: %s", e)
    return ""

def compose_final_video(job_id: str, avatar_video_path: str, audio_path: str, script_text: str, snapshot: dict, config: dict) -> str:
    """Compose avatar video, overlay product image, insert styling subtitles via FFmpeg."""
    logger.info(f"[VIDEO-COMPOSER] Starting final video composition for job {job_id}...")
    
    duration = get_audio_duration(audio_path)
    srt_path = os.path.join(OUTPUT_DIR, f"sub_{job_id}.srt")
    generate_srt_file(script_text, duration, srt_path, audio_path)

    # Format relative path to satisfy FFmpeg filter parser syntax across platforms
    srt_rel = os.path.relpath(srt_path).replace("\\", "/")
    
    output_filename = f"final_{job_id}.mp4"
    output_path = os.path.join(OUTPUT_DIR, output_filename)
    
    image_url = snapshot.get("image", "")
    image_target_stem = os.path.join(OUTPUT_DIR, f"prod_{job_id}")
    local_image_path = download_product_image(image_url, image_target_stem)
    
    # Scale product image to 180x180 and overlay at coordinate (x=40, y=100)
    # Render subtitles overlay centered near bottom of frame
    if local_image_path:
        filter_str = (
            f"[1:v]scale=180:180[img];"
            f"[0:v][img]overlay=40:100[bg];"
            f"[bg]subtitles='{srt_rel}':force_style='FontSize=18,PrimaryColour=&H00FFFF,OutlineColour=&H000000,BorderStyle=1,Outline=2'"
        )
        cmd = [
            "ffmpeg", "-y",
            "-i", avatar_video_path,
            "-i", local_image_path,
            "-filter_complex", filter_str,
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-c:a", "copy",
            output_path
        ]
    else:
        filter_str = f"subtitles='{srt_rel}':force_style='FontSize=18,PrimaryColour=&H00FFFF,OutlineColour=&H000000,BorderStyle=1,Outline=2'"
        cmd = [
            "ffmpeg", "-y",
            "-i", avatar_video_path,
            "-vf", filter_str,
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-c:a", "copy",
            output_path
        ]
        
    logger.info(f"[VIDEO-COMPOSER] Running FFmpeg render command: {' '.join(cmd)}")
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    
    if local_image_path and os.path.exists(local_image_path):
        try:
            os.remove(local_image_path)
        except Exception:
            pass

    if res.returncode != 0:
        logger.error(f"[VIDEO-COMPOSER] FFmpeg render failed: {res.stderr}")
        raise RuntimeError(f"FFmpeg render failed: {res.stderr}")
        
    logger.info(f"[VIDEO-COMPOSER] Render completed successfully! Saved to {output_path}")
    return output_path
