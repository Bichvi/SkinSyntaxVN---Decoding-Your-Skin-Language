"""Native Syna renderer client. Never routes a cartoon through human-face models."""
from __future__ import annotations

import base64
import hashlib
import html
import io
import json
import os
from pathlib import Path
import re
import tempfile
import unicodedata
import wave

import requests

from config import BACKGROUNDS_DIR, OUTPUT_DIR, TEMP_DIR
from engines.audio_mixer import lead_in_seconds
from engines.body_clip import price_from_for
from engines.avatar_engine import _asset_path
from engines.program_composer import probe_duration
from engines.video_composer import build_subtitle_cues, download_product_image

RENDER_VERSION = "syna-native-v4"


class RendererUnavailable(RuntimeError):
    """The Syna renderer is down, unreachable or the wrong version.

    Raised only for health/connection failures. The pipeline parks the job as
    WAITING_RENDERER instead of failing it; real render errors stay RuntimeError.
    """


def ingredient_titles(value) -> list[str]:
    """Only source labels, not inferred benefits or assumed Centella ingredients."""
    if isinstance(value, list):
        values = [str(item) for item in value if isinstance(item, str)]
    else:
        text = html.unescape(re.sub(r"<[^>]+>", "\n", str(value or "")))
        text = text.replace("\\r\\n", "\n").replace("\\n", "\n")
        values = re.split(r"[\n•;]+", text)
        if len(values) == 1 and ":" not in text:
            values = text.split(",")
    titles = []
    for item in values:
        title = re.sub(r"^[-*\s]+", "", item).split(":", 1)[0].strip()
        if title and title.upper() not in {"UNKNOWN", "N/A", "NONE"} and title not in titles:
            titles.append(title[:160])
    return titles[:3]


def _normalized(value: str) -> str:
    return "".join(char for char in unicodedata.normalize("NFD", value.lower().replace("đ", "d"))
                   if unicodedata.category(char) != "Mn")


def padded_wav_bytes(path: str, intro: float, outro: float) -> bytes:
    """The voice WAV with silent lead-in/tail, so Syna can greet and wave goodbye."""
    with wave.open(path, "rb") as source:
        params = source.getparams()
        frames = source.readframes(params.nframes)
    frame_size = params.nchannels * params.sampwidth
    silence = lambda seconds: bytes(frame_size * int(round(seconds * params.framerate)))
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as target:
        target.setparams(params)
        target.writeframes(silence(intro) + frames + silence(outro))
    return buffer.getvalue()


def shift_timings(timings: list, seconds: float) -> list:
    return [dict(item, start=float(item.get("start", 0)) + seconds, end=float(item.get("end", 0)) + seconds)
            for item in timings]


def build_manifest(snapshot: dict, script: str, duration: float, timings: list, configuration: dict,
                   speech: tuple[float, float] | None = None, purpose: str = "live_only") -> dict:
    if configuration.get("format", "16:9") != "16:9":
        raise ValueError("Syna hiện hỗ trợ khung ngang 16:9. Hãy chọn lại khung hình.")
    if not 0 < duration <= 180:
        raise ValueError("Syna hỗ trợ tối đa 180 giây mỗi sản phẩm. Hãy rút gọn kịch bản.")
    if not timings:
        raise ValueError("Thiếu mốc thời gian giọng đọc. Hãy tạo lại giọng nói trước khi render Syna.")
    ingredients = ingredient_titles(snapshot.get("ingredients"))
    cues = []
    for start, end, text in build_subtitle_cues(script, duration, timings):
        normalized = _normalized(text)
        active = -1
        for index, title in enumerate(ingredients):
            # Strip leading concentration; keep the ingredient name, never a generated claim.
            name = re.sub(r"^[\d.,%\s]+", "", _normalized(title))
            keyword = name.split()[0] if name else ""
            if name and (name in normalized or (len(keyword) >= 5 and re.search(r"\b" + re.escape(keyword) + r"\b", normalized))):
                active = index
                break
        cues.append({"start": start, "end": min(duration, end), "text": text, "ingredient": active})
    price = snapshot.get("sale_price") or snapshot.get("price") or 0
    manifest = {"version": RENDER_VERSION, "format": "16:9", "duration": duration,
                "product": {"name": str(snapshot.get("name") or "Sản phẩm")[:500], "price": max(0, int(price)),
                            # Timings are already on the video timeline (lead-in included).
                            "price_from": price_from_for(purpose, timings, 0.0)},
                "ingredients": ingredients, "cues": cues}
    if speech:
        # Speech window inside the padded audio: Syna greets before it and waves goodbye after.
        manifest["speech"] = {"start": round(speech[0], 3), "end": round(min(duration, speech[1]), 3)}
    return manifest


def render_syna_video(job_id: str, audio_path: str, script: str, snapshot: dict, configuration: dict,
                      purpose: str = "live_only") -> str:
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,80}", job_id):
        raise ValueError("Invalid render job identifier")
    audio = Path(audio_path)
    if audio.stat().st_size > 24 * 1024 * 1024:
        raise ValueError("Âm thanh quá lớn cho Syna. Hãy rút gọn kịch bản.")
    speech_seconds = probe_duration(str(audio))
    with open(str(audio) + ".timings.json", encoding="utf-8") as handle:
        timings = json.load(handle)
    intro, outro = lead_in_seconds(job_id, configuration)
    duration = round(speech_seconds + intro + outro, 3)
    manifest = build_manifest(snapshot, script, duration, shift_timings(timings, intro), configuration,
                              speech=(intro, intro + speech_seconds) if intro or outro else None,
                              purpose=purpose)
    audio_bytes = audio.read_bytes()
    signature = hashlib.sha256(audio_bytes + json.dumps(
        [manifest, snapshot.get("image"), configuration.get("background_asset")],
        sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:20]
    output = Path(OUTPUT_DIR) / f"syna_{job_id}_{signature}.mp4"
    if output.is_file() and output.stat().st_size > 1024:
        return str(output)
    url = os.getenv("SYNA_RENDER_URL", "http://host.docker.internal:7862").rstrip("/")
    try:
        health = requests.get(url + "/health", timeout=5)
        health.raise_for_status()
        if health.json().get("renderer") != RENDER_VERSION:
            raise RendererUnavailable("Phiên bản renderer Syna không khớp. Khởi động lại dịch vụ Syna.")
    except (requests.RequestException, ValueError) as exc:
        raise RendererUnavailable("Syna chưa sẵn sàng. Chạy scripts/start-ai-idol-syna.ps1 trên máy này; video sẽ tự được tạo tiếp.") from exc
    render_audio = padded_wav_bytes(str(audio), intro, outro) if intro or outro else audio_bytes
    payload = {"manifest": manifest, "audio": base64.b64encode(render_audio).decode("ascii")}
    background = _asset_path(BACKGROUNDS_DIR, configuration.get("background_asset", ""))
    if configuration.get("background_asset") and not background:
        raise ValueError("Không tìm thấy phông nền đã chọn.")
    if background:
        if Path(background).stat().st_size > 10 * 1024 * 1024:
            raise ValueError("Ảnh nền tối đa 10 MB.")
        payload["background"] = base64.b64encode(Path(background).read_bytes()).decode("ascii")
    with tempfile.TemporaryDirectory(prefix="syna-", dir=TEMP_DIR) as folder:
        image = download_product_image(snapshot.get("image", ""), str(Path(folder) / "product"))
        if image:
            payload["product_image"] = base64.b64encode(Path(image).read_bytes()).decode("ascii")
        try:
            with requests.post(url + "/render", json=payload, timeout=(10, 900), stream=True) as response:
                if response.status_code != 200:
                    try:
                        detail = str(response.json().get("error", ""))[:500]
                    except ValueError:
                        detail = "Phản hồi renderer không hợp lệ"
                    raise RuntimeError(f"Syna render HTTP {response.status_code}: {detail}")
                if not response.headers.get("Content-Type", "").startswith("video/mp4"):
                    raise RuntimeError("Renderer Syna không trả về MP4.")
                partial = output.with_suffix(".partial.mp4")
                try:
                    size = 0
                    with partial.open("wb") as handle:
                        for chunk in response.iter_content(256 * 1024):
                            size += len(chunk)
                            if size > 128 * 1024 * 1024:
                                raise RuntimeError("Video Syna vượt giới hạn 128 MB.")
                            handle.write(chunk)
                    if size < 1024 or abs(probe_duration(str(partial)) - duration) > .2:
                        raise RuntimeError("Video Syna chưa hoàn chỉnh hoặc sai thời lượng.")
                    partial.replace(output)
                finally:
                    partial.unlink(missing_ok=True)
        except requests.RequestException as exc:
            raise RendererUnavailable("Mất kết nối khi tạo Syna. Kiểm tra renderer local; video sẽ tự được tạo tiếp.") from exc
    return str(output)
