# -*- coding: utf-8 -*-
"""Hình tạm phần 2: use case, tuần tự, cây phân loại, đồ thị tri thức, kiến trúc, triển khai, ERD."""
import os, sys
from matplotlib.patches import Rectangle, FancyBboxPatch
from diagram import Diagram, ACT, DEC, NOTE, DATA, EXT, FS

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "figs")
os.makedirs(OUT, exist_ok=True)
P = lambda k: os.path.join(OUT, k + ".png")
INC, EXTD = "«include»", "«extend»"


def arc(y, ay, base, amp=1.6, span=6.0):
    """Hoành độ use case theo cung tròn quanh tác nhân để các đường nối ít cắt nhau."""
    t = min(abs(y - ay) / span, 1.0)
    return base + amp * (1 - t * t) ** 0.5 - amp


def boundary(d, x0, y0, x1, y1, title, dashed=False):
    d.ax.add_patch(Rectangle((x0, y0), x1 - x0, y1 - y0, fill=False, ec="#333333", lw=1,
                             ls=(0, (5, 3)) if dashed else "-"))
    d.ax.text(x0 + 0.15, y1 - 0.12, title, ha="left", va="top", fontsize=FS + (0 if dashed else 0.5), weight="bold")


# ======================= USE CASE =======================
def fig_3_1():
    d = Diagram(17.5, 15)
    boundary(d, 2.7, 0.3, 14.0, 14.7, "Hệ thống SkinSyntaxVN")
    boundary(d, 3.0, 10.5, 13.7, 14.15, "Gói AI tư vấn", dashed=True)
    boundary(d, 3.0, 4.35, 13.7, 10.2, "Gói Thương mại điện tử", dashed=True)
    boundary(d, 3.0, 0.6, 13.7, 4.05, "Gói AI Idol", dashed=True)
    L, R = 6.0, 10.7
    d.uc("goiy", L, 12.75, "Nhận gợi ý\ncá nhân hóa")
    d.uc("chat", L, 11.4, "Trò chuyện\nvới chatbot")
    d.uc("xd", R, 12.75, "Nhận cảnh báo\nxung đột giỏ hàng")
    d.uc("nhan", R, 11.4, "Đọc thành phần\ntừ ảnh nhãn")
    d.uc("tim", L, 9.1, "Xem, tìm kiếm\nsản phẩm")
    d.uc("ks", L, 7.85, "Làm khảo sát\nloại da")
    d.uc("dg", L, 6.6, "Đánh giá, hỏi đáp")
    d.uc("xl", L, 5.35, "Xử lý đơn hàng")
    d.uc("dk", R, 9.1, "Đăng ký, đăng nhập")
    d.uc("dh", R, 7.85, "Đặt hàng và\nthanh toán")
    d.uc("sp", R, 6.6, "Quản lý sản phẩm,\ndanh mục, tồn kho")
    d.uc("km", R, 5.35, "Quản lý khuyến mãi,\nngười dùng, báo cáo", w=3.3)
    d.uc("xv", L, 2.3, "Xem video,\nlivestream của Syna")
    d.uc("tv", R, 3.0, "Tạo video sản phẩm")
    d.uc("ls", R, 1.6, "Quản lý livestream\nvà chiến dịch")
    d.actor("kh", 1.3, 12.0, "Khách hàng")
    d.actor("vl", 1.3, 8.4, "Khách\nvãng lai")
    d.actor("nv", 1.3, 5.3, "Nhân viên")
    d.actor("qt", 1.3, 1.9, "Quản trị viên")
    d.actor("em", 15.9, 10.4, "Dịch vụ email")
    d.actor("cg", 15.9, 7.3, "Cổng đối soát\nngân hàng")
    d.actor("llm", 15.9, 3.6, "Nhà cung cấp\nLLM")
    d.link("kh", "vl", "gen"); d.link("qt", "nv", "gen")
    for u in ["tim", "ks", "dk", "chat", "xv"]: d.link("vl", u)
    for u in ["goiy", "xd", "nhan", "dh", "dg"]: d.link("kh", u)
    for u in ["xl", "sp"]: d.link("nv", u)
    for u in ["km", "tv", "ls"]: d.link("qt", u)
    for a, u in [("em", "dk"), ("em", "chat"), ("cg", "dh"), ("llm", "chat"), ("llm", "nhan"), ("llm", "tv")]:
        d.link(a, u)
    d.save(P("3.1"))


def fig_3_2():
    d = Diagram(16, 13.4)
    boundary(d, 3.0, 0.3, 15.7, 13.1, "Hệ thống SkinSyntaxVN – Khách hàng")
    L, R = 6.4, 12.6
    left = [("u08", "UC08 Quản lý hồ sơ da", 12.2), ("u09", "UC09 Nhận gợi ý\ncá nhân hóa", 11.1),
            ("u10", "UC10 Quản lý giỏ hàng", 10.0), ("u11", "UC11 Đặt hàng\nvà thanh toán", 8.5),
            ("u13", "UC13 Đọc thành phần\ntừ ảnh nhãn", 7.2), ("u12", "UC12 Đặt hàng\nqua chatbot", 5.9),
            ("u14", "UC14 Đánh giá, hỏi đáp,\nnhắn tin", 4.6), ("u15", "UC15 Theo dõi\nvà hủy đơn hàng", 3.4),
            ("u16", "UC16 Nhận thưởng", 2.25), ("u17", "UC17 Mua hàng\ntrong livestream", 1.1)]
    for k, t, y in left:
        d.uc(k, arc(y, 6.7, L + 1.0, 2.2), y, t, w=3.5, h=0.85)
    right = [("ld", "Xem lý do phù hợp", 11.1), ("xd", "Kiểm tra xung đột\nhoạt chất", 10.0),
             ("tk", "Kiểm tra tồn kho", 8.9), ("vc", "Áp dụng voucher", 7.85), ("dt", "Dùng điểm tích lũy", 6.8),
             ("otp", "Xác thực OTP", 5.9)]
    for k, t, y in right:
        d.uc(k, R, y, t, w=3.1, h=0.8)
    d.actor("kh", 1.4, 6.7, "Khách hàng")
    for k, _, _ in left:
        d.link("kh", k)
    d.link("ld", "u09", "dep", EXTD)
    d.link("u10", "xd", "dep", INC)
    d.link("u11", "tk", "dep", INC, lpos=(9.75, 8.75))
    d.link("vc", "u11", "dep", EXTD, lpos=(10.05, 8.15))
    d.link("dt", "u11", "dep", EXTD, lpos=(10.1, 7.45))
    d.link("u12", "otp", "dep", INC)
    d.save(P("3.2"))


def fig_3_3():
    d = Diagram(16, 12.8)
    boundary(d, 3.0, 0.3, 15.7, 12.5, "Hệ thống SkinSyntaxVN – Nhân viên và quản trị viên")
    L, R = 6.6, 12.7
    left = [("u18", "UC18 Xử lý đơn hàng", 11.6), ("u19", "UC19 Quản lý sản phẩm", 10.5),
            ("u20", "UC20 Trả lời đánh giá,\nhỏi đáp, tin nhắn", 9.4),
            ("u21", "UC21 Quản lý danh mục\nvà tồn kho", 7.9), ("u22", "UC22 Quản lý người dùng\nvà nhân viên", 6.8),
            ("u23", "UC23 Quản lý\nkhuyến mãi", 5.5), ("u24", "UC24 Kiểm duyệt\nđánh giá, hỏi đáp", 4.2),
            ("u25", "UC25 Xem báo cáo", 3.1), ("u26", "UC26 Tạo video\nsản phẩm (AI Idol)", 2.0),
            ("u27", "UC27 Quản lý livestream\nvà chiến dịch", 0.9)]
    for k, t, y in left:
        ay = 10.4 if k in ("u18", "u19", "u20") else 4.6
        d.uc(k, arc(y, ay, L + 0.8, 1.6, 4.5), y, t, w=3.6, h=0.85)
    right = [("in", "In đơn hàng", 11.6), ("lo", "Quản lý lô hàng\nvà hạn dùng", 7.9),
             ("vo", "Voucher", 6.6), ("fs", "Flash sale", 5.5), ("ct", "Chiến dịch thưởng\nxem video", 4.4)]
    for k, t, y in right:
        d.uc(k, R, y, t, w=3.0, h=0.8)
    d.actor("nv", 1.4, 10.4, "Nhân viên")
    d.actor("qt", 1.4, 4.6, "Quản trị viên")
    d.link("qt", "nv", "gen")
    for k in ["u18", "u19", "u20"]: d.link("nv", k)
    for k in ["u21", "u22", "u23", "u24", "u25", "u26", "u27"]: d.link("qt", k)
    d.link("in", "u18", "dep", EXTD)
    d.link("u21", "lo", "dep", INC)
    for k in ["vo", "fs", "ct"]: d.link(k, "u23", "gen")
    d.save(P("3.3"))


def fig_3_4():
    d = Diagram(16, 11.4)
    boundary(d, 3.0, 0.3, 15.7, 11.1, "Phân hệ AI Idol")
    L, R = 6.6, 12.6
    d.uc("u26", L, 8.3, "UC26 Tạo video\nsản phẩm", w=3.3, h=0.9)
    d.uc("u27", L, 5.2, "UC27 Quản lý livestream\nvà chiến dịch", w=3.6, h=0.9)
    d.uc("u05", L, 3.1, "UC05 Xem video\nvà livestream của Syna", w=3.6, h=0.9)
    d.uc("u16", L, 2.0, "UC16 Nhận voucher khi\nxem hết video", w=3.6, h=0.9)
    d.uc("u17", L, 0.9, "UC17 Mua hàng\ntrong livestream", w=3.6, h=0.9)
    for k, t, y in [("kb", "Sinh kịch bản", 10.3), ("kt", "Kiểm tra kịch bản", 9.35), ("gd", "Tạo giọng đọc", 8.4),
                    ("dv", "Dựng video", 7.45), ("dy", "Duyệt kịch bản\nthủ công", 6.45),
                    ("ll", "Lên lịch chiến dịch", 5.5), ("gh", "Ghim sản phẩm", 4.55)]:
        d.uc(k, R, y, t, w=3.0, h=0.78)
    d.actor("qt", 1.4, 6.8, "Quản trị viên")
    d.actor("kh", 1.4, 2.0, "Khách hàng")
    d.actor("llm", 1.4, 9.9, "Nhà cung cấp\nLLM")
    for k in ["u26", "u27"]: d.link("qt", k)
    for k in ["u05", "u16", "u17"]: d.link("kh", k)
    d.link("llm", "u26")
    for k in ["kb", "kt", "gd", "dv"]: d.link("u26", k, "dep", INC, rot=True)
    d.link("dy", "u26", "dep", EXTD, rot=True)
    for k in ["ll", "gh"]: d.link("u27", k, "dep", INC, rot=True)
    d.save(P("3.4"))


# ======================= TUẦN TỰ =======================
class Seq:
    def __init__(self, names, gap=2.2, top_pad=0.5, n_rows=20, row=0.62, w0=1.9, actor0=True):
        self.names = names
        self.W = gap * len(names) + 0.4
        self.row = row
        self.H = n_rows * row + 2.2
        self.d = Diagram(self.W, self.H, scale=0.6)
        self.xs = [0.2 + gap / 2 + i * gap for i in range(len(names))]
        self.y = self.H - (2.05 if actor0 else 1.6)
        self.ytop = self.H - 0.35
        self.w0 = min(w0, gap - 0.2)
        self.actor0 = actor0
        self.frames = []

    def _heads(self, ybot):
        ax = self.d.ax
        for i, (x, n) in enumerate(zip(self.xs, self.names)):
            if i == 0 and self.actor0:
                self.d.actor("a0", x, self.ytop - 0.45, n, s=0.75)
                yb = self.ytop - 1.15
            else:
                h = 0.7
                ax.add_patch(Rectangle((x - self.w0 / 2, self.ytop - h), self.w0, h, fc=ACT[0], ec=ACT[1], lw=1))
                ax.text(x, self.ytop - h / 2, n, ha="center", va="center", fontsize=FS - 0.5, linespacing=1.05)
                yb = self.ytop - h
            ax.plot([x, x], [yb, ybot], color="#666666", lw=0.8, ls=(0, (4, 3)), zorder=0)

    def msg(self, i, j, text, ret=False):
        x1, x2 = self.xs[i], self.xs[j]
        y = self.y
        ax = self.d.ax
        if i == j:
            ax.plot([x1, x1 + 0.45, x1 + 0.45], [y + 0.1, y + 0.1, y - 0.2], color="black", lw=0.9)
            ax.annotate("", xy=(x1 + 0.03, y - 0.2), xytext=(x1 + 0.45, y - 0.2), arrowprops=dict(
                arrowstyle="-|>,head_length=0.45,head_width=0.2", color="black", lw=0.9, shrinkA=0, shrinkB=0))
            ax.text(x1 + 0.55, y - 0.05, text, fontsize=FS - 1, ha="left", va="center", bbox=dict(fc="white", ec="none", pad=0.3))
        else:
            st = "->,head_length=0.45,head_width=0.2" if ret else "-|>,head_length=0.45,head_width=0.2"
            ax.annotate("", xy=(x2, y), xytext=(x1, y), arrowprops=dict(
                arrowstyle=st, color="black", lw=0.9, ls=(0, (4, 3)) if ret else "-", shrinkA=0, shrinkB=0))
            ax.text((x1 + x2) / 2, y + 0.06, text, fontsize=FS - 1, ha="center", va="bottom", bbox=dict(fc="white", ec="none", pad=0.3))
        self.y -= self.row * (1.25 if i == j else 1)

    def frame_start(self, kind, guard, i0, i1):
        self.frames.append([kind, guard, i0, i1, self.y + 0.42, []])
        self.y -= 0.25

    def frame_else(self, guard):
        f = self.frames[-1]
        f[5].append((self.y + 0.3, guard))
        self.y -= 0.25

    def frame_end(self):
        kind, guard, i0, i1, ytop, elses = self.frames.pop()
        ax = self.d.ax
        x0, x1 = self.xs[i0] - 0.75, self.xs[i1] + 0.75
        yb = self.y + 0.25
        ax.add_patch(Rectangle((x0, yb), x1 - x0, ytop - yb, fill=False, ec="#333333", lw=0.9))
        ax.add_patch(Rectangle((x0, ytop - 0.32), 0.62, 0.32, fc="#f5f5f5", ec="#333333", lw=0.9))
        ax.text(x0 + 0.31, ytop - 0.16, kind, ha="center", va="center", fontsize=FS - 1, weight="bold")
        ax.text(x0 + 0.72, ytop - 0.16, guard, ha="left", va="center", fontsize=FS - 1, style="italic")
        for ye, g in elses:
            ax.plot([x0, x1], [ye, ye], color="#333333", lw=0.8, ls=(0, (5, 3)))
            ax.text(x0 + 0.12, ye - 0.16, g, ha="left", va="center", fontsize=FS - 1, style="italic")
        self.y -= 0.3

    def save(self, key):
        self._heads(self.y + 0.1)
        self.d.ymin = self.y - 0.1
        self.d.save(P(key))


def fig_3_9():
    s = Seq(["Khách hàng", "Trình duyệt", "PHP Backend", "Chatbot\n(LangGraph)", "LLM", "ChromaDB /\nNeo4j", "MongoDB"],
            gap=2.35, n_rows=24)
    s.msg(0, 1, "Gửi câu hỏi")
    s.msg(1, 2, "POST yêu cầu chat (stream)")
    s.msg(2, 2, "Kiểm tra phiên, hạn mức")
    s.msg(2, 3, "Chuyển tiếp kèm phiên, hồ sơ da")
    s.msg(3, 4, "Phân loại ý định")
    s.msg(4, 3, "Nhãn ý định, hàng đợi", ret=True)
    s.frame_start("alt", "[ý định thuộc legacy]", 2, 6)
    s.msg(3, 5, "Truy xuất ChromaDB + BM25")
    s.msg(5, 3, "Ngữ cảnh Top-K", ret=True)
    s.msg(3, 4, "Sinh câu trả lời từ ngữ cảnh")
    s.msg(4, 3, "Câu trả lời", ret=True)
    s.frame_else("[ý định thuộc agent]")
    s.msg(3, 4, "Chọn công cụ")
    s.msg(3, 5, "Tra sản phẩm, xung đột (Neo4j)")
    s.msg(3, 2, "Gọi API giỏ hàng, đơn hàng")
    s.msg(2, 6, "Đọc, ghi giỏ và đơn")
    s.msg(2, 3, "Kết quả công cụ", ret=True)
    s.msg(3, 4, "Tổng hợp câu trả lời")
    s.msg(4, 3, "Câu trả lời", ret=True)
    s.frame_end()
    s.msg(3, 2, "Luồng sự kiện token / ui", ret=True)
    s.msg(2, 1, "Chuyển tiếp luồng (SSE)", ret=True)
    s.msg(1, 0, "Hiển thị câu trả lời, thẻ sản phẩm", ret=True)
    s.save("3.9")


def fig_3_10():
    s = Seq(["Khách hàng", "Trình duyệt", "PHP Backend", "Search\nService", "Redis", "ChromaDB", "Neo4j", "MongoDB"],
            gap=2.15, n_rows=19)
    s.msg(0, 1, "Mở trang")
    s.msg(1, 2, "Yêu cầu trang")
    s.msg(2, 2, "Kiểm tra ngắt mạch")
    s.msg(2, 3, "POST /api/search/recommend")
    s.msg(3, 4, "Tra bộ đệm")
    s.msg(4, 3, "Kết quả tra", ret=True)
    s.frame_start("alt", "[chưa có trong bộ đệm]", 3, 7)
    s.msg(3, 5, "Nhánh ngữ nghĩa")
    s.msg(3, 6, "Nhánh đồ thị (tương tự, mua kèm)")
    s.msg(3, 7, "Xu hướng, cùng hãng, cá nhân hóa")
    s.msg(3, 3, "Trộn điểm theo trọng số")
    s.msg(3, 4, "Lưu bộ đệm")
    s.frame_end()
    s.msg(3, 2, "Danh sách + reason_code", ret=True)
    s.msg(2, 1, "Trang có khối gợi ý", ret=True)
    s.msg(1, 0, "Hiển thị gợi ý kèm lý do", ret=True)
    s.save("3.10")


def fig_3_11():
    s = Seq(["Khách hàng", "Trình duyệt", "PHP Backend", "Search\nService", "Redis", "Neo4j", "MongoDB"],
            gap=2.3, n_rows=19)
    s.msg(0, 1, "Mở giỏ hàng")
    s.msg(1, 2, "GET phân tích giỏ hàng")
    s.msg(2, 2, "Lấy mã sản phẩm từ phiên, hồ sơ da")
    s.msg(2, 3, "POST /api/cart/analyze")
    s.msg(3, 4, "Tra bộ đệm")
    s.msg(4, 3, "Kết quả tra", ret=True)
    s.frame_start("alt", "[chưa có trong bộ đệm]", 3, 6)
    s.msg(3, 5, "Truy vấn cặp CONFLICTS_WITH")
    s.msg(5, 3, "Cặp xung đột, thứ hạng", ret=True)
    s.frame_else("[Neo4j lỗi]")
    s.msg(3, 6, "Đọc thành phần sản phẩm")
    s.msg(3, 3, "Áp luật YAML dự phòng")
    s.frame_end()
    s.msg(3, 2, "Xung đột, routine, gợi ý", ret=True)
    s.msg(2, 1, "Dữ liệu khối phân tích", ret=True)
    s.msg(1, 0, "Hiển thị cảnh báo", ret=True)
    s.save("3.11")


def fig_3_12():
    s = Seq(["Quản trị viên", "PHP Backend", "AI Idol\nService", "Redis /\nCelery", "Worker", "LLM", "Dịch vụ\nTTS",
             "Bộ dựng\nSyna", "Kho lưu\ntrữ R2", "MongoDB"], gap=1.95, n_rows=25, w0=1.75)
    s.msg(0, 1, "Tạo công việc")
    s.msg(1, 2, "POST tạo job")
    s.msg(2, 9, "Lưu ai_idol_jobs")
    s.msg(2, 3, "Đưa task vào hàng đợi")
    s.msg(3, 4, "Giao task")
    s.msg(4, 9, "Lấy sản phẩm, giá")
    s.msg(4, 5, "Sinh kịch bản (kèm RAG)")
    s.msg(5, 4, "Kịch bản", ret=True)
    s.msg(4, 4, "Kiểm tra, tự sửa")
    s.frame_start("opt", "[duyệt thủ công]", 0, 9)
    s.msg(4, 9, "Trạng thái chờ duyệt")
    s.msg(0, 1, "Duyệt kịch bản")
    s.msg(1, 2, "Gửi quyết định")
    s.msg(2, 3, "Tiếp tục task")
    s.frame_end()
    s.msg(4, 6, "Tạo giọng đọc")
    s.msg(6, 4, "Tệp âm thanh", ret=True)
    s.msg(4, 7, "Dựng video")
    s.msg(7, 4, "Tệp video", ret=True)
    s.msg(4, 4, "FFmpeg trộn âm")
    s.msg(4, 8, "Tải video lên")
    s.msg(4, 9, "Ghi ai_product_videos")
    s.msg(1, 9, "Đọc video sản phẩm")
    s.msg(1, 0, "Hiển thị trên trang sản phẩm", ret=True)
    s.save("3.12")


# ======================= CÂY PHÂN LOẠI =======================
def tree(d, parent, kids, ymid=None):
    P_ = d.n[parent]
    y0 = P_["y"] - P_["h"] / 2
    ytop = max(d.n[k]["y"] + d.n[k]["h"] / 2 for k in kids)
    ym = ymid if ymid is not None else (y0 + ytop) / 2
    k = dict(color="#333333", lw=0.9)
    d.ax.plot([P_["x"], P_["x"]], [y0, ym], **k)
    xs = [d.n[c]["x"] for c in kids]
    d.ax.plot([min(xs + [P_["x"]]), max(xs + [P_["x"]])], [ym, ym], **k)
    for c in kids:
        C = d.n[c]
        d.ax.plot([C["x"], C["x"]], [ym, C["y"] + C["h"] / 2], **k)


def fig_2_1():
    d = Diagram(17.4, 8.2)
    USED, TRY, BASE = DATA, DEC, NOTE
    d.box("root", 8.7, 7.4, "Hệ thống gợi ý", w=3.0, h=0.65, style=ACT)
    L1 = 5.9
    d.box("cb", 1.5, L1, "Theo nội dung\n(Content-based)", w=2.5, h=0.8, style=USED)
    d.box("cf", 5.4, L1, "Lọc cộng tác\n(Collaborative)", w=2.5, h=0.8, style=BASE)
    d.box("kb", 10.05, L1, "Dựa trên tri thức\n(Knowledge-based)", w=2.6, h=0.8, style=USED)
    d.box("gb", 13.0, L1, "Dựa trên đồ thị\n(Graph-based)", w=2.4, h=0.8, style=USED)
    d.box("hy", 15.75, L1, "Lai\n(Hybrid)", w=2.1, h=0.8, style=USED)
    L2 = 4.1
    d.box("mem", 3.4, L2, "Dựa trên bộ nhớ\n(Memory-based)", w=2.3, h=0.8, style=BASE)
    d.box("mod", 7.4, L2, "Dựa trên mô hình\n(Model-based)", w=2.3, h=0.8, style=BASE)
    d.box("con", 9.05, 2.4, "Ràng buộc\n(Constraint)", w=1.9, h=0.75, style=USED)
    d.box("cas", 11.1, 2.4, "Tình huống\n(Case-based)", w=1.9, h=0.75, style=BASE)
    L3 = 2.4
    d.box("uk", 2.4, L3, "User-based\nkNN", w=1.8, h=0.75, style=BASE)
    d.box("ik", 4.35, L3, "Item-based\nkNN", w=1.8, h=0.75, style=BASE)
    d.box("mf", 6.4, L3, "Phân rã ma trận\n(MF / SVD)", w=1.95, h=0.75, style=BASE)
    d.box("bpr", 8.0, 1.2, "BPR", w=1.3, h=0.6, style=TRY)
    for i, (k, t) in enumerate([("w", "Weighted"), ("sw", "Switching"), ("mx", "Mixed"), ("cs", "Cascade")]):
        d.box(k, 16.0, 4.5 - i * 0.82, t, w=1.6, h=0.6, style=USED if k == "w" else BASE)
    tree(d, "root", ["cb", "cf", "kb", "gb", "hy"])
    tree(d, "cf", ["mem", "mod"])
    tree(d, "mem", ["uk", "ik"])
    k = dict(color="#333333", lw=0.9)
    M = d.n["mod"]
    d.ax.plot([M["x"], M["x"]], [M["y"] - 0.4, 3.15], **k)
    d.ax.plot([6.4, 8.0], [3.15, 3.15], **k)
    d.ax.plot([6.4, 6.4], [3.15, 2.775], **k)
    d.ax.plot([8.0, 8.0], [3.15, 1.5], **k)
    tree(d, "kb", ["con", "cas"], ymid=3.1)
    d.ax.plot([14.95, 14.95], [L1 - 0.4, 4.5 - 3 * 0.82], **k)
    for i in range(4):
        y = 4.5 - i * 0.82
        d.ax.plot([14.95, 15.2], [y, y], **k)
    # chú thích
    for i, (st, t) in enumerate([(USED, "Nhóm sử dụng trong hệ thống"), (TRY, "Nhóm thử nghiệm, đánh giá"),
                                 (BASE, "Không sử dụng")]):
        x = 10.4 + i * 0.0
        y = 1.2 - i * 0.0
    lx, ly = 10.3, 1.05
    for i, (st, t) in enumerate([(USED, "Nhóm sử dụng"), (TRY, "Nhóm thử nghiệm"), (BASE, "Không sử dụng")]):
        xx = lx + i * 2.35
        d.ax.add_patch(Rectangle((xx, ly - 0.15), 0.4, 0.3, fc=st[0], ec=st[1], lw=1))
        d.ax.text(xx + 0.5, ly, t, fontsize=FS - 1, va="center")
    d.save(P("2.1"))


# ======================= ĐỒ THỊ TRI THỨC =======================
NC = {"Product": ("#dae8fc", "#6c8ebf"), "Brand": ("#e1d5e7", "#9673a6"), "Category": ("#fff2cc", "#d6b656"),
      "Ingredient": ("#f8cecc", "#b85450"), "SkinType": ("#d5e8d4", "#82b366"), "Concern": ("#ffe6cc", "#d79b00"),
      "Benefit": ("#f5f5f5", "#666666"), "Origin": ("#e6f2f2", "#5b9a9a")}


def kg(d, k, x, y, name, r=0.62):
    d.node(k, x, y, name, r=r, fc=NC[name][0], ec=NC[name][1])


def fig_kg(key, full):
    d = Diagram(15, 10.6 if full else 9.6)
    yP = 5.3
    kg(d, "p", 7.5, yP, "Product", r=0.75)
    kg(d, "b", 2.6, 8.0, "Brand")
    kg(d, "c", 7.5, 8.9 if full else 8.6, "Category")
    kg(d, "s", 1.8, 4.6, "SkinType")
    kg(d, "co", 4.0, 1.4, "Concern")
    kg(d, "i", 11.2, 1.8, "Ingredient")
    d.link("p", "b", "arrow", "OF_BRAND")
    d.link("p", "c", "arrow", "IN_CATEGORY")
    d.link("p", "s", "arrow", "SUITABLE_FOR")
    d.link("p", "co", "arrow", "TARGETS")
    d.link("p", "i", "arrow", "CONTAINS {rank}" if full else "CONTAINS", lpos=(10.1, 2.95))
    d.loop("i", "CONFLICTS_WITH", side="bottom")
    d.loop("p", "SIMILAR_TO / CO_PURCHASED", side="bottom", size=0.9)
    if full:
        kg(d, "o", 12.6, 8.0, "Origin")
        kg(d, "be", 13.2, 4.9, "Benefit")
        d.link("p", "o", "arrow", "MADE_IN")
        d.link("p", "be", "arrow", "HAS_BENEFIT")
        d.link("i", "co", "arrow", "HELPS")
        d.loop("c", "CHILD_OF", side="top", size=0.5)
    d.save(P(key))


# ======================= KIẾN TRÚC =======================
def fig_3_13():
    d = Diagram(17, 12.4)
    d.frame(0.3, 2.9, 2.9, 12.1, "Người dùng")
    d.frame(3.2, 2.9, 5.4, 12.1, "Cổng vào")
    d.frame(5.8, 2.9, 12.0, 12.1, "Tầng ứng dụng")
    d.frame(12.4, 2.9, 16.7, 12.1, "Tầng dữ liệu")
    d.frame(5.8, 0.2, 16.7, 2.5, "Dịch vụ bên ngoài")
    d.act("br", 1.6, 7.6, "Trình duyệt\n(khách, nhân viên,\nquản trị)", w=2.2, h=1.2, style=NOTE)
    d.act("ng", 4.3, 7.6, "Nginx\n(Caddy HTTPS)", w=1.9, h=0.9)
    X = 9.3
    d.box("php", X, 10.6, "PHP Backend\n(MVC, BFF)", w=3.6, h=0.9)
    d.box("ai", X, 8.9, "AI Service: chatbot LangGraph,\nSearch Service, Home Service", w=3.9, h=0.95)
    d.box("wk", X, 7.25, "Search worker,\nCatalog worker", w=3.6, h=0.85)
    d.box("idol", X, 5.6, "AI Idol Service\n+ Celery worker, beat", w=3.6, h=0.9)
    d.box("crew", X, 3.9, "Agent kịch bản\n(CrewAI)", w=3.6, h=0.85)
    d.db("mg", 14.6, 10.4, "MongoDB Atlas", w=2.6)
    d.db("neo", 14.6, 8.6, "Neo4j", w=2.6)
    d.db("ch", 14.6, 6.8, "ChromaDB", w=2.6)
    d.db("rd", 14.6, 5.0, "Redis", w=2.6)
    for i, t in enumerate(["Nhà cung cấp LLM", "Dịch vụ email", "Cổng đối soát", "Cloudflare R2"]):
        d.box("x%d" % i, 7.4 + i * 2.7, 1.15, t, w=2.3, h=0.7, style=EXT)
    d.edge("br", "ng", "HTTPS", lpos=(2.75, 7.68))
    d.edge("ng", "php", via=[(4.3, 10.6)])
    d.edge("php", "ai", "HTTP nội bộ +\nX-Internal-Token", lpos=(X + 0.1, 9.53))
    d.line([(X - 1.8, 10.3), (6.3, 10.3), (6.3, 5.6), (X - 1.8, 5.6)])
    d.edge("idol", "crew")
    # thanh nối dữ liệu
    bx = 12.2
    k = dict(color="black", lw=0.9)
    d.ax.plot([bx, bx], [3.9, 10.6], **k)
    for y, w in [(10.6, 3.6), (8.9, 3.9), (7.25, 3.6), (5.6, 3.6), (3.9, 3.6)]:
        d.ax.plot([X + w / 2, bx], [y, y], **k)
    for y in [10.4, 8.6, 6.8, 5.0]:
        d.ax.annotate("", xy=(13.3, y), xytext=(bx, y), arrowprops=dict(
            arrowstyle="<|-|>,head_length=0.4,head_width=0.2", color="black", lw=0.9, shrinkA=0, shrinkB=0))
    d.ax.plot([X, X], [3.475, 2.5], color="black", lw=0.9, ls=(0, (4, 3)))
    d.label(X + 0.12, 2.75, "gọi API ngoài", style="italic")
    d.save(P("3.13"))


def fig_3_14():
    d = Diagram(17, 10.6)
    # nút máy chủ dạng hộp 3D
    x0, y0, x1, y1, dp = 3.6, 0.5, 13.0, 9.6, 0.35
    ax = d.ax
    ax.add_patch(Rectangle((x0, y0), x1 - x0, y1 - y0, fc="#fafafa", ec="#333333", lw=1))
    from matplotlib.patches import Polygon
    ax.add_patch(Polygon([(x0, y1), (x0 + dp, y1 + dp), (x1 + dp, y1 + dp), (x1, y1)], fc="#eeeeee", ec="#333333", lw=1))
    ax.add_patch(Polygon([(x1, y0), (x1 + dp, y0 + dp), (x1 + dp, y1 + dp), (x1, y1)], fc="#e4e4e4", ec="#333333", lw=1))
    ax.text(x0 + 0.15, y1 - 0.15, "«device» Máy chủ ảo (VPS) – Docker Compose", ha="left", va="top", fontsize=FS + 0.5, weight="bold")
    names = [["caddy", "nginx", "php-backend"], ["ai-service", "search-worker", "catalog-worker"],
             ["ai-idol-service", "ai-idol-worker", "ai-idol-beat"], ["ai-idol-agent-service", "neo4j", "chromadb"]]
    for r, row in enumerate(names):
        for c, n in enumerate(row):
            x = 5.3 + c * 3.0
            y = 8.2 - r * 1.35
            d.box("c_" + n, x, y, "«container»\n" + n, w=2.6, h=0.85,
                  style=DATA if n in ("neo4j", "chromadb") else ACT)
    d.box("c_redis", 11.3, 2.8, "«container»\nredis", w=2.6, h=0.85, style=DATA)
    d.db("vol", 6.8, 1.5, "Volume dữ liệu: neo4j, chromadb, redis, uploads", w=5.6, h=0.85, style=NOTE)
    d.actor("u", 1.3, 8.4, "Người dùng")
    d.edge("u", "c_caddy", "Internet\n(cổng 80/443)", lpos=(1.8, 8.3))
    ext = [("mg", "MongoDB Atlas"), ("r2", "Cloudflare R2"), ("llm", "Nhà cung cấp LLM"), ("em", "Dịch vụ email")]
    for i, (k, t) in enumerate(ext):
        d.box(k, 15.4, 8.2 - i * 1.6, t, w=2.6, h=0.75, style=EXT)
        d.ax.annotate("", xy=(14.1, 8.2 - i * 1.6), xytext=(x1 + dp, 8.2 - i * 1.6), arrowprops=dict(
            arrowstyle="-|>,head_length=0.45,head_width=0.22", color="black", lw=0.9, ls=(0, (4, 3)), shrinkA=0, shrinkB=0))
    d.save(P("3.14"))


# ======================= ERD =======================
def ent(d, k, x, y, name, note, w=3.0):
    h, hh = 1.0, 0.42
    d.ax.add_patch(Rectangle((x - w / 2, y - h / 2), w, h, fc="white", ec="#6c8ebf", lw=1, zorder=2))
    d.ax.add_patch(Rectangle((x - w / 2, y + h / 2 - hh), w, hh, fc="#dae8fc", ec="#6c8ebf", lw=1, zorder=2))
    d.ax.text(x, y + h / 2 - hh / 2, name, ha="center", va="center", fontsize=FS, weight="bold", zorder=3)
    d.ax.text(x, y - hh / 2 + 0.02, note, ha="center", va="center", fontsize=FS - 1, color="#333333", zorder=3)
    d._add(k, x, y, w, h, "box")


def rel(d, a, b, ca, cb):
    A, B = d.n[a], d.n[b]
    p = d._clip(a, B["x"], B["y"]); q = d._clip(b, A["x"], A["y"])
    d.ax.plot([p[0], q[0]], [p[1], q[1]], color="black", lw=0.9, zorder=1)
    for (pt, other, t) in [(p, q, ca), (q, p, cb)]:
        dx, dy = other[0] - pt[0], other[1] - pt[1]
        L = (dx * dx + dy * dy) ** 0.5
        ox, oy = -dy / L * 0.18, dx / L * 0.18
        d.ax.text(pt[0] + dx / L * 0.3 + ox, pt[1] + dy / L * 0.3 + oy, t, fontsize=FS - 0.5, ha="center",
                  va="center", weight="bold", zorder=3)


def fig_3_15():
    d = Diagram(17, 11.2)
    ent(d, "sp", 8.5, 6.3, "san_pham", "sản phẩm")
    for i, (k, n, t) in enumerate([("dm", "danh_muc", "danh mục"), ("th", "thuong_hieu", "thương hiệu"),
                                   ("xx", "xuat_xu", "xuất xứ"), ("ld", "loai_da", "loại da")]):
        ent(d, k, 2.2 + i * 4.2, 9.9, n, t)
        rel(d, "sp", k, "n", "1")
    ent(d, "ib", 2.2, 6.3, "inventory_batches", "lô hàng, hạn dùng")
    ent(d, "vd", 14.8, 7.6, "ai_product_videos", "video của Syna")
    ent(d, "dg", 14.8, 5.9, "danh_gia_san_pham", "đánh giá", w=3.2)
    ent(d, "hd", 14.8, 4.2, "hoi_dap_san_pham", "hỏi đáp", w=3.2)
    for k in ["ib", "vd", "dg", "hd"]:
        rel(d, "sp", k, "1", "n")
    ent(d, "ct", 8.5, 3.6, "chi_tiet_hoa_don", "dòng sản phẩm của đơn")
    ent(d, "hdn", 8.5, 1.0, "hoa_don", "đơn hàng")
    ent(d, "kh", 3.2, 1.0, "khach_hang", "khách hàng")
    ent(d, "sk", 2.2, 3.6, "skin_profiles", "hồ sơ da")
    ent(d, "vc", 13.8, 1.0, "voucher", "mã giảm giá")
    rel(d, "ct", "sp", "n", "1")
    rel(d, "hdn", "ct", "1", "n")
    rel(d, "kh", "hdn", "1", "n")
    rel(d, "kh", "sk", "1", "n")
    rel(d, "hdn", "vc", "n", "1")
    d.save(P("3.15"))


FIGS2 = {"3.1": fig_3_1, "3.2": fig_3_2, "3.3": fig_3_3, "3.4": fig_3_4,
         "3.9": fig_3_9, "3.10": fig_3_10, "3.11": fig_3_11, "3.12": fig_3_12,
         "2.1": fig_2_1, "2.3": lambda: fig_kg("2.3", False), "3.16": lambda: fig_kg("3.16", True),
         "3.13": fig_3_13, "3.14": fig_3_14, "3.15": fig_3_15}

if __name__ == "__main__":
    for k in sys.argv[1:] or list(FIGS2):
        FIGS2[k]()
        print("saved", k)
