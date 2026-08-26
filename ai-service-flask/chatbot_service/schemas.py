from __future__ import annotations
import re
from typing import Optional, List, Literal
from pydantic import BaseModel, Field



ALLOWED_LOAI_DA = {
    "Da dầu/Hỗn hợp dầu", "Da thường/Mọi loại da", "Da nhạy cảm",
    "Da khô/Hỗn hợp khô", "Da khô", "Da mụn", "Da hỗn hợp thiên dầu", "Unknown",
}

ALLOWED_LOAI_SP = {
    "Sữa Rửa Mặt", "Tẩy Trang Mặt", "Toner / Nước Cân Bằng Da",
    "Serum / Tinh Chất", "Kem / Gel / Dầu Dưỡng", "Lotion / Sữa Dưỡng",
    "Mặt Nạ Giấy", "Mặt Nạ Rửa", "Mặt Nạ Ngủ", "Chống Nắng Da Mặt",
    "Tẩy Tế Bào Chết Da Mặt", "Serum / Kem Dưỡng Mắt",
    "Hỗ Trợ Trị Mụn", "Xịt Khoáng", "Dưỡng Thể", "Sữa Tắm",
    "Dầu Gội", "Dầu Xả", "Mặt Nạ Lột", "Bộ Chăm Sóc Da Mặt",
    "Son Dưỡng Môi", "Son Kem / Tint", "Khử Mùi",
    "Tẩy Tế Bào Chết Body", "Mini / Sample",
}

ALLOWED_TINH_TRANG_DA = {
    "mụn", "thâm", "nhăn", "đỏ kích ứng", "bong tróc",
    "lỗ chân lông to", "sạm màu", "quầng thâm mắt", "da bong",
}

ALLOWED_XUAT_XU = {
    "Hàn Quốc", "Nhật Bản", "Pháp", "Mỹ", "Việt Nam", "Úc",
    "Đức", "Anh", "Thái Lan", "Singapore", "Trung Quốc", "Đài Loan",
}

ALLOWED_MUC_GIA  = {"binh_dan", "tam_trung", "cao_cap"}
ALLOWED_BUOI_DUNG = {"sang", "toi", "ca_hai"}


SYNONYMS_SP: dict[str, str] = {
    "kem chống nắng": "Chống Nắng Da Mặt", "chống nắng": "Chống Nắng Da Mặt",
    "sunscreen": "Chống Nắng Da Mặt",      "chong nang": "Chống Nắng Da Mặt",
    "kcn": "Chống Nắng Da Mặt",
    "sữa rửa mặt": "Sữa Rửa Mặt",          "sua rua mat": "Sữa Rửa Mặt",
    "srm": "Sữa Rửa Mặt",
    "tẩy trang": "Tẩy Trang Mặt",           "tay trang": "Tẩy Trang Mặt",
    "nước hoa hồng": "Toner / Nước Cân Bằng Da",
    "nuoc hoa hong": "Toner / Nước Cân Bằng Da",
    "toner": "Toner / Nước Cân Bằng Da",
    "serum": "Serum / Tinh Chất",           "tinh chất": "Serum / Tinh Chất",
    "tinh chat": "Serum / Tinh Chất",       "ampoule": "Serum / Tinh Chất",
    "kem dưỡng": "Kem / Gel / Dầu Dưỡng",  "kem duong": "Kem / Gel / Dầu Dưỡng",
    "gel dưỡng": "Kem / Gel / Dầu Dưỡng",
    "mặt nạ": "Mặt Nạ Giấy",               "mat na": "Mặt Nạ Giấy",
    "trị mụn": "Hỗ Trợ Trị Mụn",           "tri mun": "Hỗ Trợ Trị Mụn",
    "chấm mụn": "Hỗ Trợ Trị Mụn",
}

SYNONYMS_DA: dict[str, str] = {
    "da dầu": "Da dầu/Hỗn hợp dầu",        "da dau": "Da dầu/Hỗn hợp dầu",
    "hỗn hợp dầu": "Da dầu/Hỗn hợp dầu",   "nhờn": "Da dầu/Hỗn hợp dầu",
    "da nhạy cảm": "Da nhạy cảm",           "da nhay cam": "Da nhạy cảm",
    "kích ứng": "Da nhạy cảm",
    "da khô": "Da khô/Hỗn hợp khô",         "da kho": "Da khô/Hỗn hợp khô",
    "da mụn": "Da mụn",                      "da mun": "Da mụn",
    "da thường": "Da thường/Mọi loại da",   "da thuong": "Da thường/Mọi loại da",
}



class PhanTichYeuCau(BaseModel):
    loai_da: Optional[Literal[
        "Da dầu/Hỗn hợp dầu", "Da thường/Mọi loại da", "Da nhạy cảm",
        "Da khô/Hỗn hợp khô", "Da khô", "Da mụn", "Da hỗn hợp thiên dầu", "Unknown"
    ]] = Field(default=None)

    tinh_trang_da: Optional[List[Literal[
        "mụn", "thâm", "nhăn", "đỏ kích ứng", "bong tróc",
        "lỗ chân lông to", "sạm màu", "quầng thâm mắt", "da bong"
    ]]] = Field(default=None)

    loai_san_pham: Optional[Literal[
        "Sữa Rửa Mặt", "Tẩy Trang Mặt", "Toner / Nước Cân Bằng Da",
        "Serum / Tinh Chất", "Kem / Gel / Dầu Dưỡng", "Lotion / Sữa Dưỡng",
        "Mặt Nạ Giấy", "Mặt Nạ Rửa", "Mặt Nạ Ngủ", "Chống Nắng Da Mặt",
        "Tẩy Tế Bào Chết Da Mặt", "Serum / Kem Dưỡng Mắt",
        "Hỗ Trợ Trị Mụn", "Xịt Khoáng", "Dưỡng Thể", "Sữa Tắm",
        "Dầu Gội", "Dầu Xả", "Mặt Nạ Lột", "Bộ Chăm Sóc Da Mặt",
        "Son Dưỡng Môi", "Son Kem / Tint", "Khử Mùi",
        "Tẩy Tế Bào Chết Body", "Mini / Sample"
    ]] = Field(default=None)

    thanh_phan_yeu_cau:  Optional[List[str]] = Field(default=None)
    thanh_phan_can_tranh: Optional[List[str]] = Field(default=None)
    thuong_hieu: Optional[str] = Field(default=None)

    xuat_xu: Optional[Literal[
        "Hàn Quốc", "Nhật Bản", "Pháp", "Mỹ", "Việt Nam", "Úc",
        "Đức", "Anh", "Thái Lan", "Singapore", "Trung Quốc", "Đài Loan"
    ]] = Field(default=None)

    muc_gia:      Optional[Literal["binh_dan", "tam_trung", "cao_cap"]] = Field(default=None)
    gia_cu_the:   Optional[str] = Field(default=None)
    buoi_dung:    Optional[Literal["sang", "toi", "ca_hai"]] = Field(default=None)
    so_luong_goi_y: int  = Field(default=3)
    tu_khoa_ngu_nghia: str = Field(default="")
    is_routine: bool = Field(
        default=False,
        description="True when the customer wants advice on a full multi-step skincare routine.",
    )



def dict_to_yc(d: dict) -> PhanTichYeuCau:
    """
    Convert a dict from an LLM JSON response into a PhanTichYeuCau.
    Normalizes and validates each field to prevent ValidationError.
    """
    safe: dict = {}
    allowed_fields = set(PhanTichYeuCau.model_fields.keys())

    for k, v in d.items():
        if k not in allowed_fields or v is None:
            continue

        if k == "loai_da":
            v_str = str(v).strip()
            if v_str in ALLOWED_LOAI_DA:
                safe[k] = v_str
            else:
                matched = next(
                    (val for key, val in SYNONYMS_DA.items() if key in v_str.lower()),
                    "Unknown",
                )
                safe[k] = matched

        elif k == "loai_san_pham":
            v_str = str(v).strip()
            if v_str in ALLOWED_LOAI_SP:
                safe[k] = v_str
            else:
                matched = next(
                    (val for key, val in SYNONYMS_SP.items() if key in v_str.lower()),
                    None,
                )
                safe[k] = matched

        elif k == "tinh_trang_da" and isinstance(v, list):
            _norm = {
                "mun": "mụn", "tham": "thâm", "nhan": "nhăn",
                "lo chan long to": "lỗ chân lông to", "sam mau": "sạm màu",
                "bong troc": "bong tróc",
            }
            clean = [
                _norm.get(str(i).strip().lower(), str(i).strip().lower())
                for i in v
                if _norm.get(str(i).strip().lower(), str(i).strip().lower()) in ALLOWED_TINH_TRANG_DA
            ]
            safe[k] = clean or None

        elif k == "xuat_xu":
            v_lower = str(v).lower()
            mapping = [
                (["korea", "hàn"], "Hàn Quốc"),
                (["japan", "nhật"], "Nhật Bản"),
                (["france", "pháp"], "Pháp"),
                (["usa", "mỹ"], "Mỹ"),
                (["viet"], "Việt Nam"),
            ]
            matched = next(
                (out for keys, out in mapping if any(k in v_lower for k in keys)),
                str(v).strip() if str(v).strip() in ALLOWED_XUAT_XU else None,
            )
            safe[k] = matched

        elif k == "muc_gia":
            safe[k] = str(v).strip().lower() if str(v).strip().lower() in ALLOWED_MUC_GIA else None

        elif k == "buoi_dung":
            safe[k] = str(v).strip().lower() if str(v).strip().lower() in ALLOWED_BUOI_DUNG else None

        elif k == "is_routine":
            safe[k] = v if isinstance(v, bool) else str(v).strip().lower() in ("true", "1", "yes")

        else:
            safe[k] = v

    safe.setdefault("tu_khoa_ngu_nghia", "")

    try:
        return PhanTichYeuCau(**safe)
    except Exception as e:
        return PhanTichYeuCau(tu_khoa_ngu_nghia=safe.get("tu_khoa_ngu_nghia", ""), so_luong_goi_y=3)
