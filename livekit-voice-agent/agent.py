import asyncio
import json
import logging
import time
import requests
from typing import Optional

from config import config
from voice_queue import VoiceQueueEngine, CommentItem

logger = logging.getLogger("LiveKitVoiceAgent")
logging.basicConfig(level=logging.INFO)

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
            f"Bạn là AI Beauty Advisor Co-Host trong phòng livestream bán mỹ phẩm SkinSyntax. "
            f"Khán giả {comment.user_name} đặt câu hỏi: '{comment.text}'. "
            f"Hãy trả lời ngắn gọn trong 1-2 câu (dưới 35 từ), thân thiện, tư vấn chuẩn chuyên môn da liễu."
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
