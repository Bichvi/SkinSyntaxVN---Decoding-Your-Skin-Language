import os
from providers.avatar_provider import (
    LivePortraitProvider,
    MockAvatarProvider,
    MuseTalkProvider,
    SadTalkerProvider,
    Wav2LipProvider,
)
from config import AVATAR_PROVIDER, AVATARS_DIR, BACKGROUNDS_DIR, OUTPUT_DIR, logger


def _asset_path(folder, filename: str) -> str:
    safe_name = os.path.basename(str(filename or ""))
    if not safe_name or safe_name != str(filename):
        return ""
    candidate = os.path.join(folder, safe_name)
    return candidate if os.path.isfile(candidate) else ""

def generate_avatar_video(job_id: str, audio_path: str, config: dict) -> str:
    """Generate avatar video synchronized with the generated audio."""
    custom_avatar = config.get("avatar_asset") or config.get("avatar_template", "")
    avatar_path = _asset_path(AVATARS_DIR, custom_avatar)
    background_path = _asset_path(BACKGROUNDS_DIR, config.get("background_asset", ""))

    # A motion template always uses Wav2Lip. Static portraits retain the
    # configured provider (SadTalker by default), so existing campaigns keep
    # working without a migration.
    avatar_extension = os.path.splitext(avatar_path)[1].lower()
    if avatar_extension in Wav2LipProvider.VIDEO_EXTENSIONS:
        provider = Wav2LipProvider()
        provider_name = "wav2lip"
    else:
        provider_type = AVATAR_PROVIDER.lower()
        if provider_type == "musetalk":
            provider = MuseTalkProvider()
        elif provider_type == "liveportrait":
            provider = LivePortraitProvider()
        elif provider_type == "sadtalker":
            provider = SadTalkerProvider()
        else:
            provider = MockAvatarProvider()
        provider_name = provider_type
    logger.info("[AVATAR-ENGINE] Selected provider: %s", provider_name)
        
    output_filename = f"avatar_{job_id}.mp4"
    output_path = os.path.join(OUTPUT_DIR, output_filename)
    
    provider.generate(
        audio_path, avatar_path, output_path, config.get("format", "9:16"), background_path
    )
    
    if not os.path.exists(output_path):
        raise RuntimeError("Avatar engine failed to produce a video file.")
        
    logger.info(f"[AVATAR-ENGINE] Video generated successfully at {output_path}")
    return output_path
