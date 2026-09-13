import os
import sys
import time

# Tối ưu: Đặt cache HuggingFace sang ổ D: do ổ C: đầy đĩa
os.environ["HF_HOME"] = r"D:\hf_cache"
os.environ["HF_HUB_DISABLE_SYMLINKS_WARNING"] = "1"

# Reconfigure stdout/stderr for UTF-8 on Windows
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')


scratch_path = r"C:\Users\ACER\.gemini\antigravity-ide\brain\3597e4c5-2b42-43a9-98a0-ce7f1eed59a8\scratch\VieNeu-TTS-main\src"
if os.path.exists(scratch_path) and scratch_path not in sys.path:
    sys.path.insert(0, scratch_path)

def test_standalone_tts():
    print("==================================================")
    print("Phase 1: Test VieNeu-TTS Standalone Generation")
    print("==================================================")
    
    try:
        from vieneu import Vieneu
    except ImportError as e:
        print(f"[FAIL] Khong the import vieneu: {e}")
        return

    print("[INFO] Nap model VieNeu-TTS v3 Turbo ONNX (int8)...")
    t0 = time.time()
    tts = Vieneu(mode="v3turbo", backend="onnx", precision="int8")
    print(f"[OK] Model loaded in {time.time() - t0:.2f}s")

    test_text = "Xin chào các bạn khán giả đang xem livestream SkinSyntax. Hôm nay em sẽ tư vấn sản phẩm kiềm dầu dành cho da nhạy cảm nhé!"
    print(f"[INFO] Dang tong hop van ban: '{test_text}'")
    
    t_gen = time.time()
    audio = tts.infer(test_text, voice="Minh Đức")
    gen_time = time.time() - t_gen
    
    output_dir = os.path.join(os.path.dirname(__file__), "output")
    os.makedirs(output_dir, exist_ok=True)
    out_file = os.path.join(output_dir, "phase1_test_output.wav")
    
    import wave
    import numpy as np

    pcm_data = (np.asarray(audio) * 32767.0).clip(-32768.0, 32767.0).astype(np.int16).tobytes()
    with wave.open(out_file, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(48000)
        w.writeframes(pcm_data)
    
    audio_duration = len(audio) / 48000.0
    rtf = gen_time / audio_duration if audio_duration > 0 else 0
    
    print("--------------------------------------------------")
    print(f"[SUCCESS] Da tao file thanh cong: {out_file}")
    print(f"[METRIC] Thoi luong audio: {audio_duration:.2f} giay")
    print(f"[METRIC] Thoi gian sinh: {gen_time:.2f} giay")
    print(f"[METRIC] RTF (Real-Time Factor): {rtf:.3f} ({1/rtf:.1f}x realtime)")
    print("--------------------------------------------------")

if __name__ == "__main__":
    test_standalone_tts()
