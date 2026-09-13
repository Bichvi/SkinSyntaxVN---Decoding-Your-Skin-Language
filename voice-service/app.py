import os
import time
import logging
from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, Response
from fastapi.responses import StreamingResponse, JSONResponse
from fastapi.middleware.cors import CORSMiddleware

from schemas import TTSRequest, HealthResponse
from model_loader import model_manager
import tts_engine

logger = logging.getLogger("VoiceService.API")
logging.basicConfig(level=logging.INFO)

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: Load model once at server startup
    logger.info("🚀 Booting Voice Service... Pre-loading VieNeu-TTS Model into RAM.")
    precision = os.getenv("PRECISION", "int8")
    threads = int(os.getenv("NUM_THREADS", "4"))
    model_manager.initialize(precision=precision, threads=threads)
    yield
    logger.info("🛑 Shutting down Voice Service.")

app = FastAPI(
    title="SkinSyntaxVN VieNeu-TTS Service",
    description="Microservice độc lập chuyển đổi văn bản thành giọng nói Tiếng Việt cho AI Beauty Advisor Co-Host",
    version="1.0.0",
    lifespan=lifespan
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return HealthResponse(
        status="healthy",
        service="voice-service",
        backend="onnx",
        sample_rate=tts_engine.SAMPLE_RATE,
        model_loaded=model_manager.is_loaded()
    )

@app.get("/api/voices")
async def get_voices():
    try:
        voices = tts_engine.list_preset_voices()
        return [{"id": v[1], "name": v[0]} for v[1], v[0] in [(item[1], item[0]) for item in voices]]
    except Exception as e:
        logger.error(f"Lỗi lấy danh sách giọng: {e}")
        return [{"id": "Minh Đức", "name": "Minh Đức (Mặc định)"}]

@app.post("/api/tts")
async def generate_tts(req: TTSRequest):
    if not req.text.strip():
        raise HTTPException(status_code=400, detail="Văn bản không được để trống")
    
    t0 = time.perf_counter()
    try:
        wav_bytes = tts_engine.generate_wav_bytes(text=req.text, voice=req.voice)
        elapsed = time.perf_counter() - t0
        logger.info(f"✅ TTS generated {len(req.text)} chars in {elapsed:.3f}s")
        return Response(content=wav_bytes, media_type="audio/wav")
    except Exception as e:
        logger.error(f"❌ Lỗi tổng hợp giọng nói: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/api/tts/stream")
async def generate_tts_stream(req: TTSRequest):
    if not req.text.strip():
        raise HTTPException(status_code=400, detail="Văn bản không được để trống")
    
    logger.info(f"⚡ Streaming TTS request for text: '{req.text[:30]}...'")
    return StreamingResponse(
        tts_engine.generate_pcm_stream(text=req.text, voice=req.voice),
        media_type="audio/pcm"
    )

if __name__ == "__main__":
    import uvicorn
    port = int(os.getenv("TTS_PORT", "5002"))
    uvicorn.run("app:app", host="0.0.0.0", port=port, reload=False)
