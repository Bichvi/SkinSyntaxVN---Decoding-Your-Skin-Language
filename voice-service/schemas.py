from typing import Optional, List
from pydantic import BaseModel, Field

class TTSRequest(BaseModel):
    text: str = Field(..., description="Văn bản tiếng Việt cần tổng hợp giọng nói", min_length=1)
    voice: Optional[str] = Field(default=None, description="Tên giọng đọc preset (vd: 'Minh Đức', 'Adam', 'Xuân Vĩnh')")
    speed: Optional[float] = Field(default=1.0, description="Tốc độ đọc", ge=0.5, le=2.0)
    precision: Optional[str] = Field(default="int8", description="Mức chính xác ONNX: 'int8' (CPU nhanh) hoặc 'fp32'")

class VoiceItem(BaseModel):
    id: str
    name: str

class HealthResponse(BaseModel):
    status: str
    service: str
    backend: str
    sample_rate: int
    model_loaded: bool
