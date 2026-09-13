import os
import sys
import logging
from typing import Optional

# Tối ưu: Đặt cache HuggingFace sang ổ D: do ổ C: đầy đĩa
if "HF_HOME" not in os.environ:
    os.environ["HF_HOME"] = r"D:\hf_cache"

logger = logging.getLogger("VoiceService.ModelLoader")
logging.basicConfig(level=logging.INFO)


class TTSModelManager:
    _instance: Optional['TTSModelManager'] = None
    _engine = None

    def __new__(cls):
        if cls._instance is None:
            cls._instance = super(TTSModelManager, cls).__new__(cls)
        return cls._instance

    def initialize(self, precision: str = "int8", threads: int = 4):
        if self._engine is not None:
            logger.info("TTS Engine đã được khởi tạo trước đó (Singleton).")
            return self._engine

        logger.info(f"⏳ Khởi tạo VieNeu-TTS Engine (precision={precision}, threads={threads})...")
        try:
            from vieneu import Vieneu
            self._engine = Vieneu(mode="v3turbo", backend="onnx", precision=precision, threads=threads)
            logger.info("✅ VieNeu-TTS Engine (ONNX INT8) khởi tạo thành công!")
        except ImportError:
            # Fallback nếu vieneu chưa cài qua pip
            scratch_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "scratch", "VieNeu-TTS-main", "src"))
            if os.path.exists(scratch_path) and scratch_path not in sys.path:
                sys.path.insert(0, scratch_path)
            from vieneu import Vieneu
            self._engine = Vieneu(mode="v3turbo", backend="onnx", precision=precision, threads=threads)
            logger.info("✅ VieNeu-TTS Engine khởi tạo thành công từ scratch fallback!")
        
        return self._engine

    def get_engine(self):
        if self._engine is None:
            self.initialize()
        return self._engine

    def is_loaded(self) -> bool:
        return self._engine is not None

model_manager = TTSModelManager()
