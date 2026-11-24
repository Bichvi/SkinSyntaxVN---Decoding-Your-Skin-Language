# -*- coding: utf-8 -*-
"""Wireframe giao diện (mục 3.7) – kiểu khung thiết kế Figma độ trung thực thấp."""
import os, sys
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch, Rectangle, Circle, Polygon

plt.rcParams["font.family"] = ["Arial", "DejaVu Sans"]
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "figs")
LINE, FILL, TXT, MUTED = "#9aa0a6", "#f1f3f4", "#202124", "#80868b"
ACC, ACC_L = "#2e7d4f", "#e6f2ea"
WARN, WARN_L = "#c5221f", "#fce8e6"


class W:
    def __init__(self, w, h, scale=0.6):
        self.w, self.h = w, h
        self.fig = plt.figure(figsize=(w * scale, h * scale), dpi=200)
        self.ax = self.fig.add_axes([0, 0, 1, 1])
        self.ax.set_xlim(0, w); self.ax.set_ylim(0, h); self.ax.axis("off")
        # khung màn hình
        self.box(0.1, 0.1, w - 0.2, h - 0.2, fc="white", ec="#5f6368", lw=1.2, r=0.15)

    def box(self, x, y, w, h, fc="white", ec=LINE, lw=0.9, r=0.08, ls="-"):
        self.ax.add_patch(FancyBboxPatch((x + r, y + r), w - 2 * r, h - 2 * r, boxstyle=f"round,pad={r}",
                                         fc=fc, ec=ec, lw=lw, ls=ls))

    def t(self, x, y, s, size=8, color=TXT, weight="normal", ha="left", va="center", **kw):
        self.ax.text(x, y, s, fontsize=size, color=color, weight=weight, ha=ha, va=va, **kw)

    def img(self, x, y, w, h, label=None):
        self.ax.add_patch(Rectangle((x, y), w, h, fc=FILL, ec=LINE, lw=0.9))
        self.ax.plot([x, x + w], [y, y + h], color=LINE, lw=0.7)
        self.ax.plot([x, x + w], [y + h, y], color=LINE, lw=0.7)
        if label:
            self.t(x + w / 2, y + h / 2, label, size=7, color=MUTED, ha="center",
                   bbox=dict(fc=FILL, ec="none", pad=1.5))

    def lines(self, x, y, w, n=3, gap=0.22, last=0.6):
        for i in range(n):
            ww = w * (last if i == n - 1 else 1)
            self.ax.add_patch(Rectangle((x, y - i * gap - 0.05), ww, 0.09, fc="#dadce0", ec="none"))

    def btn(self, x, y, w, h, s, primary=False, size=7.5, color=None):
        fc = color or (ACC if primary else "white")
        self.box(x, y, w, h, fc=fc, ec=fc if primary or color else LINE, r=0.06)
        self.t(x + w / 2, y + h / 2, s, size=size, color="white" if primary or color else TXT, ha="center",
               weight="bold" if primary else "normal")

    def chip(self, x, y, s, fc=FILL, ec=LINE, color=TXT, size=7):
        w = 0.3 + len(s) * 0.095 * size / 7
        self.box(x, y, w, 0.3, fc=fc, ec=ec, r=0.1)
        self.t(x + w / 2, y + 0.15, s, size=size, color=color, ha="center")
        return x + w + 0.12

    def note(self, x, y, s, tx, ty):
        """Chú thích thiết kế: số trong vòng tròn + đường dẫn."""
        self.ax.add_patch(Circle((x, y), 0.17, fc="#f9ab00", ec="none", zorder=5))
        self.t(x, y, s, size=7, weight="bold", ha="center", color="white", zorder=6)
        if tx is not None:
            self.ax.plot([x, tx], [y, ty], color="#f9ab00", lw=0.9, zorder=4)

    def header(self, y=None):
        y = self.h - 0.95 if y is None else y
        self.ax.add_patch(Rectangle((0.1, y), self.w - 0.2, 0.85, fc="#fafafa", ec=LINE, lw=0.8))
        self.ax.add_patch(Circle((0.6, y + 0.42), 0.25, fc=FILL, ec=LINE))
        self.t(1.0, y + 0.42, "SkinSyntax", size=10, weight="bold")
        self.box(3.0, y + 0.18, self.w * 0.38, 0.5, fc="white", r=0.1)
        self.t(3.2, y + 0.43, "Tìm sản phẩm, thương hiệu, vấn đề da...", size=7.5, color=MUTED)
        x = self.w - 4.6
        for s in ["Gợi ý routine", "Tài khoản", "Giỏ hàng"]:
            self.btn(x, y + 0.2, 1.4, 0.46, s); x += 1.5

    def save(self, k):
        self.fig.savefig(os.path.join(OUT, k + ".png"), dpi=200, facecolor="white")
        plt.close(self.fig)
        print("saved", k)


def fig_3_18():
    d = W(16, 11.6)
    d.header()
    # cột trái: ảnh
    d.img(0.5, 5.6, 5.2, 4.6, "Ảnh sản phẩm")
    for i in range(4):
        d.img(0.5 + i * 1.35, 4.65, 1.15, 0.8)
    # cột phải: thông tin
    X = 6.2
    d.t(X, 10.35, "THƯƠNG HIỆU", size=7, color=MUTED)
    d.t(X, 9.95, "Tên sản phẩm", size=13, weight="bold")
    d.t(X, 9.5, "★ 4.8  |  120 đánh giá  |  Chính hãng", size=7.5, color=MUTED)
    d.box(X, 8.55, 9.2, 0.75, fc=FILL)
    d.t(X + 0.25, 8.92, "379.000 đ", size=12, weight="bold")
    d.t(X + 2.2, 8.92, "540.000 đ", size=8, color=MUTED)
    d.chip(X + 8.0, 8.77, "-30%", fc=WARN, ec=WARN, color="white")
    x = X
    for s in ["Dung tích 35g", "Xuất xứ Nhật Bản", "Loại da Dầu/Hỗn hợp"]:
        x = d.chip(x, 8.0, s)
    # ô vì sao phù hợp
    d.box(X, 6.0, 9.2, 1.75, fc=ACC_L, ec=ACC, lw=1.2)
    d.t(X + 0.25, 7.45, "Vì sao phù hợp với bạn", size=8.5, weight="bold", color=ACC)
    d.t(X + 0.25, 7.05, "Khớp loại da dầu · Hỗ trợ mụn, dầu thừa", size=8)
    x = X + 0.25
    for s in ["Niacinamide", "BHA", "Kẽm"]:
        x = d.chip(x, 6.45, s, fc="white", ec=ACC, color=ACC)
    d.t(X + 4.3, 6.6, "Bấm để xem chi tiết lý do", size=7, color=MUTED, style="italic")
    d.box(X, 5.15, 1.0, 0.6)
    d.t(X + 0.5, 5.45, "1", size=8, ha="center")
    d.btn(X + 1.15, 5.15, 3.6, 0.6, "+ Thêm giỏ")
    d.btn(X + 4.9, 5.15, 4.3, 0.6, "Mua ngay", primary=True)
    # hàng dưới
    d.box(0.5, 1.45, 7.3, 2.95)
    d.t(0.75, 4.1, "Thành phần", size=9, weight="bold")
    x = 0.75
    for s in ["Mô tả", "Thành phần", "HDSD", "Đánh giá", "Hỏi đáp"]:
        x = d.chip(x, 3.45, s, fc=ACC if s == "Thành phần" else FILL, ec=ACC if s == "Thành phần" else LINE,
                   color="white" if s == "Thành phần" else TXT)
    d.lines(0.75, 3.0, 6.7, n=5)
    d.box(8.2, 1.45, 7.3, 2.95)
    d.t(8.45, 4.1, "Video giới thiệu của Syna", size=9, weight="bold")
    d.ax.add_patch(Rectangle((8.45, 1.7), 6.8, 2.1, fc="#3c4043", ec="none"))
    d.ax.add_patch(Polygon([(11.6, 2.35), (11.6, 3.15), (12.25, 2.75)], fc="white", ec="none"))
    d.t(8.6, 1.9, "0:00 / 0:45", size=6.5, color="white")
    d.t(0.5, 1.05, "Sản phẩm tương tự", size=9, weight="bold")
    for i in range(6):
        d.img(0.5 + i * 2.55, 0.3, 2.35, 0.55)
    d.note(5.95, 7.4, "1", X, 6.9)
    d.note(7.95, 4.65, "2", 8.2, 4.1)
    d.note(0.25, 1.05, "3", None, None)
    d.save("3.18")


def fig_3_19():
    d = W(16, 11.2)
    d.header()
    d.t(0.5, 9.75, "Giỏ hàng của bạn", size=14, weight="bold")
    d.t(10.6, 9.75, "2 sản phẩm", size=8, color=MUTED, ha="right")
    d.box(0.5, 7.0, 10.1, 2.35)
    cols = [("SẢN PHẨM", 0.75), ("SỐ LƯỢNG", 5.6), ("ĐƠN GIÁ", 7.6), ("TỔNG", 9.0)]
    for s, x in cols:
        d.t(x, 9.05, s, size=7, color=MUTED, weight="bold")
    for i, name in enumerate(["Kem dưỡng Retinol 1.0%", "Dung dịch BHA 2%"]):
        y = 8.25 - i * 0.82
        d.ax.plot([0.6, 10.5], [y + 0.45, y + 0.45], color="#e8eaed", lw=0.8)
        d.img(0.75, y - 0.3, 0.6, 0.6)
        d.t(1.55, y + 0.1, name, size=8)
        d.t(1.55, y - 0.18, "Thương hiệu", size=6.5, color=MUTED)
        d.box(5.55, y - 0.2, 1.3, 0.4); d.t(6.2, y, "–   1   +", size=7.5, ha="center")
        d.t(7.6, y, "000.000 đ", size=7.5); d.t(9.0, y, "000.000 đ", size=7.5, weight="bold")
        d.t(10.25, y, "✕", size=8, color=WARN, ha="center")
    # khối cảnh báo
    d.box(0.5, 4.3, 10.1, 2.45)
    d.t(0.75, 6.45, "Lưu ý khi dùng chung hoạt chất", size=9.5, weight="bold")
    d.box(0.75, 4.55, 9.6, 1.6, fc=WARN_L, ec=WARN, lw=1.1)
    d.chip(0.95, 5.65, "⚠ Nên tránh dùng cùng nhau", fc=WARN, ec=WARN, color="white")
    d.t(4.4, 5.8, "Salicylic Acid + Retinol", size=8)
    d.t(0.95, 5.3, "Dung dịch BHA 2%  +  Kem dưỡng Retinol 1.0%", size=8, color=WARN)
    d.lines(0.95, 4.95, 9.0, n=2, gap=0.25, last=0.55)
    # routine
    d.box(0.5, 0.35, 10.1, 3.7)
    d.t(0.75, 3.75, "Hoàn thiện routine của bạn", size=9.5, weight="bold")
    x = 0.75
    for s, ok in [("Sữa rửa mặt", False), ("Toner", False), ("Serum", True), ("Kem dưỡng ẩm", False), ("Chống nắng", False)]:
        x = d.chip(x, 3.15, ("✓ " if ok else "+ ") + s, fc=ACC_L if ok else "white", ec=ACC if ok else "#f9ab00",
                   color=ACC if ok else TXT)
    d.t(0.75, 2.75, "Còn thiếu: Kem dưỡng ẩm", size=8.5, weight="bold")
    for i in range(3):
        x0 = 0.75 + i * 3.25
        d.box(x0, 0.55, 3.0, 1.95)
        d.img(x0 + 0.15, 1.2, 1.0, 1.15)
        d.t(x0 + 1.3, 2.1, "Tên sản phẩm", size=7.5)
        d.t(x0 + 1.3, 1.75, "000.000 đ", size=7.5, weight="bold")
        d.btn(x0 + 1.3, 0.75, 1.55, 0.4, "Thêm vào giỏ", size=6.5)
    # tóm tắt
    d.box(11.0, 5.6, 4.5, 3.75)
    d.t(11.25, 9.0, "Tóm tắt đơn hàng", size=10, weight="bold")
    for i, (a, b) in enumerate([("Tạm tính", "000.000 đ"), ("Phí vận chuyển", "30.000 đ")]):
        d.t(11.25, 8.4 - i * 0.45, a, size=8); d.t(15.25, 8.4 - i * 0.45, b, size=8, ha="right")
    d.ax.plot([11.25, 15.25], [7.25, 7.25], color="#e8eaed")
    d.t(11.25, 6.95, "Tổng cộng", size=9.5, weight="bold"); d.t(15.25, 6.95, "000.000 đ", size=9.5, weight="bold", color=ACC, ha="right")
    d.btn(11.25, 5.85, 4.0, 0.6, "Thanh toán", primary=True)
    d.note(0.3, 5.5, "1", 0.75, 5.5)
    d.note(0.3, 2.75, "2", 0.75, 2.75)
    d.save("3.19")


def fig_3_20():
    d = W(16, 11.2)
    # thanh bên hội thoại
    d.ax.add_patch(Rectangle((0.1, 0.1), 3.4, 11.0, fc="#202124", ec="none"))
    d.t(0.4, 10.6, "CUỘC HỘI THOẠI", size=8.5, color="#8ab4f8", weight="bold")
    d.box(0.4, 9.8, 2.8, 0.5, fc="#202124", ec="#5f6368", ls="--")
    d.t(1.8, 10.05, "+ Hội thoại mới", size=7.5, color="white", ha="center")
    for i in range(6):
        y = 9.2 - i * 0.85
        if i == 0:
            d.ax.add_patch(Rectangle((0.3, y - 0.35), 3.0, 0.75, fc="#303134", ec="none"))
        d.ax.add_patch(Rectangle((0.45, y + 0.05), 2.4, 0.09, fc="#9aa0a6", ec="none"))
        d.ax.add_patch(Rectangle((0.45, y - 0.2), 1.8, 0.07, fc="#5f6368", ec="none"))
    # đầu khung chat
    X = 3.8
    d.ax.add_patch(Circle((X + 0.35, 10.55), 0.3, fc=ACC_L, ec=ACC))
    d.t(X + 0.85, 10.65, "SYNA | AI SKINCARE ADVISOR", size=9, weight="bold")
    d.t(X + 0.85, 10.32, "● Đã kết nối", size=6.5, color=ACC)
    for i, s in enumerate(["Xóa", "Thu nhỏ", "✕"]):
        d.btn(13.4 + [0, 0.55, 1.75][i], 10.3, [0.5, 1.1, 0.45][i], 0.45, s, size=6.5)
    x = X
    for s in ["Phân tích giỏ hàng", "Da dầu mụn", "Thành phần", "Trợ giúp"]:
        x = d.chip(x, 9.6, s, size=7)
    # tin nhắn người dùng
    d.box(11.2, 8.75, 4.4, 0.6, fc=ACC_L, ec=ACC)
    d.t(11.4, 9.05, "Da dầu mụn, tư vấn serum giúp mình", size=7.5)
    # trả lời bot + thẻ sản phẩm
    d.ax.add_patch(Circle((X + 0.25, 7.0), 0.22, fc=ACC_L, ec=ACC))
    d.box(X + 0.6, 5.55, 6.6, 3.0)
    d.lines(X + 0.85, 8.25, 6.0, n=2, gap=0.25)
    for i in range(2):
        y = 7.1 - i * 0.78
        d.box(X + 0.85, y - 0.32, 6.1, 0.68)
        d.img(X + 0.95, y - 0.25, 0.55, 0.55)
        d.t(X + 1.65, y + 0.1, "Tên sản phẩm gợi ý", size=7.5, weight="bold")
        d.t(X + 1.65, y - 0.15, "000.000 đ", size=7)
        d.btn(X + 3.7, y - 0.22, 1.5, 0.42, "Thêm vào giỏ", primary=True, size=6.5)
        d.btn(X + 5.3, y - 0.22, 1.5, 0.42, "Xem chi tiết", size=6.5)
    # thẻ xác nhận đơn + OTP
    d.box(X + 7.6, 4.3, 4.3, 4.25, fc="#fafafa", ec=ACC, lw=1.1)
    d.t(X + 7.85, 8.25, "Xác nhận đơn hàng", size=8.5, weight="bold")
    d.lines(X + 7.85, 7.85, 3.8, n=3, gap=0.25, last=0.7)
    d.t(X + 7.85, 6.9, "Tổng: 000.000 đ  ·  COD", size=7.5, weight="bold")
    d.t(X + 7.85, 6.5, "Nhập mã OTP gửi qua email", size=7, color=MUTED)
    for i in range(6):
        d.box(X + 7.85 + i * 0.62, 5.75, 0.5, 0.55)
    d.btn(X + 7.85, 4.55, 3.8, 0.5, "Xác nhận", primary=True)
    # thẻ phân tích nhãn
    d.box(X + 0.6, 1.6, 6.6, 3.7)
    d.t(X + 0.85, 5.0, "Phân tích ảnh nhãn thành phần", size=8.5, weight="bold")
    d.t(X + 0.85, 4.62, "Đã đọc 30 thành phần", size=7.5)
    x = X + 0.85
    for s in ["Sodium Hyaluronate", "Niacinamide"]:
        x = d.chip(x, 4.05, s, fc=ACC_L, ec=ACC, color=ACC)
    d.t(X + 0.85, 3.7, "Mức phù hợp với da bạn: Phù hợp", size=7.5, color=ACC, weight="bold")
    d.lines(X + 0.85, 3.3, 6.0, n=3, gap=0.25)
    d.btn(X + 0.85, 1.8, 2.6, 0.42, "Sửa nếu đọc sai", size=6.5)
    # ô nhập
    d.ax.plot([3.6, 15.9], [1.3, 1.3], color="#e8eaed")
    d.box(X, 0.4, 10.4, 0.7)
    d.t(X + 0.25, 0.75, "Hỏi Syna về thành phần, sản phẩm, routine...", size=7.5, color=MUTED)
    d.btn(14.4, 0.4, 1.3, 0.7, "Gửi", primary=True)
    d.note(X + 0.6, 8.85, "1", X + 0.9, 8.55)
    d.note(X + 7.35, 8.85, "2", X + 7.65, 8.55)
    d.note(X + 0.35, 5.3, "3", X + 0.6, 5.1)
    d.save("3.20")


def fig_3_21():
    d = W(16, 11.2)
    d.ax.add_patch(Rectangle((0.1, 0.1), 3.0, 11.0, fc="#fafafa", ec=LINE, lw=0.8))
    d.t(0.4, 10.6, "SkinSyntaxVN", size=9.5, weight="bold")
    for i, s in enumerate(["Dashboard", "Sản phẩm", "Kho & Lô hàng", "Đơn hàng", "Voucher", "Quản lý Live", "AI Idol Studio"]):
        y = 9.7 - i * 0.62
        if s == "AI Idol Studio":
            d.box(0.3, y - 0.25, 2.6, 0.5, fc=ACC_L, ec=ACC)
        d.ax.add_patch(Rectangle((0.45, y - 0.08), 0.16, 0.16, fc=LINE, ec="none"))
        d.t(0.8, y, s, size=7.5, weight="bold" if s == "AI Idol Studio" else "normal")
    X = 3.5
    d.t(X, 10.6, "AI Idol Studio", size=13, weight="bold")
    x = X
    for s, ok in [("Dữ liệu", 1), ("Hàng đợi", 1), ("Worker", 1), ("Kịch bản", 1), ("Syna", 1)]:
        x = d.chip(x, 9.85, "● " + s, fc=ACC_L, ec=ACC, color=ACC)
    # chế độ
    d.box(X, 8.75, 7.4, 0.8)
    d.btn(X + 0.1, 8.83, 3.55, 0.64, "Tự động – giao việc bằng một câu", primary=True, size=7)
    d.t(X + 5.55, 9.15, "Thủ công – chọn từng bước", size=7, ha="center")
    # form
    d.box(X, 3.9, 7.4, 4.6)
    d.t(X + 0.25, 8.2, "Giao việc cho Syna", size=9.5, weight="bold")
    d.box(X + 0.25, 6.6, 6.9, 1.3)
    d.t(X + 0.4, 7.65, "Ví dụ: Làm video cho 5 kem chống nắng bán chạy nhất 30 ngày", size=7, color=MUTED)
    x = X + 0.25
    for s in ["Top bán chạy", "Xả lô cận hạn", "Theo mã sản phẩm"]:
        x = d.chip(x, 6.05, s)
    d.t(X + 0.25, 5.6, "Chế độ duyệt", size=8, weight="bold")
    for i, s in enumerate(["Tự duyệt và đăng", "Chờ quản trị viên duyệt"]):
        cx = X + 0.4 + i * 3.4
        d.ax.add_patch(Circle((cx, 5.2), 0.12, fc=ACC if i == 1 else "white", ec=ACC))
        d.t(cx + 0.25, 5.2, s, size=7.5)
    d.btn(X + 0.25, 4.15, 6.9, 0.6, "Giao việc", primary=True)
    # tiến độ
    d.box(X, 0.35, 7.4, 3.3)
    d.t(X + 0.25, 3.35, "Tiến độ công việc", size=9.5, weight="bold")
    steps = ["Dữ liệu", "RAG", "Kịch bản", "Kiểm tra", "Duyệt", "Giọng đọc", "Dựng video", "Trộn âm", "Tải lên"]
    for i, s in enumerate(steps):
        cx = X + 0.55 + i * 0.79
        done, cur = i < 4, i == 4
        if i:
            d.ax.plot([cx - 0.79 + 0.17, cx - 0.17], [2.55, 2.55], color=ACC if i <= 4 else LINE, lw=1.4)
        d.ax.add_patch(Circle((cx, 2.55), 0.17, fc=ACC if done else ("#f9ab00" if cur else "white"),
                              ec=ACC if done else ("#f9ab00" if cur else LINE), lw=1.2))
        if done:
            d.t(cx, 2.55, "✓", size=7, color="white", ha="center", weight="bold")
        d.t(cx, 2.1, s, size=6, ha="center", rotation=0)
    d.chip(X + 0.25, 1.05, "Đang chờ duyệt kịch bản", fc="#fef7e0", ec="#f9ab00")
    d.btn(X + 3.4, 1.0, 1.15, 0.4, "Duyệt", primary=True, size=6.5)
    d.btn(X + 4.65, 1.0, 1.25, 0.4, "Viết lại", size=6.5)
    d.btn(X + 6.0, 1.0, 1.0, 0.4, "Hủy", size=6.5)
    # xem trước
    d.box(11.2, 4.6, 4.4, 5.6)
    d.t(11.45, 9.9, "Xem trước kịch bản", size=9.5, weight="bold")
    for k in range(3):
        d.t(11.45, 9.35 - k * 1.45, f"Cảnh {k + 1}", size=7.5, weight="bold", color=ACC)
        d.lines(11.45, 9.0 - k * 1.45, 3.9, n=3, gap=0.25)
    d.box(11.2, 0.35, 4.4, 3.95)
    d.t(11.45, 4.0, "Xem trước video", size=9.5, weight="bold")
    d.ax.add_patch(Rectangle((12.2, 0.6), 2.4, 3.1, fc="#3c4043", ec="none"))
    d.ax.add_patch(Circle((13.4, 2.6), 0.45, fc="#5f6368", ec="none"))
    d.t(13.4, 1.6, "Syna", size=7, color="white", ha="center")
    d.ax.add_patch(Polygon([(13.25, 0.85), (13.25, 1.25), (13.6, 1.05)], fc="white", ec="none"))
    d.note(X - 0.2, 9.15, "1", X, 9.15)
    d.note(X - 0.2, 5.2, "2", X + 0.25, 5.2)
    d.note(X - 0.2, 2.55, "3", X + 0.38, 2.55)
    d.save("3.21")


FIGS = {"3.18": fig_3_18, "3.19": fig_3_19, "3.20": fig_3_20, "3.21": fig_3_21}
if __name__ == "__main__":
    for k in sys.argv[1:] or list(FIGS):
        FIGS[k]()
