"""Build only the prototype narration with the installed local Piper voice.

Run in the existing AI Idol container; output is a new, caller-specified directory.
No application imports, database writes, remote TTS, API keys or GPU are used.
"""
import argparse
import math
import json
import pathlib
import subprocess
import tempfile
import wave

SECTIONS = [
    ("greeting", "happy", "hello", "Meo! Xin chào bạn! Mình là Syna, mèo nhỏ của Skin Syntax.", "Meo! Xin chào bạn! Mình là Si Na, mèo nhỏ của Skin Syntax."),
    ("talking", "neutral", "leaf", "Nhìn nè! Trên đầu mình là cây rau má. Chiếc lá nhỏ mang tinh thần làm dịu, phục hồi và chữa lành.", None),
    ("presenting", "confident", "sparkle", "Hôm nay, mình cùng bạn tìm hiểu sản phẩm nhé. Cứ từ từ, mình sẽ giải thích từng chút một!", None),
    ("thinking", "thinking", "question", "Bạn thích Syna nháy mắt, vẫy tay, hay ôm một trái tim? Thử các nút bên cạnh nha!", "Bạn thích Si Na nháy mắt, vẫy tay, hay ôm một trái tim? Thử các nút bên cạnh nha!"),
    ("happy", "happy", "heart", "Gửi bạn một chút vui vẻ nè! Cảm ơn bạn đã ghé chơi. Hẹn gặp lại nhé. Meo!", None),
]

# A fictional product for layout review, not a real ingredient list or claim.
PRESENTATION_SECTIONS = [
    ("greeting", "happy", "hello", "Meo! Chào bạn, mình là Syna. Hôm nay mình có một chiếc bảng nhỏ nè!", "Meo! Chào bạn, mình là Si Na. Hôm nay mình có một chiếc bảng nhỏ nè!", None, False),
    ("talking", "neutral", None, "Đây là gel dưỡng rau má minh họa. Mình dùng sản phẩm mẫu này để thử cách giới thiệu nhé!", None, None, False),
    ("talking", "neutral", "leaf", "Nhìn lên bảng cùng mình nha! Ý đầu tiên là chiết xuất rau má. Mình ghi tên ở dòng số một nè.", None, "centella", True),
    ("talking", "neutral", None, "Mình quay lại với bạn đây. Trên bảng chỉ giữ ý chính, còn mình sẽ kể từng chút một nhé!", None, None, False),
    ("talking", "neutral", None, "Tiếp theo, bạn nhìn dòng thứ hai nha. Glycerin là tên thành phần tiếp theo trong ví dụ này.", "Tiếp theo, bạn nhìn dòng thứ hai nha. Gli xê rin là tên thành phần tiếp theo trong ví dụ này.", "glycerin", True),
    ("talking", "neutral", None, "Còn dòng thứ ba là Panthenol. Mình chỉ vào đây để bạn dễ theo dõi nhé!", "Còn dòng thứ ba là Pan thê nôn. Mình chỉ vào đây để bạn dễ theo dõi nhé!", "panthenol", True),
    ("talking", "confident", "sparkle", "Vậy là bảng đã có đủ ba ý rồi. Đây chỉ là nội dung minh họa, chưa phải công thức sản phẩm thật nha!", None, "summary", False),
    ("happy", "happy", "heart", "Bạn thấy cách giới thiệu này dễ theo dõi hơn không? Syna gửi bạn một trái tim nè. Meo!", "Bạn thấy cách giới thiệu này dễ theo dõi hơn không? Si Na gửi bạn một trái tim nè. Meo!", "summary", False),
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("output", type=pathlib.Path)
    parser.add_argument("--pitch-semitones", type=float, default=2.5)
    parser.add_argument("--presentation", action="store_true", help="Build the isolated product-board design demo")
    args = parser.parse_args()
    if not math.isfinite(args.pitch_semitones) or not 0 <= args.pitch_semitones <= 5:
        parser.error("Pitch must be between 0 and 5 semitones.")
    args.output.mkdir(parents=True, exist_ok=True)
    narration = args.output / "narration.wav"
    metadata = args.output / "narration.json"
    if narration.exists() or metadata.exists():
        raise SystemExit("Refusing to overwrite an existing narration; choose a new directory.")
    cues, cursor = [], 0.0
    sections = PRESENTATION_SECTIONS if args.presentation else [(*section, None, False) for section in SECTIONS]
    with tempfile.TemporaryDirectory(prefix="mascot-speech-") as temporary:
        with wave.open(str(narration), "wb") as output:
            parameters = None
            for index, (gesture, expression, sticker, text, pronunciation, board, look) in enumerate(sections):
                raw = pathlib.Path(temporary) / f"raw-{index}.wav"
                part = pathlib.Path(temporary) / f"part-{index}.wav"
                subprocess.run([
                    "piper", "--model", "/opt/piper/vi_VN-vais1000-medium.onnx",
                    "--config", "/opt/piper/vi_VN-vais1000-medium.onnx.json",
                    "--output_file", str(raw), "--length_scale", "1.0",
                    "--noise_scale", "0.6", "--noise_w_scale", "0.7",
                    "--sentence_silence", "0.14", "--volume", "0.82",
                ], input=pronunciation or text, text=True, check=True, capture_output=True, timeout=120)
                with wave.open(str(raw), "rb") as raw_source:
                    rate = raw_source.getframerate()
                pitch = 2 ** (args.pitch_semitones / 12)
                filters = (
                    f"asetrate={round(rate * pitch)},aresample={rate},atempo={1 / pitch:.7f},"
                    "highpass=f=90,equalizer=f=320:t=q:w=0.8:g=-1.5,"
                    "equalizer=f=2800:t=q:w=0.7:g=1.0,alimiter=limit=0.88:level=false"
                )
                subprocess.run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-n",
                                "-i", str(raw), "-af", filters, "-c:a", "pcm_s16le", str(part)],
                               check=True, capture_output=True, timeout=120)
                with wave.open(str(part), "rb") as source:
                    current = (source.getnchannels(), source.getsampwidth(), source.getframerate())
                    if parameters is None:
                        parameters = current
                        output.setnchannels(current[0])
                        output.setsampwidth(current[1])
                        output.setframerate(current[2])
                    if current != parameters:
                        raise RuntimeError("Piper changed its WAV format between sections.")
                    duration = source.getnframes() / source.getframerate()
                    output.writeframes(source.readframes(source.getnframes()))
                    cues.append({"start": round(cursor, 4), "end": round(cursor + duration, 4),
                                 "text": text, "gesture": gesture,
                                 "expression": expression, "sticker": sticker,
                                 "board": board, "lookAtBoard": look})
                    pause_frames = round(0.42 * current[2])
                    output.writeframes(b"\0" * pause_frames * current[0] * current[1])
                    cursor += duration + pause_frames / current[2]
    metadata.write_text(json.dumps({"voice": "Syna · trong trẻo, vui vẻ (Piper local)",
        "pitchSemitones": args.pitch_semitones, "processedBeforeTiming": True,
        "presentationDemo": args.presentation,
        "duration": round(cursor, 4), "cues": cues}, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"duration": cursor, "sections": len(cues), "output": str(args.output)}))


if __name__ == "__main__":
    main()
