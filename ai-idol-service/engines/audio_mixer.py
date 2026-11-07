"""Final audio mix: loudness-normalized voice over a ducked background music bed.

The mix runs after rendering so lip-sync and subtitle timing are computed from
the clean voice track. Voice is always taken from the lossless TTS WAV, never
from the rendered video, so re-running the mix on the same raw render is safe.
Any failure keeps the original render: music is an enhancement, not a gate.
"""
from __future__ import annotations

import hashlib
import os
import random
import subprocess
import tempfile
from pathlib import Path

from config import (
    AUDIO_MIX_ENABLED,
    AUDIO_TARGET_LUFS,
    MUSIC_DIR,
    MUSIC_INTRO_SECONDS,
    MUSIC_OUTRO_SECONDS,
    MUSIC_BED_LUFS,
    MUSIC_DUCK_RATIO,
    TEMP_DIR,
    logger,
)
from engines.title_cards import build_card_graph, card_inputs, prepare_cards

MUSIC_EXTENSIONS = {".mp3", ".wav", ".m4a", ".aac", ".ogg", ".flac"}
SAMPLE_FORMAT = "aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo"


def _probe_duration(path: str) -> float:
    result = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", path],
        capture_output=True, text=True, timeout=30, check=True,
    )
    return float(result.stdout.strip())


def _enabled(value) -> bool:
    return str(value).strip().lower() not in {"0", "false", "no", "off"}


def list_music_tracks(music_dir=MUSIC_DIR) -> list[str]:
    folder = Path(music_dir)
    if not folder.is_dir():
        return []
    return sorted(
        str(path) for path in folder.iterdir()
        if path.is_file() and path.suffix.lower() in MUSIC_EXTENSIONS
    )


def pick_music_track(job_id: str, config: dict, music_dir=MUSIC_DIR) -> str:
    """Return the requested track, or a stable per-job pick so retries sound the same."""
    if not _enabled(config.get("music_enabled", True)):
        return ""
    tracks = list_music_tracks(music_dir)
    if not tracks:
        return ""
    requested = str(config.get("music_track") or "")
    if requested:
        safe_name = os.path.basename(requested)
        match = next((track for track in tracks if os.path.basename(track) == safe_name), "")
        if safe_name == requested and match:
            return match
        logger.warning("[AUDIO-MIX] Requested music track %r not found; using default pick", requested)
    index = int(hashlib.sha256(job_id.encode("utf-8")).hexdigest(), 16) % len(tracks)
    return tracks[index]


def plan_mix_timing(job_id: str, music_seconds: float = 0.0,
                    intro_range=MUSIC_INTRO_SECONDS, outro_range=MUSIC_OUTRO_SECONDS) -> dict:
    """Vary lead-in, tail and music entry point per job so videos do not all open alike.

    Seeded by job_id: a retried job gets the same timing as its first attempt.
    """
    rng = random.Random(hashlib.sha256(("timing:" + job_id).encode("utf-8")).hexdigest())
    intro = round(rng.uniform(*intro_range), 1)
    outro = round(rng.uniform(*outro_range), 1)
    # Skip into the track sometimes so the same song does not always start on its
    # quiet first bars; keep at least 30 s of track after the jump.
    offset = 0.0
    if music_seconds > 45 and rng.random() < 0.6:
        offset = round(rng.uniform(0, min(60.0, music_seconds - 30)), 1)
    return {"intro": intro, "outro": outro, "music_offset": offset}


def lead_in_seconds(job_id: str, config: dict) -> tuple[float, float]:
    """Lead-in/tail for renderers that animate the pause themselves (Syna).

    Same values `mix_program_audio` plans for this job, so the rendered video and
    the music mix agree. Zero when the mix feature is switched off.
    """
    if not AUDIO_MIX_ENABLED or not _enabled(config.get("audio_mix_enabled", True)):
        return 0.0, 0.0
    timing = plan_mix_timing(job_id)
    return timing["intro"], timing["outro"]


def voice_chain(target_lufs: float = AUDIO_TARGET_LUFS) -> str:
    # Gentle compression evens out TTS sentence levels before loudness targeting.
    return (
        f"{SAMPLE_FORMAT},"
        "acompressor=threshold=0.125:ratio=2.5:attack=10:release=200,"
        f"loudnorm=I={target_lufs}:TP=-1.5:LRA=11,"
        f"aresample=44100,{SAMPLE_FORMAT}"
    )


def build_filter_graph(voice_seconds: float, intro: float, outro: float,
                       music_offset: float = 0.0,
                       music_lufs: float = MUSIC_BED_LUFS,
                       target_lufs: float = AUDIO_TARGET_LUFS,
                       video_graph: str = "", pad_video: bool = True,
                       duck_ratio: float = MUSIC_DUCK_RATIO) -> str:
    """Graph for inputs 0=video, 1=voice WAV, 2=looped music. Outputs [aout], plus [vout]
    when the video is padded here.

    `video_graph` (title cards) replaces the default frozen-frame padding.
    """
    total = voice_seconds + intro + outro
    fade_in = min(1.0, max(0.3, intro))
    # Let the music swell back up after the last word before it fades out.
    fade_out = min(2.5, max(0.5, outro * 0.7))
    parts = [
        f"[1:a]{voice_chain(target_lufs)},adelay=delays={int(round(intro * 1000))}:all=1,"
        f"apad=whole_dur={total:.3f},asplit=2[voice][key]",
        # Library tracks are mastered anywhere from -8 to -15 LUFS; normalize so every
        # track sits at the same bed level before ducking.
        f"[2:a]{SAMPLE_FORMAT},atrim={music_offset:.3f}:{music_offset + total:.3f},asetpts=PTS-STARTPTS,"
        f"loudnorm=I={music_lufs}:TP=-3:LRA=11,aresample=44100,{SAMPLE_FORMAT},"
        f"afade=t=in:st=0:d={fade_in:.2f},afade=t=out:st={max(0.0, total - fade_out):.3f}:d={fade_out:.2f}[bed]",
        # The voice keys the compressor, so music dips while Syna speaks and swells in pauses.
        f"[bed][key]sidechaincompress=threshold=0.04:ratio={duck_ratio:g}:attack=20:release=400[ducked]",
        "[voice][ducked]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,"
        "alimiter=limit=0.89:level=false[aout]",
    ]
    if video_graph:
        parts.append(video_graph)
    elif pad_video and (intro > 0 or outro > 0):
        parts.append(
            f"[0:v]tpad=start_duration={intro:.3f}:stop_duration={outro:.3f}"
            ":start_mode=clone:stop_mode=clone[vout]"
        )
    # Otherwise the video is stream-copied: an unconnected [vout] would make FFmpeg fail.
    return ";".join(parts)


def build_mix_command(video_path: str, voice_path: str, music_path: str, output_path: str,
                      voice_seconds: float, intro: float = 0.0, outro: float = 0.0,
                      music_offset: float = 0.0, cards: dict | None = None,
                      main_seconds: float = 0.0, video_padded: bool = False) -> list[str]:
    """`video_padded`: the render already contains the lead-in/tail (Syna animates it),
    so only the voice is shifted and the video stream is copied untouched."""
    intro = max(0.0, intro)
    outro = max(0.0, outro)
    total = voice_seconds + intro + outro
    if not music_path:
        # Voice-only mastering: video stream is untouched, so no re-encode is needed.
        shift = (f",adelay=delays={int(round(intro * 1000))}:all=1,apad=whole_dur={total:.3f}"
                 if video_padded and (intro > 0 or outro > 0) else "")
        return [
            "ffmpeg", "-y", "-i", video_path, "-i", voice_path,
            "-filter_complex", f"[1:a]{voice_chain()}{shift},alimiter=limit=0.89:level=false[aout]",
            "-map", "0:v:0", "-map", "[aout]",
            "-c:v", "copy", "-c:a", "aac", "-b:a", "192k", "-ar", "44100",
            *(["-t", f"{total:.3f}"] if shift else ["-shortest"]),
            "-movflags", "+faststart", output_path,
        ]
    padded = (intro > 0 or outro > 0) and not video_padded
    video_codec = (
        ["-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p"]
        if padded else ["-c:v", "copy"]
    )
    use_cards = bool(cards) and padded
    video_graph = build_card_graph(cards, intro, outro, main_seconds or voice_seconds) if use_cards else ""
    return [
        "ffmpeg", "-y", "-i", video_path, "-i", voice_path,
        "-stream_loop", "-1", "-i", music_path,
        *(card_inputs(cards, intro, outro) if use_cards else []),
        "-filter_complex", build_filter_graph(voice_seconds, intro, outro, max(0.0, music_offset),
                                              video_graph=video_graph, pad_video=padded),
        "-map", "[vout]" if padded else "0:v:0", "-map", "[aout]",
        *video_codec, "-c:a", "aac", "-b:a", "192k", "-ar", "44100",
        "-t", f"{total:.3f}", "-movflags", "+faststart", output_path,
    ]


def mix_program_audio(job_id: str, video_path: str, voice_path: str, config: dict,
                      snapshot: dict | None = None, video_padded: bool = False,
                      applied: dict | None = None) -> str:
    """Return a new MP4 with mastered audio, or the original path if mixing is skipped/fails.

    `video_padded=True` for renders that already include the lead-in/tail from
    `lead_in_seconds` (Syna waves hello/goodbye there); they get no title cards.
    `applied`, when given, receives {"intro": seconds} actually placed before the first
    word in the returned file, so callers can locate script sections on its timeline.
    """
    if not AUDIO_MIX_ENABLED or not _enabled(config.get("audio_mix_enabled", True)):
        return video_path
    if not (video_path and os.path.isfile(video_path) and voice_path and os.path.isfile(voice_path)):
        logger.warning("[AUDIO-MIX] Missing render or voice file for job %s; keeping original audio", job_id)
        return video_path

    music_path = pick_music_track(job_id, config)
    stem, _ = os.path.splitext(video_path)
    output_path = stem + ".mix.mp4"
    partial_path = stem + ".mix.partial.mp4"
    try:
        voice_seconds = _probe_duration(voice_path)
        main_seconds = _probe_duration(video_path)
        timing = plan_mix_timing(job_id, _probe_duration(music_path)) if music_path else {}
        if video_padded:
            intro, outro = lead_in_seconds(job_id, config)
            if abs(main_seconds - (voice_seconds + intro + outro)) > 0.3:
                # e.g. a render cached before lead-ins existed: mix it as-is.
                intro = outro = 0.0
            timing.update(intro=intro, outro=outro)
        with tempfile.TemporaryDirectory(prefix="cards-", dir=TEMP_DIR) as work_dir:
            use_cards = bool(music_path) and not video_padded
            cards = prepare_cards(video_path, snapshot or {}, work_dir) if use_cards else None
            cmd = build_mix_command(video_path, voice_path, music_path, partial_path, voice_seconds,
                                    cards=cards, main_seconds=main_seconds, video_padded=video_padded,
                                    **timing)
            logger.info("[AUDIO-MIX] Mixing job %s with %s %s, title cards: %s", job_id,
                        os.path.basename(music_path) if music_path else "voice only", timing, bool(cards))
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
            if cards and result.returncode != 0:
                # Cards are decoration: retry with the plain frozen-frame lead-in.
                logger.warning("[AUDIO-MIX] Title cards failed for job %s: %s", job_id, result.stderr[-800:])
                cmd = build_mix_command(video_path, voice_path, music_path, partial_path, voice_seconds,
                                        video_padded=video_padded, **timing)
                result = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
        if result.returncode != 0 or not os.path.isfile(partial_path) or os.path.getsize(partial_path) < 1024:
            raise RuntimeError(result.stderr[-1500:])
        os.replace(partial_path, output_path)
        if applied is not None:
            applied["intro"] = float(timing.get("intro", 0.0))
        return output_path
    except Exception as exc:
        logger.warning("[AUDIO-MIX] Mix failed for job %s; keeping original audio: %s", job_id, exc)
        return video_path
    finally:
        if os.path.exists(partial_path):
            os.remove(partial_path)
