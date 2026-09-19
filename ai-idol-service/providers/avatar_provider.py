import os
import subprocess
import requests
from abc import ABC, abstractmethod
from config import logger, AVATARS_DIR, AVATAR_SERVICE_URL, AVATAR_SERVICE_TIMEOUT

class AvatarProvider(ABC):
    @abstractmethod
    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        """Generate lip-sync video output from audio and baseline template."""
        pass

class MockAvatarProvider(AvatarProvider):
    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        """Loop baseline video or static image to fit audio length and mux them together."""
        logger.info(f"[AVATAR-PROVIDER] Mock rendering using template: {avatar_path}")
        
        if not os.path.exists(audio_path):
            raise FileNotFoundError(f"Audio file not found: {audio_path}")

        # Fallback to default files if baseline template is empty/missing
        if not avatar_path or not os.path.exists(avatar_path):
            default_mp4 = os.path.join(AVATARS_DIR, "default_idol.mp4")
            default_jpg = os.path.join(AVATARS_DIR, "default_idol.jpg")
            
            if os.path.exists(default_mp4):
                avatar_path = default_mp4
            elif os.path.exists(default_jpg):
                avatar_path = default_jpg
            else:
                # Create a black placeholder image if nothing exists
                avatar_path = os.path.join(AVATARS_DIR, "default_idol.jpg")
                logger.info(f"[AVATAR-PROVIDER] Creating default placeholder image at {avatar_path}")
                cmd = ["ffmpeg", "-y", "-f", "lavfi", "-i", "color=c=black:s=720x1280", "-vframes", "1", avatar_path]
                subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        ext = os.path.splitext(avatar_path)[1].lower()
        width, height = (1280, 720) if video_format == "16:9" else (720, 1280)
        normalize_filter = (
            f"scale={width}:{height}:force_original_aspect_ratio=decrease,"
            f"pad={width}:{height}:(ow-iw)/2:(oh-ih)/2,setsar=1"
        )

        if background_path and os.path.isfile(background_path):
            logger.info("[AVATAR-PROVIDER] Compositing selected avatar over selected background")
            avatar_is_video = ext in (".mp4", ".mov", ".avi", ".mkv")
            avatar_input = ["-stream_loop", "-1", "-i", avatar_path] if avatar_is_video else [
                "-loop", "1", "-framerate", "25", "-i", avatar_path
            ]
            foreground_width, foreground_height = int(width * 0.72), int(height * 0.9)
            filter_complex = (
                f"[0:v]scale={width}:{height}:force_original_aspect_ratio=increase,"
                f"crop={width}:{height},setsar=1[bg];"
                f"[1:v]scale={foreground_width}:{foreground_height}:"
                "force_original_aspect_ratio=decrease,setsar=1[fg];"
                "[bg][fg]overlay=(W-w)/2:H-h:shortest=1[v]"
            )
            cmd = [
                "ffmpeg", "-y", "-loop", "1", "-framerate", "25", "-i", background_path,
                *avatar_input, "-i", audio_path, "-shortest",
                "-filter_complex", filter_complex, "-map", "[v]", "-map", "2:a:0",
                "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
                "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "1",
                "-movflags", "+faststart", output_path,
            ]
        # 1. Template is a video loop
        elif ext in (".mp4", ".mov", ".avi", ".mkv"):
            logger.info("[AVATAR-PROVIDER] Template is video. Muxing video loop with audio...")
            cmd = [
                "ffmpeg", "-y",
                "-stream_loop", "-1",
                "-i", avatar_path,
                "-i", audio_path,
                "-shortest",
                "-map", "0:v:0",
                "-map", "1:a:0",
                "-vf", normalize_filter,
                "-c:v", "libx264",
                "-preset", "veryfast",
                "-pix_fmt", "yuv420p",
                "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "1",
                "-movflags", "+faststart",
                output_path
            ]
        # 2. Template is a static image
        else:
            logger.info("[AVATAR-PROVIDER] Template is static image. Muxing static image loop with audio...")
            cmd = [
                "ffmpeg", "-y",
                "-loop", "1",
                "-framerate", "25",
                "-i", avatar_path,
                "-i", audio_path,
                "-shortest",
                "-vf", normalize_filter,
                "-c:v", "libx264",
                "-preset", "veryfast",
                "-pix_fmt", "yuv420p",
                "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "1",
                "-movflags", "+faststart",
                output_path
            ]

        logger.info(f"[AVATAR-PROVIDER] Running FFmpeg command: {' '.join(cmd)}")
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        
        if res.returncode != 0:
            logger.error(f"[AVATAR-PROVIDER] FFmpeg muxing failed: {res.stderr}")
            raise RuntimeError(f"FFmpeg muxing failed: {res.stderr}")

        logger.info(f"[AVATAR-PROVIDER] Muxed video successfully: {output_path}")
        return True

class MuseTalkProvider(AvatarProvider):
    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        logger.info("[AVATAR-PROVIDER] MuseTalkProvider placeholder called.")
        raise NotImplementedError("MuseTalkProvider is reserved for Phase 2 GPU server integration.")

class LivePortraitProvider(AvatarProvider):
    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        logger.info("[AVATAR-PROVIDER] LivePortraitProvider placeholder called.")
        raise NotImplementedError("LivePortraitProvider is reserved for Phase 2 GPU server integration.")


class SadTalkerProvider(AvatarProvider):
    """Use the isolated local GPU service to animate a portrait from speech."""

    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        if not os.path.isfile(audio_path):
            raise FileNotFoundError(f"Audio file not found: {audio_path}")
        if not avatar_path or not os.path.isfile(avatar_path):
            raise ValueError(
                "Chưa có ảnh nhân vật hợp lệ. Hãy tải ảnh chân dung thấy rõ khuôn mặt."
            )

        payload = {
            "video_format": video_format,
        }
        logger.info("[AVATAR-PROVIDER] Sending talking-head job to %s", AVATAR_SERVICE_URL)
        handles = []
        try:
            handles = [open(audio_path, "rb"), open(avatar_path, "rb")]
            files = {
                "audio": (os.path.basename(audio_path), handles[0], "audio/wav"),
                "avatar": (os.path.basename(avatar_path), handles[1], "application/octet-stream"),
            }
            if background_path and os.path.isfile(background_path):
                handles.append(open(background_path, "rb"))
                files["background"] = (
                    os.path.basename(background_path), handles[-1], "application/octet-stream"
                )
            response = requests.post(
                f"{AVATAR_SERVICE_URL}/api/generate",
                data=payload,
                files=files,
                stream=True,
                timeout=(10, AVATAR_SERVICE_TIMEOUT),
            )
        except requests.RequestException as exc:
            raise RuntimeError(
                "Dịch vụ cử động khuôn mặt chưa sẵn sàng. "
                "Hãy kiểm tra dịch vụ ai-idol-avatar local."
            ) from exc
        finally:
            for handle in handles:
                handle.close()

        if response.status_code >= 400:
            try:
                message = response.json().get("message")
            except (ValueError, AttributeError):
                message = None
            response.close()
            raise RuntimeError(f"Không thể tạo nhân vật nói: {message or f'HTTP {response.status_code}'}")
        temp_output = output_path + ".download"
        try:
            with open(temp_output, "wb") as output:
                for chunk in response.iter_content(chunk_size=1024 * 1024):
                    if chunk:
                        output.write(chunk)
            os.replace(temp_output, output_path)
        finally:
            response.close()
            if os.path.exists(temp_output):
                os.remove(temp_output)
        if not os.path.isfile(output_path) or os.path.getsize(output_path) < 1024:
            raise RuntimeError("Dịch vụ khuôn mặt không tạo được video đầu ra hợp lệ.")
        logger.info("[AVATAR-PROVIDER] Talking-head video generated: %s", output_path)
        return True


class Wav2LipProvider(SadTalkerProvider):
    """Lip-sync a motion template while preserving its eye/head/body movement."""

    VIDEO_EXTENSIONS = {".mp4", ".mov", ".avi", ".mkv", ".webm"}

    def generate(self, audio_path: str, avatar_path: str, output_path: str,
                 video_format: str = "9:16", background_path: str = "") -> bool:
        if not avatar_path or not os.path.isfile(avatar_path):
            raise ValueError(
                "Chưa có video nhân vật hợp lệ. Hãy tải clip thấy rõ mặt liên tục."
            )
        if os.path.splitext(avatar_path)[1].lower() not in self.VIDEO_EXTENSIONS:
            raise ValueError("Wav2Lip chỉ nhận video nhân vật MP4 hoặc WebM.")
        logger.info("[AVATAR-PROVIDER] Using Wav2Lip motion template: %s", avatar_path)
        return super().generate(
            audio_path, avatar_path, output_path, video_format, background_path
        )
