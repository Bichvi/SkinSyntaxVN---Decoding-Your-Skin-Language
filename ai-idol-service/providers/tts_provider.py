import asyncio
import os
import subprocess
import edge_tts
from gtts import gTTS
from config import PIPER_CONFIG_PATH, PIPER_MODEL_PATH, TTS_PROVIDER, logger

class TTSProvider:
    NORMALIZE_FILTER = (
        "highpass=f=75,lowpass=f=9500,"
        "afftdn=nr=5:nf=-38:tn=1,"
        "alimiter=limit=0.88:level=false"
    )

    def generate(self, text: str, output_path: str) -> bool:
        """Synthesize speech with local Piper first and online providers as fallbacks."""
        providers = [TTS_PROVIDER, "piper", "edge", "gtts"]
        ordered = list(dict.fromkeys(providers))
        errors = []
        for provider in ordered:
            try:
                if provider == "piper":
                    return self._generate_piper(text, output_path)
                if provider == "edge":
                    return self._generate_edge(text, output_path)
                if provider == "gtts":
                    return self._generate_gtts(text, output_path)
            except Exception as exc:
                errors.append(f"{provider}: {exc}")
                logger.warning("[TTS] %s failed: %s", provider, exc)
        raise RuntimeError("Không thể tổng hợp giọng nói: " + "; ".join(errors))

    def _generate_piper(self, text: str, output_path: str) -> bool:
        if not os.path.isfile(PIPER_MODEL_PATH) or not os.path.isfile(PIPER_CONFIG_PATH):
            raise FileNotFoundError("Piper Vietnamese model is not installed")
        wav_path = os.path.splitext(output_path)[0] + ".raw.wav"
        result = subprocess.run(
            ["piper", "--model", PIPER_MODEL_PATH, "--config", PIPER_CONFIG_PATH,
             "--output_file", wav_path],
            input=text, text=True, capture_output=True, timeout=180,
        )
        if result.returncode != 0:
            raise RuntimeError(result.stderr[-1000:])
        try:
            return self._normalize_to_wav(wav_path, output_path)
        finally:
            if os.path.exists(wav_path):
                os.remove(wav_path)

    def _generate_edge(self, text: str, output_path: str) -> bool:
        temp_path = os.path.splitext(output_path)[0] + ".edge.mp3"
        try:
            communicate = edge_tts.Communicate(text, "vi-VN-HoaiMyNeural")
            loop = asyncio.new_event_loop()
            try:
                loop.run_until_complete(communicate.save(temp_path))
            finally:
                loop.close()
            return self._normalize_to_wav(temp_path, output_path)
        except Exception as e:
            raise RuntimeError(str(e)) from e
        finally:
            if os.path.exists(temp_path):
                os.remove(temp_path)

    def _generate_gtts(self, text: str, output_path: str) -> bool:
        temp_path = os.path.splitext(output_path)[0] + ".gtts.mp3"
        try:
            gTTS(text=text, lang="vi").save(temp_path)
            return self._normalize_to_wav(temp_path, output_path)
        finally:
            if os.path.exists(temp_path):
                os.remove(temp_path)

    def _normalize_to_wav(self, input_path: str, output_path: str) -> bool:
        result = subprocess.run(
            [
                "ffmpeg", "-y", "-i", input_path,
                "-af", self.NORMALIZE_FILTER,
                "-ar", "44100", "-ac", "1", "-c:a", "pcm_s16le",
                output_path,
            ],
            capture_output=True, text=True, timeout=180,
        )
        if result.returncode != 0:
            raise RuntimeError(result.stderr[-1000:])
        return os.path.isfile(output_path) and os.path.getsize(output_path) > 44
