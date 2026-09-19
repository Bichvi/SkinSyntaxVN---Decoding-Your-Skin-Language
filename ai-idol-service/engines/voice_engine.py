import json
import os
import subprocess
from providers.tts_provider import TTSProvider
from config import AUDIO_DIR, logger
from core.script_text import split_speech_sections


def _audio_duration(path: str) -> float:
    result = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", path],
        capture_output=True, text=True, timeout=30, check=True,
    )
    return float(result.stdout.strip())


def _concat_line(path: str) -> str:
    normalized = os.path.abspath(path).replace("\\", "/").replace("'", "'\\''")
    return f"file '{normalized}'\n"

def generate_voice_audio(job_id: str, script_text: str, config: dict) -> str:
    """Generate audio file from script text using the TTS provider."""
    logger.info(f"[VOICE-ENGINE] Generating voice audio for job {job_id}...")
    
    # Keep TTS lossless until the final video mux. MP3 here would be transcoded
    # to AAC later and can produce metallic hiss/rumble from double compression.
    output_filename = f"voice_{job_id}.wav"
    output_path = os.path.join(AUDIO_DIR, output_filename)
    
    tts = TTSProvider()
    sections = split_speech_sections(script_text)
    part_paths = []
    timings = []
    cursor = 0.0
    concat_path = os.path.join(AUDIO_DIR, f"voice_{job_id}.concat.txt")
    try:
        for index, section in enumerate(sections):
            part_path = os.path.join(AUDIO_DIR, f"voice_{job_id}.part{index:02d}.wav")
            if not tts.generate(section, part_path):
                raise RuntimeError(f"TTS failed for speech section {index + 1}")
            part_paths.append(part_path)
            part_duration = _audio_duration(part_path)
            timings.append({"start": cursor, "end": cursor + part_duration, "text": section})
            cursor += part_duration

        with open(concat_path, "w", encoding="utf-8") as handle:
            handle.write("".join(_concat_line(path) for path in part_paths))
        concat = subprocess.run(
            ["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", concat_path,
             "-c:a", "pcm_s16le", "-ar", "44100", "-ac", "1", output_path],
            capture_output=True, text=True, timeout=300,
        )
        if concat.returncode != 0:
            raise RuntimeError(concat.stderr[-1000:])
        with open(output_path + ".timings.json", "w", encoding="utf-8") as handle:
            json.dump(timings, handle, ensure_ascii=False)
        success = True
    finally:
        for path in part_paths:
            if os.path.exists(path):
                os.remove(path)
        if os.path.exists(concat_path):
            os.remove(concat_path)
    
    if not success or not os.path.exists(output_path):
        raise RuntimeError("TTS engine failed to produce an audio file.")
        
    logger.info(f"[VOICE-ENGINE] Audio successfully rendered to {output_path}")
    return output_path
