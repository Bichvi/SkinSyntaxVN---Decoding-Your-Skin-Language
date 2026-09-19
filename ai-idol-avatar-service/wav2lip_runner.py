"""Run the official Wav2Lip inference script with a small 4 GB GPU fix.

The upstream script deletes its S3FD detector before loading Wav2Lip, but the
CUDA caching allocator keeps the detector's reserved blocks. On a 4 GB laptop
GPU that can make the following model load fail even though no other process is
using the GPU. Keep upstream code and checkpoints untouched; inject only an
explicit garbage collection/cache release at that boundary.
"""

import os
import sys
from pathlib import Path


wav2lip_root = Path(os.environ["WAV2LIP_ROOT"]).resolve()
inference_path = wav2lip_root / "inference.py"
source = inference_path.read_text(encoding="utf-8")
needle = "\tdel detector\n\treturn results"
replacement = (
    "\tdel detector\n"
    "\tif device == 'cuda':\n"
    "\t\timport gc\n"
    "\t\tgc.collect()\n"
    "\t\ttorch.cuda.empty_cache()\n"
    "\treturn results"
)
if needle not in source:
    raise RuntimeError(
        "Wav2Lip inference.py changed; CUDA cleanup injection point was not found."
    )

sys.path.insert(0, str(wav2lip_root))
os.chdir(wav2lip_root)
namespace = {
    "__name__": "__main__",
    "__file__": str(inference_path),
    "__package__": None,
}
exec(compile(source.replace(needle, replacement, 1), str(inference_path), "exec"), namespace)
