# -*- coding: utf-8 -*-
import os
import re
from datetime import datetime
from pymongo import MongoClient

def get_mongodb_connection():
    """Get the pymongo database connection."""
    db_name = os.getenv("MONGO_DB_NAME", "skinsyntax")
    mongo_uri = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
    
    # Hỗ trợ tự sửa endpoint cho Windows local nếu có cổng ánh xạ ngoài
    if "27017" in mongo_uri and (os.name == 'nt' or not os.path.exists('/.dockerenv')):
        # Ở môi trường host Windows, MongoDB container map qua cổng 27018
        # Nếu docker-compose map "27018:27017", trên host phải dùng 27018
        # chatbot_flask chạy ngoài Docker (nếu có) sẽ cần cổng 27018
        pass

    client = MongoClient(mongo_uri)
    return client[db_name]

def save_profile_history(email: str, profile_data: dict, source: str = "chat_survey") -> int:
    """
    Save the skin profile snapshot to skin_profile_history for versioning/debugging.
    Returns: the inserted version number (int).
    """
    if not email:
        return 0
    try:
        db = get_mongodb_connection()
        
        # Calculate the next version number
        history_col = db.skin_profile_history
        version_count = history_col.count_documents({"email": email})
        next_version = version_count + 1
        
        history_doc = {
            "email": email,
            "skin_type": profile_data.get("loai_da"),
            "concerns": profile_data.get("concerns"),
            "sensitivity": profile_data.get("sensitivity"),
            "budget": profile_data.get("budget"),
            "updated_at": datetime.utcnow(),
            "source": source,
            "version": next_version
        }
        history_col.insert_one(history_doc)
        print(f"[OK] Saved profile version {next_version} to skin_profile_history for {email}")
        return next_version
    except Exception as e:
        print(f"[ERROR] save_profile_history error: {e}")
        return 0

def save_user_profile(email: str, profile_data: dict, source: str = "chat_survey") -> bool:
    """
    Update the customer's skin profile in the database:
    - Overwrite 'khach_hang' collection (matches PHP BFF schema).
    - Overwrite 'skin_profile' collection (matches PHP BFF schema).
    - Save snapshot into 'skin_profile_history' to track versions.
    """
    if not email:
        return False
    try:
        db = get_mongodb_connection()
        kh = db.khach_hang.find_one({"email": email})
        
        sensitivity = profile_data.get("sensitivity")
        concerns = profile_data.get("concerns")
        budget = profile_data.get("budget")
        loai_da = profile_data.get("loai_da")
        
        update_fields = {
            "updated_at": datetime.utcnow()
        }
        
        if sensitivity:
            # Map friendly sensitivity representation
            update_fields["muc_do_nhay_cam"] = sensitivity
            
        if concerns:
            # Convert list to comma-separated string for khach_hang compatibility
            if isinstance(concerns, list):
                update_fields["van_de_da"] = ", ".join(concerns)
            else:
                update_fields["van_de_da"] = str(concerns)
                
        if budget is not None:
            update_fields["ngan_sach"] = int(budget)
            
        if loai_da and loai_da.lower() != "không chắc":
            # Sync with loai_da reference collection (auto-increment ma_loai_da)
            ld = db.loai_da.find_one({"ten_loai_da": re.compile(f"^{re.escape(loai_da)}$", re.I)})
            ma_loai = ld["ma_loai_da"] if ld else None
            if not ma_loai:
                last_ld = db.loai_da.find_one({}, sort=[("ma_loai_da", -1)])
                ma_loai = (last_ld["ma_loai_da"] + 1) if last_ld else 1
                db.loai_da.insert_one({"ma_loai_da": ma_loai, "ten_loai_da": loai_da})
                
            update_fields["tinh_trang_dac_biet"] = f"loaida:{ma_loai}"
            
        # Update khach_hang document
        if kh:
            db.khach_hang.update_one({"_id": kh["_id"]}, {"$set": update_fields})
            print(f"[OK] Updated khach_hang profile for {email}")
        else:
            # Create a brand new customer entry if it doesn't exist
            last_kh = db.khach_hang.find_one({}, sort=[("ma_kh", -1)])
            new_ma_kh = (last_kh["ma_kh"] + 1) if last_kh else 1
            db.khach_hang.insert_one({
                "ma_kh": new_ma_kh,
                "email": email,
                "ho_ten": email.split('@')[0],
                **update_fields,
                "created_at": datetime.utcnow()
            })
            print(f"[OK] Created new khach_hang profile for {email}")
            
        # Update skin_profile document
        sp = db.skin_profile.find_one({"email": email})
        concerns_list = []
        if concerns:
            if isinstance(concerns, list):
                concerns_list = concerns
            else:
                concerns_list = [c.strip() for c in concerns.split(',') if c.strip()]
                
        sp_data = {
            "email": email,
            "loai_da": loai_da if loai_da else (kh.get("loai_da") if kh else ""),
            "tinh_trang_da": concerns_list,
            "updated_at": datetime.utcnow()
        }
        
        if sp:
            db.skin_profile.update_one({"_id": sp["_id"]}, {"$set": sp_data})
        else:
            db.skin_profile.insert_one(sp_data)
            
        # Save snapshot version history
        save_profile_history(email, profile_data, source=source)
        return True
    except Exception as e:
        print(f"[ERROR] save_user_profile error: {e}")
        return False
