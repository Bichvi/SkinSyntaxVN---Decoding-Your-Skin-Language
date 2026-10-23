import asyncio
import json
import logging
import sys
import time
import requests
from typing import Callable, Iterable, Optional, Tuple

from config import config
from voice_queue import VoiceQueueEngine, CommentItem

logger = logging.getLogger("LiveKitVoiceAgent")
logging.basicConfig(level=logging.INFO)

def _probe_health(url: str, timeout: float = 3.0) -> bool:
    """True nếu GET url trả 2xx. Chỉ dùng cho kiểm tra sẵn sàng, không tốn tài nguyên chat."""
    try:
        return requests.get(url, timeout=timeout).ok
    except requests.RequestException:
        return False


def wait_for_dependencies(
    targets: Iterable[Tuple[str, str]],
    max_wait: float,
    initial_backoff: float,
    max_backoff: float,
    probe: Callable[[str], bool] = _probe_health,
    sleep: Callable[[float], None] = time.sleep,
    monotonic: Callable[[], float] = time.monotonic,
) -> bool:
    """Chờ mọi phụ thuộc (tên, health_url) trả OK với exponential backoff.

    Trả False nếu hết max_wait mà vẫn còn phụ thuộc chưa sẵn sàng. Không gửi
    bất kỳ request chat/TTS nào trong lúc chờ.
    """
    pending = list(targets)
    deadline = monotonic() + max_wait
    delay = initial_backoff
    while pending:
        pending = [(name, url) for name, url in pending if not probe(url)]
        if not pending:
            return True
        if monotonic() + delay > deadline:
            names = ", ".join(name for name, _ in pending)
            logger.error(f"Phụ thuộc chưa sẵn sàng sau {max_wait:.0f}s: {names}")
            return False
        logger.warning(
            "Chờ phụ thuộc: %s (thử lại sau %.0fs)", ", ".join(n for n, _ in pending), delay
        )
        sleep(delay)
        delay = min(delay * 2, max_backoff)
    return True


class AIBeautyAdvisorCoHost:
    def __init__(self, room_name: str = "default-room"):
        self.room_name = room_name
        self.queue_engine = VoiceQueueEngine(
            max_size=config.MAX_QUEUE_SIZE,
            cooldown_sec=config.COOLDOWN_SECONDS,
            ttl_sec=config.TTL_SECONDS
        )
        self.is_running = False

    def on_comment_received(self, user_id: str, user_name: str, text: str, is_vip: bool = False, is_buyer: bool = False):
        """Callback khi có comment mới từ LiveKit Room Chat."""
        comment = CommentItem(
            comment_id=f"cmt_{int(time.time()*1000)}",
            user_id=user_id,
            user_name=user_name,
            text=text,
            is_vip=is_vip,
            is_buyer=is_buyer
        )
        self.queue_engine.push(comment)

    def fetch_ai_rag_answer(self, comment: CommentItem) -> str:
        """Gửi prompt tới ai-service-flask RAG để lấy câu trả lời tư vấn da/mỹ phẩm ngắn gọn."""
        prompt = (
            f"Bạn là SYNA - chú mèo chibi mascot và là AI Skincare Advisor Co-Host trong phòng livestream bán mỹ phẩm SkinSyntax. "
            f"Khán giả {comment.user_name} đặt câu hỏi: '{comment.text}'. "
            f"Hãy trả lời ngắn gọn trong 1-2 câu (dưới 35 từ), xưng Syna hoặc mình, cực kỳ thân thiện ('Để Syna xem thử nhé!'), tư vấn chuẩn chuyên môn da liễu."
        )
        try:
            resp = requests.post(
                config.AI_SERVICE_URL,
                json={"message": prompt, "session_id": f"live_{comment.user_id}"},
                timeout=10
            )
            if resp.status_code == 200:
                data = resp.json()
                answer = data.get("response", "").strip()
                if answer:
                    return answer
        except Exception as e:
            logger.error(f"❌ Lỗi gọi AI RAG service: {e}")

        return f"Cảm ơn {comment.user_name} đã đặt câu hỏi ạ! Bạn có thể xem chi tiết sản phẩm SkinSyntax trên góc màn hình nhé!"

    async def process_queue_loop(self):
        """Vòng lặp chính xử lý Voice Queue, lấy Text RAG -> Gọi TTS -> Publish Audio."""
        self.is_running = True
        logger.info("🤖 AI Beauty Advisor Co-Host Worker đã sẵn sàng lắng nghe Queue...")
        
        while self.is_running:
            item = self.queue_engine.pop_next()
            if item is None:
                await asyncio.sleep(0.5)
                continue

            logger.info(f"🎤 Đang xử lý comment của {item.user_name}: '{item.text}' (Score: {item.score:.1f})")
            
            # Step 1: Lấy câu trả lời từ Chatbot RAG
            ai_text = self.fetch_ai_rag_answer(item)
            logger.info(f"💡 AI RAG Answer: '{ai_text}'")

            # Step 2: Stream audio PCM từ voice-service TTS
            try:
                tts_resp = requests.post(
                    config.TTS_SERVICE_URL,
                    json={"text": ai_text, "voice": "Minh Đức"},
                    stream=True,
                    timeout=15
                )
                
                if tts_resp.status_code == 200:
                    logger.info("🔊 Đã nhận PCM Audio Stream từ voice-service. Publishing to LiveKit Room...")
                    
                    # Đọc stream PCM theo chunk (1920 bytes = 20ms @ 48kHz Mono 16-bit)
                    for chunk in tts_resp.iter_content(chunk_size=1920):
                        if self.queue_engine.is_interrupted:
                            logger.warning("⚡ Hủy phát audio giữa chừng do Host cất tiếng nói!")
                            break
                        
                        # Simulate frames publishing delay (20ms)
                        await asyncio.sleep(0.02)
                        
                    logger.info("✅ Hoàn thành đọc câu thoại!")
                else:
                    logger.error(f"❌ Lỗi HTTP TTS Service: {tts_resp.status_code}")

            except Exception as e:
                logger.error(f"❌ Lỗi trong luồng TTS Audio Streaming: {e}")

            await asyncio.sleep(config.COOLDOWN_SECONDS)

    def stop(self):
        self.is_running = False

if __name__ == "__main__":
    # Không gửi chat RAG khi ai-service/voice-service chưa sẵn sàng; thoát mã 1 để
    # Docker (restart: on-failure) tự giãn khoảng cách thử lại thay vì spam.
    if not wait_for_dependencies(
        [("ai-service", config.AI_HEALTH_URL), ("voice-service", config.TTS_HEALTH_URL)],
        max_wait=config.STARTUP_WAIT_SECONDS,
        initial_backoff=config.STARTUP_BACKOFF_INITIAL,
        max_backoff=config.STARTUP_BACKOFF_MAX,
    ):
        sys.exit(1)

    agent = AIBeautyAdvisorCoHost()
    
    # Test simulation
    print("--- Simulating LiveKit Co-Host Agent Loop ---")
    agent.on_comment_received("u1", "Minh Anh", "Da dầu mụn dùng serum Niacinamide này được không chị?", is_vip=True)
    agent.on_comment_received("u2", "Hoàng Nam", "Hi shop", is_vip=False)
    
    loop = asyncio.get_event_loop()
    try:
        loop.run_until_complete(asyncio.wait_for(agent.process_queue_loop(), timeout=8.0))
    except (asyncio.TimeoutError, KeyboardInterrupt):
        print("Simulation finish.")
