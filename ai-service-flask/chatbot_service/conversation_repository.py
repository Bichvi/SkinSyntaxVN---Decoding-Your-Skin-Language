"""Mongo conversation persistence, isolated from HTTP and model code."""
from datetime import datetime
import os
from bson.objectid import ObjectId
from .profile_service import get_mongodb_connection

def get_conversation_by_id(conv_id: str, email: str) -> dict | None:
    try:
        db = get_mongodb_connection()
        conv = db.chat_conversations.find_one({"_id": ObjectId(conv_id)})
        if conv and conv.get("user_email") == email:
            return conv
    except Exception as e:
        print(f"[ERROR] get_conversation_by_id: {e}")
    return None


def create_new_conversation(email: str, first_message: str) -> str:
    try:
        db = get_mongodb_connection()
        # Trích xuất tiêu đề tự động thông minh (cắt tối đa 50 ký tự không làm nát từ)
        title = first_message.strip()
        if len(title) > 50:
            title = title[:50]
            last_space = title.rfind(' ')
            if last_space > 20:
                title = title[:last_space]
            title += "..."
        if not title:
            title = "Cuộc trò chuyện mới"
            
        doc = {
            "user_email": email,
            "title": title,
            "messages": [],
            "message_count": 0,
            "last_message_preview": "",
            "created_at": datetime.utcnow(),
            "updated_at": datetime.utcnow()
        }
        res = db.chat_conversations.insert_one(doc)
        return str(res.inserted_id)
    except Exception as e:
        print(f"[ERROR] create_new_conversation: {e}")
        raise e


def save_chat_messages(conv_id: str, email: str, user_text: str, assistant_text: str, products: list = None, conflicts: list = None):
    try:
        db = get_mongodb_connection()
        now = datetime.utcnow()
        user_msg = {
            "role": "user",
            "content": user_text,
            "timestamp": now
        }
        assistant_msg = {
            "role": "assistant",
            "content": assistant_text,
            "timestamp": now,
            "products": products or [],
            "conflicts": conflicts or []
        }
        
        preview = assistant_text[:100] + "..." if len(assistant_text) > 100 else assistant_text
        db.chat_conversations.update_one(
            {"_id": ObjectId(conv_id), "user_email": email},
            {
                "$push": {"messages": {"$each": [user_msg, assistant_msg]}},
                "$inc": {"message_count": 2},
                "$set": {
                    "last_message_preview": preview,
                    "updated_at": now
                }
            }
        )
    except Exception as e:
        print(f"[ERROR] save_chat_messages: {e}")


def fetch_user_profile_from_mongo(email: str = "", user_id=None) -> dict:
    profile = {}
    try:
        from pymongo import MongoClient
        db_name = os.getenv("MONGO_DB_NAME", "skinsyntax")
        mongo_uri = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
        db = MongoClient(mongo_uri)[db_name]
        
        user_query = {}
        if email:
            user_query = {"email": email}
        elif user_id:
            try:
                user_query = {"$or": [{"ma_kh": int(user_id)}, {"ma_kh": str(user_id)}]}
            except Exception:
                user_query = {"ma_kh": str(user_id)}
                
        if user_query:
            kh = db.khach_hang.find_one(user_query) or db.tai_khoan.find_one(user_query) or {}
            if kh:
                profile["display_name"] = kh.get("ho_ten") or kh.get("ten") or "bạn"
                profile["skin_type"] = kh.get("loai_da") or ""
                profile["concerns"] = kh.get("van_de_da") or []
                profile["avoid_ingredients"] = kh.get("thanh_phan_tranh") or []
                profile["budget"] = kh.get("ngan_sach")
                
                sp = db.skin_profile.find_one({"email": email or kh.get("email")})
                if sp and sp.get("loai_da"):
                    profile["skin_type"] = sp.get("loai_da")
                    if sp.get("tinh_trang_da"):
                        profile["concerns"] = sp.get("tinh_trang_da")
    except Exception as e:
        print(f"[WARN] fetch_user_profile_from_mongo error: {e}")
        
    return profile
