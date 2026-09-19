"""Compatibility shims loaded by Python for the isolated SadTalker runtime."""

import os
import subprocess

import numpy as np
import torch


# SadTalker shells out to the bare command name ``ffmpeg``. Some Windows
# Python installations also publish a small, incompatible ffmpeg.exe wrapper
# earlier on PATH. Route only those shell calls to the bundled real binary.
_ffmpeg_bin = os.getenv("AI_IDOL_FFMPEG_BIN")
_original_system = os.system


def _system_with_bundled_ffmpeg(command):
    if (
        _ffmpeg_bin
        and isinstance(command, str)
        and command.lstrip().lower().startswith("ffmpeg ")
    ):
        leading_space_count = len(command) - len(command.lstrip())
        leading_space = command[:leading_space_count]
        # The bundled path for this local runtime is space-free. Avoid a
        # leading quoted executable here because ``cmd.exe /c`` strips the
        # first quote pair using special Windows parsing rules.
        command = f'{leading_space}{_ffmpeg_bin}{command.lstrip()[len("ffmpeg"):]}'
    return _original_system(command)


os.system = _system_with_bundled_ffmpeg


# Wav2Lip invokes FFmpeg through ``subprocess.call`` with a command string.
# Apply the same narrow rewrite so it cannot accidentally pick up the obsolete
# ffmpeg.exe that some Windows Python distributions place earlier on PATH.
_original_call = subprocess.call


def _call_with_bundled_ffmpeg(command, *args, **kwargs):
    if (
        _ffmpeg_bin
        and isinstance(command, str)
        and command.lstrip().lower().startswith("ffmpeg ")
    ):
        leading_space_count = len(command) - len(command.lstrip())
        leading_space = command[:leading_space_count]
        command = f'{leading_space}{_ffmpeg_bin}{command.lstrip()[len("ffmpeg"):]}'
    return _original_call(command, *args, **kwargs)


subprocess.call = _call_with_bundled_ffmpeg


if not hasattr(torch, "frombuffer"):
    _NUMPY_DTYPES = {
        torch.bool: np.bool_,
        torch.uint8: np.uint8,
        torch.int8: np.int8,
        torch.int16: np.int16,
        torch.int32: np.int32,
        torch.int64: np.int64,
        torch.float16: np.float16,
        torch.float32: np.float32,
        torch.float64: np.float64,
    }

    def _frombuffer(buffer, *, dtype, count=-1, offset=0, requires_grad=False):
        is_bfloat16 = dtype == torch.bfloat16
        numpy_dtype = np.uint16 if is_bfloat16 else _NUMPY_DTYPES.get(dtype)
        if numpy_dtype is None:
            raise TypeError(f"Unsupported torch.frombuffer compatibility dtype: {dtype}")
        array = np.frombuffer(buffer, dtype=numpy_dtype, count=count, offset=offset).copy()
        tensor = torch.from_numpy(array)
        if is_bfloat16:
            tensor = tensor.view(torch.bfloat16)
        tensor.requires_grad_(requires_grad)
        return tensor

    torch.frombuffer = _frombuffer
