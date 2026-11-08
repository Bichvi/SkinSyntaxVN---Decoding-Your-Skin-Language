from datetime import datetime, timezone

from chatbot_service.profile_state import determine_profile_state


def test_complete_profile_with_today_update_is_fresh():
    profile = {
        "skin_type": "Da dầu",
        "sensitivity": "Khỏe mạnh, hiếm khi",
        "budget": 500000,
        "updated_at": datetime.now(timezone.utc).isoformat(),
    }
    assert determine_profile_state("tư vấn cho tôi", profile) == "PROFILE_FRESH"
