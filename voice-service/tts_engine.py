import io
import wave
import numpy as np
from typing import Generator, Optional, List, Tuple
from model_loader import model_manager

SAMPLE_RATE = 48_000

def float32_to_pcm16(audio_f32: np.ndarray) -> bytes:
    """Convert float32 numpy array (-1.0 to 1.0) into PCM 16-bit signed bytes."""
    return (np.asarray(audio_f32) * 32767.0).clip(-32768.0, 32767.0).astype(np.int16).tobytes()

def generate_wav_bytes(text: str, voice: Optional[str] = None) -> bytes:
    """Tổng hợp toàn bộ văn bản và trả về dữ liệu file WAV hoàn chỉnh (48kHz Mono 16-bit PCM)."""
    engine = model_manager.get_engine()
    wav_f32 = engine.infer(text=text, voice=voice)
    
    pcm_data = float32_to_pcm16(wav_f32)
    
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)       # Mono
        w.setsampwidth(2)       # 16-bit = 2 bytes
        w.setframerate(SAMPLE_RATE)
        w.writeframes(pcm_data)
    
    return buf.getvalue()

def generate_pcm_stream(text: str, voice: Optional[str] = None) -> Generator[bytes, None, None]:
    """Stream từng chunk dữ liệu PCM 16-bit raw theo thời gian thực (Realtime Generator)."""
    engine = model_manager.get_engine()
    for chunk_f32 in engine.infer_stream(text=text, voice=voice):
        if chunk_f32 is None or len(chunk_f32) == 0:
            continue
        yield float32_to_pcm16(chunk_f32)

def list_preset_voices() -> List[Tuple[str, str]]:
    """Trả về danh sách giọng mẫu có sẵn."""
    engine = model_manager.get_engine()
    return engine.list_preset_voices()
