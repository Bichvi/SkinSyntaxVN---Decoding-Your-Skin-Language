import glob
import math
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import uuid
import wave
from pathlib import Path

import numpy as np
import torch
from flask import Flask, after_this_request, jsonify, request, send_file
from PIL import Image, ImageFilter


app = Flask(__name__)
SERVICE_ROOT = Path(__file__).resolve().parent
SADTALKER_ROOT = Path(os.getenv("SADTALKER_ROOT", "/opt/SadTalker")).resolve()
WAV2LIP_ROOT = Path(
    os.getenv("WAV2LIP_ROOT", str(SERVICE_ROOT.parent / ".runtime" / "Wav2Lip"))
).resolve()
WAV2LIP_CHECKPOINT = Path(
    os.getenv(
        "WAV2LIP_CHECKPOINT",
        str(WAV2LIP_ROOT / "checkpoints" / "wav2lip_gan.pth"),
    )
).resolve()
INFERENCE_TIMEOUT = int(os.getenv("SADTALKER_INFERENCE_TIMEOUT", "14400"))
FFMPEG_BIN = os.getenv("AI_IDOL_FFMPEG_BIN", "ffmpeg")
WAV2LIP_BATCH_SIZE = max(1, int(os.getenv("AI_IDOL_WAV2LIP_BATCH_SIZE", "2")))
WAV2LIP_TEMPLATE_SECONDS = max(
    5, int(os.getenv("AI_IDOL_WAV2LIP_TEMPLATE_SECONDS", "20"))
)
AVATAR_CHUNK_SECONDS = max(4, int(os.getenv("AI_IDOL_AVATAR_CHUNK_SECONDS", "8")))
EXPRESSION_SCALE = min(
    1.0, max(0.5, float(os.getenv("AI_IDOL_AVATAR_EXPRESSION_SCALE", "0.78")))
)
RENDER_LOCK = threading.Lock()
SEGMENTATION_LOCK = threading.Lock()
SEGMENTATION_MODEL = None
IMAGE_SUFFIXES = {".png", ".jpg", ".jpeg", ".webp"}
VIDEO_SUFFIXES = {".mp4", ".mov", ".avi", ".mkv", ".webm"}


def _cuda_status():
    if not torch.cuda.is_available():
        return False, "PyTorch không nhìn thấy GPU NVIDIA/CUDA."
    try:
        major, minor = torch.cuda.get_device_capability(0)
        required_arch = f"sm_{major}{minor}"
        supported_arches = set(torch.cuda.get_arch_list())
        if required_arch not in supported_arches and f"compute_{major}{minor}" not in supported_arches:
            supported_text = ", ".join(sorted(supported_arches)) or "không có"
            return False, (
                f"PyTorch thiếu kernel {required_arch}; các kernel hiện có: "
                f"{supported_text}."
            )
        # Do not allocate a probe tensor in this long-running web process. On a
        # 4 GB laptop GPU that CUDA context keeps hundreds of MB away from the
        # short-lived SadTalker renderer. A real GPU kernel is covered by the
        # startup smoke test; architecture compatibility is enough here.
        return True, None
    except Exception as exc:
        return False, str(exc)


def _run(command, *, cwd=None, timeout=INFERENCE_TIMEOUT):
    result = subprocess.run(
        command,
        cwd=cwd,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        timeout=timeout,
    )
    if result.returncode:
        tail = "\n".join(result.stdout.splitlines()[-30:])
        raise RuntimeError(tail or f"Lệnh kết thúc với mã {result.returncode}")
    return result.stdout


def _prepare_source(avatar_path, background_path, target_path, video_format):
    width, height = (1280, 720) if video_format == "16:9" else (720, 1280)
    if background_path:
        cutout_path = target_path.with_name("avatar-cutout.png")
        _prepare_avatar_cutout(avatar_path, cutout_path)
        # A face that occupies only a few pixels becomes waxy after SadTalker's
        # 256 px renderer. Frame the cut-out like a studio presenter instead of
        # retaining transparent padding from a full-body upload.
        if video_format == "16:9":
            fg_width, fg_height = int(width * 0.58), int(height * 0.96)
        else:
            fg_width, fg_height = int(width * 0.92), int(height * 0.96)
        filters = (
            f"[0:v]scale={width}:{height}:force_original_aspect_ratio=increase,"
            f"crop={width}:{height},setsar=1[bg];"
            f"[1:v]scale={fg_width}:{fg_height}:force_original_aspect_ratio=decrease,"
            "setsar=1[fg];[bg][fg]overlay=(W-w)/2:H-h:format=auto[out]"
        )
        command = [
            FFMPEG_BIN, "-y", "-i", str(background_path), "-i", str(cutout_path),
            "-filter_complex", filters, "-map", "[out]", "-frames:v", "1", str(target_path),
        ]
    else:
        filters = (
            f"scale={width}:{height}:force_original_aspect_ratio=decrease,"
            f"pad={width}:{height}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1"
        )
        command = [
            FFMPEG_BIN, "-y", "-i", str(avatar_path), "-vf", filters,
            "-frames:v", "1", str(target_path),
        ]
    _run(command, timeout=120)


def _prepare_avatar_cutout(source_path, output_path):
    """Keep real alpha, otherwise create a local foreground mask for custom backgrounds."""
    try:
        with Image.open(source_path) as uploaded:
            uploaded.load()
            rgba = uploaded.convert("RGBA")
        alpha_min, _ = rgba.getchannel("A").getextrema()
        if alpha_min < 250:
            _crop_cutout_to_subject(rgba).save(output_path)
            return

        rgb = rgba.convert("RGB")
        preview = rgb.copy()
        preview.thumbnail((512, 512), Image.Resampling.LANCZOS)
        from torchvision.transforms import functional as vision_f

        input_tensor = vision_f.to_tensor(preview)
        input_tensor = vision_f.normalize(
            input_tensor, mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225)
        ).unsqueeze(0)
        model = _person_segmentation_model()
        with torch.no_grad():
            scores = model(input_tensor)["out"][0]
            person_probability = scores.softmax(dim=0)[15]
        alpha = Image.fromarray(
            np.uint8(person_probability.clamp(0, 1).cpu().numpy() * 255), mode="L"
        ).resize(rgb.size, Image.Resampling.BILINEAR)
        # Keep hair edges but do not expand the mask into a fake checkerboard
        # or white backdrop; that expansion becomes a moving halo in video.
        alpha = alpha.filter(ImageFilter.MedianFilter(5)).filter(
            ImageFilter.MinFilter(5)
        ).filter(ImageFilter.GaussianBlur(1.0))
        alpha_array = np.asarray(alpha, dtype=np.float32)
        alpha_array = np.uint8(
            np.clip((alpha_array - 36.0) * (255.0 / 180.0), 0.0, 255.0)
        )
        alpha = Image.fromarray(alpha_array, mode="L")
        alpha_values = np.asarray(alpha)
        if float((alpha_values > 64).mean()) < 0.02:
            raise RuntimeError("Không nhận diện được cơ thể người trong ảnh.")
        rgba.putalpha(alpha)
        _crop_cutout_to_subject(rgba).save(output_path)
    except Exception as exc:
        # A failed optional cutout must never delete parts of the presenter.
        app.logger.warning("Automatic portrait cutout failed; using original image: %s", exc)
        with Image.open(source_path) as uploaded:
            _crop_cutout_to_subject(uploaded.convert("RGBA")).save(output_path)


def _crop_cutout_to_subject(rgba):
    """Remove transparent padding so the presenter is large enough to animate cleanly."""
    alpha = rgba.getchannel("A")
    solid_alpha = alpha.point(lambda value: 255 if value >= 48 else 0)
    bbox = solid_alpha.getbbox()
    if not bbox:
        return rgba
    left, top, right, bottom = bbox
    subject_width = right - left
    subject_height = bottom - top
    pad_x = max(8, int(subject_width * 0.05))
    pad_top = max(8, int(subject_height * 0.04))
    pad_bottom = max(4, int(subject_height * 0.015))
    return rgba.crop((
        max(0, left - pad_x),
        max(0, top - pad_top),
        min(rgba.width, right + pad_x),
        min(rgba.height, bottom + pad_bottom),
    ))


def _person_segmentation_model():
    global SEGMENTATION_MODEL
    with SEGMENTATION_LOCK:
        if SEGMENTATION_MODEL is None:
            from torchvision.models.segmentation import deeplabv3_resnet50

            SEGMENTATION_MODEL = deeplabv3_resnet50(pretrained=True, progress=False)
            SEGMENTATION_MODEL.eval().cpu()
        return SEGMENTATION_MODEL


def _normalise_output(
    generated_path, audio_path, output_path, video_format, background_path=None
):
    width, height = (1280, 720) if video_format == "16:9" else (720, 1280)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    if background_path:
        # Motion templates are opaque. The selected background therefore fills
        # only the letterbox area when the source and target aspect ratios differ.
        filters = (
            f"[0:v]scale={width}:{height}:force_original_aspect_ratio=increase,"
            f"crop={width}:{height},setsar=1[bg];"
            f"[1:v]scale={width}:{height}:force_original_aspect_ratio=decrease,"
            "setsar=1[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2:shortest=1[v]"
        )
        command = [
            FFMPEG_BIN, "-y", "-loop", "1", "-framerate", "25", "-i",
            str(background_path), "-i", str(generated_path), "-i", str(audio_path),
            "-filter_complex", filters, "-map", "[v]", "-map", "2:a:0", "-shortest",
        ]
    else:
        filters = (
            f"scale={width}:{height}:force_original_aspect_ratio=decrease,"
            f"pad={width}:{height}:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1"
        )
        command = [
            FFMPEG_BIN, "-y", "-i", str(generated_path), "-i", str(audio_path),
            "-map", "0:v:0", "-map", "1:a:0", "-shortest", "-vf", filters,
        ]
    command.extend([
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "21",
        "-pix_fmt", "yuv420p", "-r", "25", "-c:a", "aac", "-b:a", "128k",
        "-ar", "44100", "-ac", "1", "-movflags", "+faststart", str(output_path),
    ])
    _run(command)


def _prepare_motion_template(source_path, output_path, video_format):
    """Normalize high-frame-rate uploads before Wav2Lip reads every frame."""
    max_width, max_height = ((1280, 720) if video_format == "16:9" else (720, 1280))
    filters = (
        f"fps=25,scale=w='min(iw,{max_width})':h='min(ih,{max_height})':"
        "force_original_aspect_ratio=decrease,"
        "scale=trunc(iw/2)*2:trunc(ih/2)*2,setsar=1"
    )
    _run([
        FFMPEG_BIN, "-y", "-i", str(source_path), "-t",
        str(WAV2LIP_TEMPLATE_SECONDS), "-an", "-vf", filters,
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "18",
        "-pix_fmt", "yuv420p", "-movflags", "+faststart", str(output_path),
    ], timeout=900)
    if not output_path.is_file() or output_path.stat().st_size < 1024:
        raise RuntimeError("Không chuẩn hóa được video nhân vật trước khi nhép môi.")


def _generate_wav2lip(avatar_path, audio_path, output_path):
    if not (WAV2LIP_ROOT / "inference.py").is_file():
        raise RuntimeError("Wav2Lip local chưa được cài đặt.")
    if not WAV2LIP_CHECKPOINT.is_file():
        raise RuntimeError("Thiếu model wav2lip_gan.pth.")
    face_detector = WAV2LIP_ROOT / "face_detection" / "detection" / "sfd" / "s3fd.pth"
    if not face_detector.is_file():
        raise RuntimeError("Thiếu model nhận diện khuôn mặt s3fd.pth.")
    attempts = [
        (WAV2LIP_BATCH_SIZE, 1),
        (1, 1),
        (1, 2),
    ]
    seen_attempts = set()
    last_error = None
    for batch_size, resize_factor in attempts:
        if (batch_size, resize_factor) in seen_attempts:
            continue
        seen_attempts.add((batch_size, resize_factor))
        output_path.unlink(missing_ok=True)
        command = [
            sys.executable, str(SERVICE_ROOT / "wav2lip_runner.py"),
            "--checkpoint_path", str(WAV2LIP_CHECKPOINT),
            "--face", str(avatar_path),
            "--audio", str(audio_path),
            "--outfile", str(output_path),
            "--face_det_batch_size", str(min(2, batch_size)),
            "--wav2lip_batch_size", str(batch_size),
            "--resize_factor", str(resize_factor),
            "--pads", "0", "15", "0", "0",
        ]
        try:
            _run(command, cwd=WAV2LIP_ROOT)
            last_error = None
            break
        except RuntimeError as exc:
            message = str(exc)
            if "Face not detected" in message or "face not detected" in message.lower():
                raise RuntimeError(
                    "Wav2Lip không thấy mặt ở một số khung hình. Hãy dùng video thấy rõ mặt "
                    "liên tục, không quay lưng, không che miệng và không cắt cảnh."
                ) from exc
            if "out of memory" not in message.lower():
                raise
            last_error = exc
            app.logger.warning(
                "Wav2Lip OOM with batch=%s resize=%s; retrying lower-memory mode",
                batch_size, resize_factor,
            )
    if last_error is not None:
        raise RuntimeError(
            "GPU 4 GB vẫn hết bộ nhớ sau khi Wav2Lip đã tự hạ batch và độ phân giải. "
            "Hãy đóng ứng dụng dùng GPU hoặc chọn clip ngắn hơn."
        ) from last_error
    if not output_path.is_file() or output_path.stat().st_size < 1024:
        raise RuntimeError("Wav2Lip không tạo được video đầu ra hợp lệ.")


def _audio_duration_seconds(audio_path):
    try:
        with wave.open(str(audio_path), "rb") as audio:
            return audio.getnframes() / float(audio.getframerate())
    except (wave.Error, EOFError, ZeroDivisionError) as exc:
        raise ValueError(f"Âm thanh đầu vào không phải WAV hợp lệ: {exc}") from exc


def _split_audio(audio_path, work_dir):
    duration = _audio_duration_seconds(audio_path)
    if duration <= AVATAR_CHUNK_SECONDS + 0.05:
        return [audio_path]
    chunks = []
    for index in range(int(math.ceil(duration / AVATAR_CHUNK_SECONDS))):
        start = index * AVATAR_CHUNK_SECONDS
        chunk_path = work_dir / f"speech-{index:03d}.wav"
        _run([
            FFMPEG_BIN, "-y", "-ss", f"{start:.3f}", "-i", str(audio_path),
            "-t", str(AVATAR_CHUNK_SECONDS), "-ac", "1", "-ar", "16000",
            "-c:a", "pcm_s16le", str(chunk_path),
        ], timeout=120)
        if _audio_duration_seconds(chunk_path) > 0.05:
            chunks.append(chunk_path)
    if not chunks:
        raise RuntimeError("Không chia được âm thanh thành các đoạn render.")
    return chunks


def _concat_video_chunks(video_paths, output_path):
    if len(video_paths) == 1:
        return video_paths[0]
    concat_path = output_path.with_suffix(".txt")
    concat_path.write_text(
        "\n".join(f"file '{path.resolve().as_posix()}'" for path in video_paths),
        encoding="utf-8",
    )
    try:
        _run([
            FFMPEG_BIN, "-y", "-f", "concat", "-safe", "0", "-i", str(concat_path),
            "-an", "-c:v", "copy", str(output_path),
        ], timeout=600)
    except RuntimeError:
        inputs = []
        for path in video_paths:
            inputs.extend(["-i", str(path)])
        streams = "".join(f"[{index}:v:0]" for index in range(len(video_paths)))
        _run([
            FFMPEG_BIN, "-y", *inputs,
            "-filter_complex", f"{streams}concat=n={len(video_paths)}:v=1:a=0[outv]",
            "-map", "[outv]", "-c:v", "libx264", "-preset", "veryfast",
            "-crf", "21", "-pix_fmt", "yuv420p", str(output_path),
        ], timeout=1800)
    return output_path


@app.get("/health")
def health():
    checkpoints = SADTALKER_ROOT / "checkpoints"
    ready = all((checkpoints / name).is_file() for name in (
        "SadTalker_V0.0.2_256.safetensors",
        "mapping_00109-model.pth.tar",
    ))
    cuda_usable, cuda_error = _cuda_status()
    wav2lip_ready = all(path.is_file() for path in (
        WAV2LIP_ROOT / "inference.py",
        WAV2LIP_CHECKPOINT,
        WAV2LIP_ROOT / "face_detection" / "detection" / "sfd" / "s3fd.pth",
    ))
    return jsonify({
        "ok": ready and wav2lip_ready and cuda_usable,
        "cuda": cuda_usable,
        "cuda_error": cuda_error,
        "cuda_runtime": torch.version.cuda,
        "torch": torch.__version__,
        "gpu": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        "model_ready": ready,
        "wav2lip_ready": wav2lip_ready,
        "wav2lip_batch_size": WAV2LIP_BATCH_SIZE,
    }), 200 if ready and wav2lip_ready and cuda_usable else 503


@app.post("/api/generate")
def generate():
    work_dir = None
    keep_until_response_finishes = False
    try:
        audio_upload = request.files.get("audio")
        avatar_upload = request.files.get("avatar")
        background_upload = request.files.get("background")
        if not audio_upload or not avatar_upload:
            raise ValueError("Thiếu file âm thanh hoặc nhân vật.")
        video_format = "16:9" if request.form.get("video_format") == "16:9" else "9:16"
        cuda_usable, cuda_error = _cuda_status()
        if not cuda_usable:
            raise RuntimeError(f"Engine avatar chưa dùng được GPU NVIDIA/CUDA: {cuda_error}")

        work_dir = Path(tempfile.mkdtemp(prefix=f"skinsyntax-talking-head-{uuid.uuid4().hex[:8]}-"))
        audio_path = work_dir / "speech.wav"
        avatar_suffix = Path(avatar_upload.filename or "avatar.png").suffix.lower()
        if avatar_suffix not in IMAGE_SUFFIXES | VIDEO_SUFFIXES:
            raise ValueError("Nhân vật phải là ảnh PNG/JPG/WebP hoặc video MP4/WebM hợp lệ.")
        avatar_path = work_dir / f"avatar{avatar_suffix}"
        background_path = None
        audio_upload.save(audio_path)
        avatar_upload.save(avatar_path)
        if background_upload:
            background_suffix = Path(background_upload.filename or "background.png").suffix.lower()
            if background_suffix not in IMAGE_SUFFIXES:
                raise ValueError("Background phải là ảnh PNG, JPG hoặc WebP hợp lệ.")
            background_path = work_dir / f"background{background_suffix}"
            background_upload.save(background_path)

        if avatar_suffix in VIDEO_SUFFIXES:
            prepared_avatar_path = work_dir / "motion-template-25fps.mp4"
            _prepare_motion_template(
                avatar_path, prepared_avatar_path, video_format
            )
            generated_path = work_dir / "wav2lip.mp4"
            with RENDER_LOCK:
                _generate_wav2lip(prepared_avatar_path, audio_path, generated_path)
            output_path = work_dir / "talking-head.mp4"
            _normalise_output(
                generated_path, audio_path, output_path, video_format, background_path
            )

            @after_this_request
            def cleanup_motion(response):
                shutil.rmtree(work_dir, ignore_errors=True)
                return response

            keep_until_response_finishes = True
            return send_file(output_path, mimetype="video/mp4", as_attachment=False)

        source_path = work_dir / "source.png"
        result_dir = work_dir / "results"
        result_dir.mkdir()
        _prepare_source(avatar_path, background_path, source_path, video_format)

        audio_chunks = _split_audio(audio_path, work_dir)
        generated_chunks = []
        with RENDER_LOCK:
            for index, audio_chunk in enumerate(audio_chunks):
                chunk_result_dir = result_dir / f"chunk-{index:03d}"
                chunk_result_dir.mkdir()
                command = [
                    sys.executable, str(SERVICE_ROOT / "natural_sadtalker.py"),
                    "--driven_audio", str(audio_chunk),
                    "--source_image", str(source_path),
                    "--checkpoint_dir", str(SADTALKER_ROOT / "checkpoints"),
                    "--result_dir", str(chunk_result_dir),
                    "--size", "256", "--batch_size", "1",
                    "--preprocess", "full",
                    "--expression_scale", f"{EXPRESSION_SCALE:.2f}",
                ]
                _run(command, cwd=SADTALKER_ROOT)
                candidates = sorted(
                    glob.glob(str(chunk_result_dir / "*.mp4")), key=os.path.getmtime
                )
                if not candidates:
                    raise RuntimeError(
                        f"SadTalker không trả về video cho đoạn {index + 1}/{len(audio_chunks)}."
                    )
                generated_chunks.append(Path(candidates[-1]))
        generated_path = _concat_video_chunks(
            generated_chunks, work_dir / "talking-head-chunks.mp4"
        )
        output_path = work_dir / "talking-head.mp4"
        _normalise_output(generated_path, audio_path, output_path, video_format)

        @after_this_request
        def cleanup(response):
            shutil.rmtree(work_dir, ignore_errors=True)
            return response

        keep_until_response_finishes = True
        return send_file(output_path, mimetype="video/mp4", as_attachment=False)
    except subprocess.TimeoutExpired:
        return jsonify({"ok": False, "message": "Tạo khuôn mặt nói quá thời gian cho phép."}), 504
    except (ValueError, RuntimeError) as exc:
        return jsonify({"ok": False, "message": str(exc)}), 422
    except Exception as exc:
        app.logger.exception("Talking-head generation failed")
        return jsonify({"ok": False, "message": f"Lỗi engine khuôn mặt: {exc}"}), 500
    finally:
        if work_dir and work_dir.exists() and not keep_until_response_finishes:
            shutil.rmtree(work_dir, ignore_errors=True)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=7861)
