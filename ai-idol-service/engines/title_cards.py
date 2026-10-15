"""Intro/outro title cards shown during the music-only lead-in and tail.

Without cards the video would freeze on its first/last frame while only music
plays. Each card is built from a blurred frame of the render (so the colours
match the scene), the product photo and text, then cross-faded into the main
video. All drawing happens in FFmpeg; text goes through textfile= so product
names never need filter escaping.
"""
from __future__ import annotations

import os
import subprocess

from config import CARD_FONT_BOLD, CARD_FONT_REGULAR, TITLE_CARDS_ENABLED, logger
from engines.video_composer import download_product_image

CROSSFADE = 0.4
FPS = 30
CTA_TEXT = "Đặt hàng ngay"
# Formats the card inputs can loop as still images (GIF uses a different demuxer).
STILL_EXTENSIONS = {".png", ".jpg", ".jpeg", ".webp"}


def format_price(value) -> str:
    try:
        amount = int(float(value or 0))
    except (TypeError, ValueError):
        return ""
    return f"{amount:,}".replace(",", ".") + "đ" if amount > 0 else ""


def wrap_text(text: str, max_chars: int, max_lines: int = 2) -> str:
    words = " ".join(str(text or "").split()).split(" ")
    lines, current = [], ""
    for word in words:
        candidate = f"{current} {word}".strip()
        if current and len(candidate) > max_chars:
            lines.append(current)
            current = word
        else:
            current = candidate
    if current:
        lines.append(current)
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        lines[-1] = lines[-1][: max(1, max_chars - 1)].rstrip() + "…"
    return "\n".join(lines)


def _probe_size(path: str) -> tuple[int, int]:
    result = subprocess.run(
        ["ffprobe", "-v", "error", "-select_streams", "v:0",
         "-show_entries", "stream=width,height", "-of", "csv=p=0:s=x", path],
        capture_output=True, text=True, timeout=30, check=True,
    )
    width, height = result.stdout.strip().split("x")[:2]
    return int(width), int(height)


def _grab_frame(video_path: str, target: str, from_end: bool) -> None:
    seek = ["-sseof", "-0.3"] if from_end else []
    subprocess.run(
        ["ffmpeg", "-y", "-v", "error", *seek, "-i", video_path, "-frames:v", "1", "-update", "1", target],
        capture_output=True, text=True, timeout=60, check=True,
    )
    if not os.path.isfile(target):
        raise RuntimeError("frame extraction produced no image")


def _write_text(path: str, text: str) -> str:
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    return path


def prepare_cards(video_path: str, snapshot: dict, work_dir: str) -> dict | None:
    """Collect card assets, or None to fall back to frozen-frame padding."""
    if not TITLE_CARDS_ENABLED:
        return None
    if not (os.path.isfile(CARD_FONT_BOLD) and os.path.isfile(CARD_FONT_REGULAR)):
        logger.warning("[TITLE-CARDS] Fonts not found; using frozen-frame lead-in")
        return None
    try:
        width, height = _probe_size(video_path)
        first = os.path.join(work_dir, "card_first.png")
        last = os.path.join(work_dir, "card_last.png")
        _grab_frame(video_path, first, from_end=False)
        _grab_frame(video_path, last, from_end=True)
    except Exception as exc:
        logger.warning("[TITLE-CARDS] Unable to prepare card backgrounds: %s", exc)
        return None

    product = download_product_image(snapshot.get("image", ""), os.path.join(work_dir, "card_product"))
    if product and os.path.splitext(product)[1].lower() not in STILL_EXTENSIONS:
        product = ""
    base = min(width, height)
    name_size = max(24, int(base * 0.06))
    name = str(snapshot.get("name") or "").strip() or "Sản phẩm hôm nay"
    max_chars = max(12, int(width * 0.84 / (name_size * 0.56)))
    price = format_price(snapshot.get("sale_price") or snapshot.get("price"))
    return {
        "width": width,
        "height": height,
        "first": first,
        "last": last,
        "product": product,
        "name": _write_text(os.path.join(work_dir, "card_name.txt"), wrap_text(name, max_chars)),
        "price": _write_text(os.path.join(work_dir, "card_price.txt"), price) if price else "",
        "cta": _write_text(os.path.join(work_dir, "card_cta.txt"), CTA_TEXT),
    }


def card_inputs(cards: dict, intro: float, outro: float) -> list[str]:
    """FFmpeg inputs appended after video/voice/music: 3=intro bg, 4=outro bg, 5=product."""
    args = [
        "-loop", "1", "-framerate", str(FPS), "-t", f"{intro + CROSSFADE:.3f}", "-i", cards["first"],
        "-loop", "1", "-framerate", str(FPS), "-t", f"{outro + CROSSFADE:.3f}", "-i", cards["last"],
    ]
    if cards.get("product"):
        longest = max(intro, outro) + CROSSFADE
        args += ["-loop", "1", "-framerate", str(FPS), "-t", f"{longest:.3f}", "-i", cards["product"]]
    return args


def _fade_in(start: float, duration: float = 0.5) -> str:
    return f"alpha='if(lt(t,{start}),0,min(1,(t-{start})/{duration}))'"


def _text(path: str, font: str, size: int, color: str, y: float, alpha: str,
          extra: str = "") -> str:
    return (
        f"drawtext=fontfile={font}:textfile={path}:expansion=none:fontsize={size}:fontcolor={color}"
        f":text_align=C:line_spacing={int(size * 0.25)}:x=(w-text_w)/2:y={int(y)}"
        f":borderw=2:bordercolor=black@0.45:{alpha}{extra}"
    )


def build_card_graph(cards: dict, intro: float, outro: float, main_seconds: float) -> str:
    """Video graph producing [vout]: intro card -> main render -> outro card, cross-faded."""
    width, height = cards["width"], cards["height"]
    base = min(width, height)
    common = f"fps={FPS},format=yuv420p,setsar=1,settb=AVTB"
    background = (
        f"scale={width}:{height}:force_original_aspect_ratio=increase,crop={width}:{height},"
        "gblur=sigma=28,eq=brightness=-0.14:saturation=1.15"
    )
    parts = [
        f"[0:v]scale={width}:{height},{common}[main]",
        f"[3:v]{background}[ibg]",
        f"[4:v]{background}[obg]",
    ]
    intro_label, outro_label = "ibg", "obg"
    if cards.get("product"):
        intro_box, outro_box = int(min(width * 0.8, height * 0.5)), int(min(width * 0.7, height * 0.38))
        parts += [
            "[5:v]format=rgba,split=2[pi][po]",
            f"[pi]scale={intro_box}:{intro_box}:force_original_aspect_ratio=decrease,"
            "fade=t=in:st=0:d=0.6:alpha=1[pif]",
            f"[po]scale={outro_box}:{outro_box}:force_original_aspect_ratio=decrease,"
            "fade=t=in:st=0.1:d=0.6:alpha=1[pof]",
            # Product rises 40 px into place while fading in.
            f"[ibg][pif]overlay=x=(W-w)/2:y={int(height * 0.42)}-h/2+max(0\\,40*(1-t/0.6)):shortest=1[iprod]",
            f"[obg][pof]overlay=x=(W-w)/2:y={int(height * 0.34)}-h/2+max(0\\,40*(1-t/0.6)):shortest=1[oprod]",
        ]
        intro_label, outro_label = "iprod", "oprod"
        name_y_intro, name_y_outro = height * 0.73, height * 0.57
    else:
        name_y_intro, name_y_outro = height * 0.42, height * 0.34

    name_size = max(24, int(base * 0.06))
    outro_name_size = max(20, int(base * 0.048))
    parts.append(
        f"[{intro_label}]"
        + _text(cards["name"], CARD_FONT_BOLD, name_size, "white", name_y_intro, _fade_in(0.35))
        + f",{common}[icard]"
    )
    outro_filters = [_text(cards["name"], CARD_FONT_REGULAR, outro_name_size, "white",
                           name_y_outro, _fade_in(0.3))]
    if cards.get("price"):
        outro_filters.append(_text(cards["price"], CARD_FONT_BOLD, max(28, int(base * 0.085)),
                                   "0xFFD54A", height * 0.68, _fade_in(0.5)))
    cta_size = max(22, int(base * 0.05))
    outro_filters.append(_text(cards["cta"], CARD_FONT_BOLD, cta_size, "white", height * 0.82,
                               _fade_in(0.8), f":box=1:boxcolor=0xE91E63@0.92:boxborderw={int(cta_size * 0.55)}"))
    parts.append(f"[{outro_label}]" + ",".join(outro_filters) + f",{common}[ocard]")

    # Main video starts exactly at `intro` so the first word stays in sync with the audio.
    outro_offset = max(intro + CROSSFADE, intro + main_seconds - CROSSFADE)
    parts += [
        f"[icard][main]xfade=transition=fade:duration={CROSSFADE}:offset={intro:.3f}[im]",
        f"[im][ocard]xfade=transition=fade:duration={CROSSFADE}:offset={outro_offset:.3f}[vout]",
    ]
    return ";".join(parts)
