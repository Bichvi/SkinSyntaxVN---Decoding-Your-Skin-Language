"""Run SadTalker with restrained head pose for a studio-presenter result.

SadTalker's audio-to-pose model is deliberately expressive. On a single
portrait—especially a full-body source—the default movement can pull the face
away from the hair and shoulders. This wrapper keeps lip/blink animation while
scaling only head rotation/translation toward the source pose.
"""

import os
import random
import runpy
import sys
import zlib
from pathlib import Path

SADTALKER_ROOT = Path(os.environ["SADTALKER_ROOT"])
sys.path.insert(0, str(SADTALKER_ROOT))

import torch
from src import generate_batch, generate_facerender_batch


POSE_SCALE = min(
    1.0, max(0.0, float(os.getenv("AI_IDOL_AVATAR_POSE_SCALE", "0.35")))
)
_original_get_facerender_data = generate_facerender_batch.get_facerender_data
_original_get_audio_data = generate_batch.get_data


def _get_natural_audio_data(*args, **kwargs):
    """Replace SadTalker's very frequent random blinks with human pacing."""
    data = _original_get_audio_data(*args, **kwargs)
    original_ratio = data.get("ratio_gt")
    if original_ratio is None or original_ratio.ndim != 2:
        return data

    ratio = torch.zeros_like(original_ratio)
    frame_count = ratio.shape[1]
    seed_text = str(data.get("audio_name", "speech"))
    rng = random.Random(zlib.crc32(seed_text.encode("utf-8")))
    blink_frame = rng.randint(65, 100)  # First blink after 2.6-4.0 seconds.
    curve = torch.tensor(
        [0.35, 0.75, 1.0, 0.75, 0.35],
        dtype=ratio.dtype,
        device=ratio.device,
    )
    while blink_frame + len(curve) <= frame_count:
        ratio[:, blink_frame:blink_frame + len(curve)] = curve
        blink_frame += rng.randint(80, 135)  # Then every 3.2-5.4 seconds.
    data["ratio_gt"] = ratio
    return data


def _get_natural_facerender_data(*args, **kwargs):
    data = _original_get_facerender_data(*args, **kwargs)
    target = data.get("target_semantics_list")
    source = data.get("source_semantics")
    if target is not None and source is not None and POSE_SCALE < 1.0:
        # Shapes are [batch, frames, coeff, radius] and
        # [batch, coeff, radius]. Coefficients 64:70 encode head pose.
        source_pose = source[:, None, 64:70, :]
        target_pose = target[:, :, 64:70, :]
        target[:, :, 64:70, :] = source_pose + (
            target_pose - source_pose
        ) * POSE_SCALE
    return data


generate_facerender_batch.get_facerender_data = _get_natural_facerender_data
generate_batch.get_data = _get_natural_audio_data
runpy.run_path(
    str(SADTALKER_ROOT / "inference.py"),
    run_name="__main__",
)
