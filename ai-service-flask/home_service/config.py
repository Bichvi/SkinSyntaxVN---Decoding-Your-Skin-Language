"""Runtime configuration for the homepage graph recommend API (env driven)."""

from __future__ import annotations

import os


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


CONCERN_KEYS = (
    "mun", "tham_mun", "nam_tan_nhang", "lo_chan_long", "dau_thua",
    "kho_da", "kich_ung", "lao_hoa", "xin_mau",
)
SKIN_TYPE_KEYS = ("da_dau", "da_kho", "da_nhay_cam", "da_mun", "moi_loai_da")
SECTION_KEYS = ("seen", "skin", "concern")
DEFAULT_CONCERN = "tham_mun"
DEFAULT_LIMITS = {"seen": 4, "skin": 4, "concern": 8}
MIN_ITEMS = 4
MAX_PER_BRAND = 2
SEEN_WEIGHTS = (1.0, 0.8, 0.6, 0.4, 0.2)
CACHE_TTL = max(0, _int("HOME_GRAPH_CACHE_TTL", 600))
CANDIDATE_LIMIT = 60
REASON_CODES = (
    "seen_shared_ingredient", "seen_same_category", "skin_fit",
    "concern_ingredient", "concern_target",
)

MAX_SEEN_IDS = 5
MAX_CART_IDS = 50
MAX_EXCLUDE_IDS = 100
MIN_LIMIT = 4
MAX_LIMIT = 12
