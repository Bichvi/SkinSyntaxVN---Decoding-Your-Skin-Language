# -*- coding: utf-8 -*-
import re
from datetime import date

def parse_date_simple(date_str: str) -> date:
    """Parse YYYY-MM-DD from an ISO 8601 date string safely."""
    if not date_str:
        return None
    match = re.match(r'^(\d{4})-(\d{2})-(\d{2})', date_str)
    if match:
        try:
            return date(int(match.group(1)), int(match.group(2)), int(match.group(3)))
        except ValueError:
            pass
    return None

def calculate_days_since_update(updated_at_str: str) -> int:
    """Calculate the number of days since the last profile update."""
    if not updated_at_str:
        return 999
    dt_parsed = parse_date_simple(updated_at_str)
    if dt_parsed:
        return (date.today() - dt_parsed).days
    return 999

def detect_skin_keywords(message: str) -> dict:
    """Detect skin characteristics mentioned in the user's latest message."""
    msg_lower = message.lower()
    
    # Check for temporary/minor symptoms vs persistent/major states
    minor_dry = any(k in msg_lower for k in ["hơi khô", "bị khô vài hôm", "khô 2 hôm", "khô tạm thời", "dạo này hơi khô"])
    minor_oily = any(k in msg_lower for k in ["hơi dầu", "hơi nhờn", "đổ dầu chút", "hơi bóng nhờn"])
    
    major_dry = any(k in msg_lower for k in ["da khô", "da kho", "khô ráp bong tróc", "khô căng", "khô nứt nẻ", "chuyển sang da khô"])
    major_oily = any(k in msg_lower for k in ["da dầu", "da dau", "da nhờn", "bóng dầu toàn mặt", "quá nhiều dầu"])
    
    return {
        "minor_dry": minor_dry,
        "minor_oily": minor_oily,
        "major_dry": major_dry,
        "major_oily": major_oily
    }

def detect_profile_conflict(message: str, profile: dict) -> str:
    """
    Check if the user message conflicts with their profile skin type.
    Returns: 'CONFLICT_MAJOR', 'CONFLICT_MINOR', or None.
    """
    skin_type = (profile.get("skin_type") or "").strip().lower()
    if not skin_type or skin_type == "chưa xác định":
        return None
        
    keywords = detect_skin_keywords(message)
    
    # Conflict checks
    is_oily_profile = "dầu" in skin_type or "oily" in skin_type
    is_dry_profile = "khô" in skin_type or "dry" in skin_type
    
    if is_oily_profile:
        if keywords["major_dry"]:
            return "CONFLICT_MAJOR"
        if keywords["minor_dry"]:
            return "CONFLICT_MINOR"
            
    if is_dry_profile:
        if keywords["major_oily"]:
            return "CONFLICT_MAJOR"
        if keywords["minor_oily"]:
            return "CONFLICT_MINOR"
            
    return None

def determine_profile_state(message: str, profile: dict) -> str:
    """
    Determine the profile state using priority rules:
    1. CONFLICT_MAJOR / CONFLICT_MINOR
    2. PROFILE_MISSING (No skin type)
    3. PROFILE_PARTIAL (Missing sensitivity or budget)
    4. Profile Age (NEEDS_CONFIRMATION, OUTDATED, FRESH)
    """
    # 1. Conflict detection first
    conflict_state = detect_profile_conflict(message, profile)
    if conflict_state:
        return conflict_state

    # 2. PROFILE_MISSING
    skin_type = (profile.get("skin_type") or "").strip().lower()
    if not skin_type or skin_type == "chưa xác định":
        return "PROFILE_MISSING"

    # 3. PROFILE_PARTIAL
    sensitivity = (profile.get("sensitivity") or "").strip().lower()
    budget = profile.get("budget")
    if not sensitivity or sensitivity == "chưa xác định" or budget is None or budget <= 0:
        return "PROFILE_PARTIAL"

    # 4. Profile Age calculations
    updated_at_str = profile.get("updated_at")
    days = calculate_days_since_update(updated_at_str)
    
    if days > 30:
        return "PROFILE_OUTDATED"
    elif days >= 8:
        return "PROFILE_NEEDS_CONFIRMATION"
        
    return "PROFILE_FRESH"
