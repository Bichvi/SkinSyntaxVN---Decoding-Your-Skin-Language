import re
import time
import logging
from typing import List, Optional, Dict
from dataclasses import dataclass, field

logger = logging.getLogger("VoiceQueueEngine")
logging.basicConfig(level=logging.INFO)

@dataclass
class CommentItem:
    comment_id: str
    user_id: str
    user_name: str
    text: str
    is_vip: bool = False
    is_buyer: bool = False
    pinned_product: Optional[str] = None
    timestamp: float = field(default_factory=time.time)
    score: float = 0.0

class VoiceQueueEngine:
    """Quản lý hàng đợi giọng nói AI Advisor với Spam filter, Priority Score, Cooldown & Interrupt."""
    
    def __init__(self, max_size: int = 5, cooldown_sec: float = 3.0, ttl_sec: float = 15.0):
        self.max_size = max_size
        self.cooldown_sec = cooldown_sec
        self.ttl_sec = ttl_sec
        self.queue: List[CommentItem] = []
        self.last_speech_time: float = 0.0
        self.recent_texts: List[str] = []  # Cho deduplication
        self.is_interrupted: bool = False

    def is_spam(self, text: str) -> bool:
        """Kiểm tra spam: rác, lặp ký tự, quá ngắn (<3 ký tự)."""
        clean_text = text.strip()
        if len(clean_text) < 3:
            return True
        
        # Lặp ký tự liên tiếp (vd "aaaaaa", "111111")
        if re.search(r'(.)\1{4,}', clean_text):
            return True
            
        # Từ vô nghĩa / toxic đơn giản
        spam_words = ["dm", "vcl", "cl", "spam", "chửi"]
        if any(w in clean_text.lower() for w in spam_words):
            return True
            
        return False

    def calculate_priority_score(self, comment: CommentItem) -> float:
        """Tính điểm ưu tiên (Priority Score S)."""
        text_lower = comment.text.lower()
        score = 0.0
        
        # 1. Intent score
        skin_keywords = ["da", "mụn", "dầu", "khô", "nhạy cảm", "tẩy trang", "serum", "kem chống nắng", "thành phần", "niacinamide", "bha", "aha"]
        buy_keywords = ["giá", "bao nhiêu", "mua", "đặt", "ship", "khuyến mãi", "ưu đãi", "giảm giá"]
        
        if any(k in text_lower for k in skin_keywords):
            score += 50.0  # Intent tư vấn da/sản phẩm cao nhất
        elif any(k in text_lower for k in buy_keywords):
            score += 30.0  # Intent mua hàng
        else:
            score += 10.0  # Chào hỏi / xã giao

        # 2. User role bonus
        if comment.is_vip:
            score += 20.0
        if comment.is_buyer:
            score += 15.0

        # 3. Product context bonus
        if comment.pinned_product and comment.pinned_product.lower() in text_lower:
            score += 25.0

        # 4. Hình phạt độ dài (>120 kí tự)
        if len(comment.text) > 120:
            score -= 15.0

        # 5. Hình phạt trùng lặp
        for recent in self.recent_texts[-5:]:
            # Simple similarity check
            if len(set(text_lower.split()) & set(recent.split())) >= 3:
                score -= 40.0
                break

        return score

    def push(self, comment: CommentItem) -> bool:
        """Đưa comment vào Queue nếu không phải spam và đạt ưu tiên."""
        if self.is_spam(comment.text):
            logger.info(f"🚫 Bo qua comment spam: '{comment.text}'")
            return False

        comment.score = self.calculate_priority_score(comment)
        
        # Xóa các item đã hết hạn (TTL)
        now = time.time()
        self.queue = [item for item in self.queue if (now - item.timestamp) <= self.ttl_sec]

        # Thêm item mới
        self.queue.append(comment)
        
        # Sắp xếp lại Queue theo Priority Score giảm dần
        self.queue.sort(key=lambda x: x.score, reverse=True)

        # Giữ lại tối đa max_size items
        if len(self.queue) > self.max_size:
            dropped = self.queue.pop()
            logger.info(f"🗑️ Queue đầy. Đã loại comment score thấp nhất ({dropped.score:.1f}): '{dropped.text}'")

        logger.info(f"📥 Đã thêm vào Queue (Score {comment.score:.1f}): '{comment.text}' | Queue Size: {len(self.queue)}")
        return True

    def pop_next(self) -> Optional[CommentItem]:
        """Lấy comment có ưu tiên cao nhất nếu đã hết Cooldown."""
        now = time.time()
        if (now - self.last_speech_time) < self.cooldown_sec:
            return None  # Đang trong thời gian Cooldown nghỉ im lặng

        if not self.queue:
            return None

        # Filter out expired items
        self.queue = [item for item in self.queue if (now - item.timestamp) <= self.ttl_sec]
        if not self.queue:
            return None

        item = self.queue.pop(0)
        self.recent_texts.append(item.text.lower())
        if len(self.recent_texts) > 20:
            self.recent_texts.pop(0)

        self.last_speech_time = now
        self.is_interrupted = False
        return item

    def trigger_interrupt(self):
        """Kích hoạt ngắt lời ngay lập tức (Host Streamer đang nói)."""
        logger.warning("⚡ INTERRUPT TRIGGERED! Dừng AI Co-Host voice playback.")
        self.is_interrupted = True
        # Reset last speech time to apply full cooldown
        self.last_speech_time = time.time()
