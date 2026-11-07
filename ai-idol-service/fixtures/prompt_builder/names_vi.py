"""Họ/đệm/tên tiếng Việt và địa danh theo vùng (khớp kho) để sinh khách hàng giả lập."""
from __future__ import annotations

HO = ("Nguyễn", "Trần", "Lê", "Phạm", "Hoàng", "Huỳnh", "Phan", "Vũ", "Võ", "Đặng", "Bùi", "Đỗ", "Hồ", "Ngô", "Dương", "Lý")
DEM_NU = ("Thị", "Ngọc", "Thanh", "Minh", "Khánh", "Phương", "Bảo", "Thùy", "Hồng", "Mai", "Diễm", "Tú")
DEM_NAM = ("Văn", "Minh", "Quốc", "Đức", "Hoàng", "Gia", "Anh", "Tuấn", "Hữu", "Thành")
TEN_NU = ("Lan", "Hương", "Trang", "Linh", "Ngân", "Vy", "Thảo", "Nhi", "Chi", "Uyên", "My", "Hà", "Yến", "Quỳnh", "Trâm", "Giang")
TEN_NAM = ("Huy", "Khoa", "Nam", "Long", "Phúc", "Bảo", "Dũng", "Hiếu", "Kiên", "Tài", "Việt", "Đạt", "Minh", "Sơn")

# Vùng -> tỉnh/thành. Vùng quyết định kho xuất hàng (config.REGION_WAREHOUSE).
PROVINCES = {
    "south": ("TP. Hồ Chí Minh", "Bình Dương", "Đồng Nai", "Cần Thơ", "Long An"),
    "north": ("Hà Nội", "Hải Phòng", "Bắc Ninh", "Quảng Ninh", "Hải Dương"),
    "central": ("Đà Nẵng", "Thừa Thiên Huế", "Quảng Nam", "Khánh Hòa", "Nghệ An"),
}
STREETS = ("Lê Lợi", "Nguyễn Huệ", "Trần Hưng Đạo", "Hai Bà Trưng", "Phan Chu Trinh", "Lý Thường Kiệt",
           "Điện Biên Phủ", "Nguyễn Trãi", "Võ Văn Tần", "Hoàng Diệu", "Pasteur", "Cách Mạng Tháng Tám")
