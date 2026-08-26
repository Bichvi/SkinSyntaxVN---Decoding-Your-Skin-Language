# -*- coding: utf-8 -*-
import re
from profile_service import save_user_profile

# Definitions of the 4 survey questions
Q1_TEXT = "Câu 1: Da của bạn hiện tại thuộc loại nào?"
Q1_OPTIONS = (
    "[Da dầu](quicksend:Loại da: Da dầu)\n"
    "[Da khô](quicksend:Loại da: Da khô)\n"
    "[Da hỗn hợp](quicksend:Loại da: Da hỗn hợp)\n"
    "[Da thường](quicksend:Loại da: Da thường)\n"
    "[Mình không chắc](quicksend:Loại da: Không chắc)"
)

Q2_TEXT = "Câu 2: Bạn đang quan tâm hay muốn cải thiện vấn đề nào về da nhất?"
Q2_OPTIONS = (
    "[Mụn](quicksend:Vấn đề da: Mụn)\n"
    "[Thâm](quicksend:Vấn đề da: Thâm)\n"
    "[Đỏ / Kích ứng](quicksend:Vấn đề da: Đỏ kích ứng)\n"
    "[Khô / Bong tróc](quicksend:Vấn đề da: Khô bong tróc)\n"
    "[Lão hóa](quicksend:Vấn đề da: Lão hóa)\n"
    "[Lỗ chân lông](quicksend:Vấn đề da: Lỗ chân lông)\n"
    "[Khác](quicksend:Vấn đề da: Khác)"
)

Q3_TEXT = "Câu 3: Da bạn có dễ bị kích ứng hoặc đỏ rát khi dùng mỹ phẩm mới không?"
Q3_OPTIONS = (
    "[Rất dễ kích ứng](quicksend:Độ nhạy cảm: Rất dễ)\n"
    "[Khá dễ kích ứng](quicksend:Độ nhạy cảm: Khá dễ)\n"
    "[Bình thường (Ít khi)](quicksend:Độ nhạy cảm: Bình thường)\n"
    "[Không rõ](quicksend:Độ nhạy cảm: Không rõ)"
)

Q4_TEXT = "Câu 4: Bạn muốn chi khoảng bao nhiêu cho sản phẩm skincare?"
Q4_OPTIONS = (
    "[Dưới 300k](quicksend:Ngân sách: Dưới 300k)\n"
    "[Từ 300k - 500k](quicksend:Ngân sách: Từ 300k đến 500k)\n"
    "[Từ 500k - 1 triệu](quicksend:Ngân sách: Từ 500k đến 1 triệu)\n"
    "[Trên 1 triệu](quicksend:Ngân sách: Trên 1 triệu)\n"
    "[Không giới hạn ngân sách](quicksend:Ngân sách: Không giới hạn)"
)

def get_last_ai_message(history) -> str:
    """Helper to extract the last AI message from the history list."""
    if not history:
        return ""
    for item in reversed(history):
        item_str = str(item).strip()
        if item_str.startswith("AI:") or item_str.startswith("SkinSyntax AI:"):
            parts = item_str.split(":", 1)
            return parts[1].strip()
    return ""

def parse_budget_value(text: str) -> int:
    """Parse numeric budget value from options."""
    t_lower = text.lower()
    if "dưới 300" in t_lower:
        return 250000
    if "300" in t_lower and "500" in t_lower:
        return 400000
    if "500" in t_lower and "1 triệu" in t_lower:
        return 750000
    if "trên 1 triệu" in t_lower:
        return 1200000
    return 0  # No limit / unknown

def is_in_survey_flow(message: str, history) -> bool:
    """Check if the user is currently answering survey questions."""
    msg_lower = message.lower()
    if msg_lower in ["bắt đầu khảo sát da nhanh", "làm khảo sát da mới"]:
        return True
        
    last_ai = get_last_ai_message(history)
    if not last_ai:
        return False
        
    # If the last AI message was one of our survey questions
    return any(q in last_ai for q in [Q1_TEXT, Q2_TEXT, Q3_TEXT, Q4_TEXT])

def handle_survey_flow(message: str, history, email: str) -> dict:
    """
    State machine for handling the chat survey:
    - Identifies which question the user is responding to.
    - Transitions to the next question.
    - On Q4, aggregates all answers from the history, updates MongoDB, and marks survey as completed.
    """
    msg_lower = message.lower()
    last_ai = get_last_ai_message(history)
    
    # 0. User initiates survey
    if msg_lower in ["bắt đầu khảo sát da nhanh", "làm khảo sát da mới"] or not last_ai:
        return {
            "completed": False,
            "answer": f"Ngọc Vi sẽ giúp bạn làm khảo sát nhanh tình trạng da để tư vấn chính xác nhất.\n\n**{Q1_TEXT}**\n\n{Q1_OPTIONS}"
        }
        
    # 1. User answered Q1 -> Ask Q2
    if Q1_TEXT in last_ai:
        # Extract chosen skin type
        chosen_type = "Không chắc"
        if "da dầu" in msg_lower or "da dau" in msg_lower:
            chosen_type = "Da dầu"
        elif "da khô" in msg_lower or "da kho" in msg_lower:
            chosen_type = "Da khô"
        elif "hỗn hợp" in msg_lower or "hon hop" in msg_lower:
            chosen_type = "Da hỗn hợp"
        elif "da thường" in msg_lower or "da thuong" in msg_lower:
            chosen_type = "Da thường"
            
        return {
            "completed": False,
            "answer": f"Ghi nhận loại da: **{chosen_type}**.\n\n**{Q2_TEXT}**\n\n{Q2_OPTIONS}"
        }
        
    # 2. User answered Q2 -> Ask Q3
    if Q2_TEXT in last_ai:
        chosen_concern = "Khác"
        concern_map = {
            "mụn": "Mụn",
            "thâm": "Thâm",
            "đỏ kích ứng": "Đỏ kích ứng",
            "khô bong tróc": "Khô bong tróc",
            "lão hóa": "Lão hóa",
            "lỗ chân lông": "Lỗ chân lông"
        }
        for kw, val in concern_map.items():
            if kw in msg_lower:
                chosen_concern = val
                break
                
        return {
            "completed": False,
            "answer": f"Ghi nhận vấn đề da: **{chosen_concern}**.\n\n**{Q3_TEXT}**\n\n{Q3_OPTIONS}"
        }
        
    # 3. User answered Q3 -> Ask Q4
    if Q3_TEXT in last_ai:
        chosen_sens = "Không rõ"
        sens_map = {
            "rất dễ": "Rất dễ kích ứng",
            "khá dễ": "Khá dễ kích ứng",
            "bình thường": "Bình thường"
        }
        for kw, val in sens_map.items():
            if kw in msg_lower:
                chosen_sens = val
                break
                
        return {
            "completed": False,
            "answer": f"Ghi nhận độ nhạy cảm: **{chosen_sens}**.\n\n**{Q4_TEXT}**\n\n{Q4_OPTIONS}"
        }
        
    # 4. User answered Q4 -> Save to MongoDB and Complete
    if Q4_TEXT in last_ai:
        budget_val = parse_budget_value(message)
        
        # Scrape history to build the full profile
        parsed_type = "Không chắc"
        parsed_concern = "Khác"
        parsed_sens = "Không rõ"
        
        for h in reversed(history or []):
            h_str = str(h).lower()
            if "ghi nhận loại da:" in h_str:
                for t in ["da dầu", "da khô", "da hỗn hợp", "da thường"]:
                    if t in h_str:
                        parsed_type = t.title()
                        break
            elif "ghi nhận vấn đề da:" in h_str:
                for c in ["mụn", "thâm", "đỏ kích ứng", "khô bong tróc", "lão hóa", "lỗ chân lông"]:
                    if c in h_str:
                        parsed_concern = c.title()
                        break
            elif "ghi nhận độ nhạy cảm:" in h_str:
                for s in ["rất dễ kích ứng", "khá dễ kích ứng", "bình thường"]:
                    if s in h_str:
                        parsed_sens = s.title()
                        break
                        
        profile_data = {
            "loai_da": parsed_type,
            "concerns": [parsed_concern],
            "sensitivity": parsed_sens,
            "budget": budget_val
        }
        
        # Save to DB if logged in
        saved_db = False
        if email:
            saved_db = save_user_profile(email, profile_data, source="chat_survey")
            
        success_msg = f"🎉 **Chúc mừng! Ngọc Vi đã lưu hồ sơ da của bạn thành công.**\n"
        if saved_db:
            success_msg += "*(Hồ sơ đã được lưu trữ vào lịch sử phiên bản trên tài khoản của bạn)*\n\n"
        else:
            success_msg += "*(Hồ sơ hiện tại sẽ được áp dụng trực tiếp cho cuộc tư vấn này)*\n\n"
            
        success_msg += (
            f"**Tóm tắt hồ sơ của bạn:**\n"
            f"- Loại da: {parsed_type}\n"
            f"- Vấn đề quan tâm: {parsed_concern}\n"
            f"- Độ nhạy cảm: {parsed_sens}\n"
            f"- Ngân sách: {f'{budget_val:,} đ' if budget_val > 0 else 'Không giới hạn'}\n\n"
            f"Dưới đây là gợi ý sản phẩm phù hợp nhất dành cho bạn:"
        )
        
        return {
            "completed": True,
            "answer_prefix": success_msg,
            "profile_data": profile_data
        }
        
    return {"completed": False, "answer": "Có lỗi xảy ra trong quá trình khảo sát. Vui lòng thử lại."}
