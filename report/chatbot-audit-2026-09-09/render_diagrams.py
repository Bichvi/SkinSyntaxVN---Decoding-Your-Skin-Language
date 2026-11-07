"""Draw original architecture diagrams as editable SVG and PNG, without ImageGen.
The supplied screenshot is a layout reference, not an image being edited.
"""
from pathlib import Path
from html import escape
import math
from PIL import Image, ImageDraw, ImageFont

OUT = Path(__file__).resolve().parent
W, H = 1800, 2180
FONT = 'C:/Windows/Fonts/segoeui.ttf'
BOLD = 'C:/Windows/Fonts/segoeuib.ttf'
INK = '#14372e'
GREEN = '#0d513a'
PALE = '#eef6f0'
RED = '#9f3535'
ROSE = '#fff3f0'
MUTED = '#4c625a'


def font(size, bold=False):
    return ImageFont.truetype(BOLD if bold else FONT, size)


def wrap(text, width, size, bold=False):
    words = text.split()
    lines = []
    current = ''
    f = font(size, bold)
    for word in words:
        proposed = (current + ' ' + word).strip()
        if f.getlength(proposed) <= width:
            current = proposed
        else:
            if current:
                lines.append(current)
            current = word
    if current:
        lines.append(current)
    return lines


class Diagram:
    def __init__(self, title, subtitle):
        self.im = Image.new('RGB', (W, H), '#ffffff')
        self.d = ImageDraw.Draw(self.im)
        self.svg = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" role="img">',
                    f'<title>{escape(title)}</title><desc>{escape(subtitle)}</desc>',
                    f'<rect width="{W}" height="{H}" fill="white"/>']
        self.text(70, 32, title, 40, True)
        self.text(70, 88, subtitle, 23, False, MUTED)

    def text(self, x, y, text, size=24, bold=False, color=INK):
        self.d.text((x, y), text, font=font(size, bold), fill=color)
        self.svg.append(f'<text x="{x}" y="{y}" dominant-baseline="text-before-edge" font-family="Segoe UI, Arial, sans-serif" font-size="{size}" font-weight="{700 if bold else 400}" fill="{color}">{escape(text)}</text>')

    def rect(self, x, y, w, h, fill, stroke=GREEN):
        self.d.rounded_rectangle((x, y, x+w, y+h), radius=8, fill=fill, outline=stroke, width=2)
        self.svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{fill}" stroke="{stroke}" stroke-width="2"/>')

    def box(self, x, y, w, h, title, lines, warn=False, size=24):
        color = RED if warn else GREEN
        header_lines = wrap(title, w-34, 27, True)
        hh = len(header_lines)*36+16
        self.rect(x, y, w, h, ROSE if warn else PALE, color)
        self.rect(x, y, w, hh, color, color)
        for i, line in enumerate(header_lines):
            self.text(x+17, y+6+i*36, line, 27, True, '#ffffff')
        ty = y+hh+14
        for line in lines:
            for part in wrap(line, w-38, size):
                self.text(x+19, ty, part, size)
                ty += size+7
            ty += 3
        if ty-12 > y+h-8:
            raise ValueError(f'Overflow: {title} bottom={ty} bound={y+h}')

    def arrow(self, points, color=GREEN, label=None, label_at=None):
        self.d.line(points, fill=color, width=3, joint='curve')
        self.svg.append('<polyline points="' + ' '.join(f'{x},{y}' for x,y in points) + f'" fill="none" stroke="{color}" stroke-width="3" stroke-linejoin="round"/>')
        x0,y0 = points[-2]; x,y = points[-1]
        a = math.atan2(y-y0,x-x0)
        head = [(x,y), (x-15*math.cos(a-.45),y-15*math.sin(a-.45)), (x-15*math.cos(a+.45),y-15*math.sin(a+.45))]
        self.d.polygon(head, fill=color)
        self.svg.append('<polygon points="'+' '.join(f'{px},{py}' for px,py in head)+f'" fill="{color}"/>')
        if label:
            lx,ly = label_at
            tw = font(21).getlength(label)+14
            self.rect(lx-5,ly-2,tw,29,'#ffffff','#ffffff')
            self.text(lx,ly,label,21,False,color)

    def save(self, name):
        self.im.save(OUT / (name+'.png'))
        self.svg.append('</svg>')
        (OUT / (name+'.svg')).write_text('\n'.join(self.svg), encoding='utf-8')


def current():
    d=Diagram('LUỒNG CHATBOT HIỆN TẠI · THEO SOURCE CODE', 'SkinSyntaxVN · 09/09/2026 · Fxx: mã lỗi trong báo cáo · chưa phải trace của process đang chạy')
    # Wires first, then opaque nodes. Left lane x70..610; right lane x740..1700.
    d.arrow([(340,370),(340,435)])
    d.arrow([(340,680),(340,740)])
    for cy in [232,407,607]:
        d.arrow([(610,830),(670,830),(670,cy),(740,cy)])
    d.arrow([(1700,232),(1738,232),(1738,890),(1700,890)])
    d.arrow([(1700,407),(1720,407),(1720,845),(1700,845)])
    d.arrow([(1220,690),(1220,760)])
    d.arrow([(1220,1030),(1220,1095)],label='Nếu routine',label_at=(1240,1050))
    d.arrow([(1700,985),(1747,985),(1747,1430),(1700,1430)])
    d.arrow([(1220,1280),(1220,1340)])
    d.arrow([(1220,1545),(1220,1605)])
    d.arrow([(1220,1810),(1220,1870)])
    d.arrow([(740,1960),(665,1960),(665,1810),(610,1810)])
    d.box(70,150,540,220,'1. PHP MVC · Rich context',[
        'Hồ sơ + giỏ hàng + trang sản phẩm',
        'History 6 message; products tối đa 5',
        'F01: cache câu hỏi + product_id, TTL 7 ngày',
        'POST /api/chat/auto · JSON lồng trong message'],size=23)
    d.box(70,435,540,245,'2. Rewrite → classify → parse',[
        'chatbot_flask.py::xu_ly_cau_hoi()',
        'Rewrite có history → LLM classify 6 intent',
        'Keyword parse; không match mới gọi LLM',
        'F10/F11: sai tiền; mất phủ định',
        'F13: profile ghi đè đối tượng đang hỏi'],size=23)
    d.box(70,740,540,220,'3. Profile / Survey gate',[
        'Thiếu / cũ / xung đột → hỏi bổ sung',
        'F03: Mongo history dict ≠ survey string',
        'F07: concerns bị mất khi map dữ liệu',
        'Đủ thông tin hoặc bypass → rẽ nhánh'],size=23)
    d.box(740,150,960,165,'GENERAL_CONVERSATION',[
        'Chitchat → tìm sản phẩm nổi bật; câu khác → Tavily',
        'Lời chào ngắn có thể được PHP trả ngay trước Flask'],size=25)
    d.box(740,355,960,155,'COSMETIC_KNOWLEDGE',[
        'Tavily (tối đa 2 kết quả) + tìm sản phẩm theo hoạt chất',
        'Lấy 15 ứng viên → lọc hoạt chất → tối đa 3'],size=25)
    d.box(740,550,960,140,'CÁC INTENT CÒN LẠI → PRODUCT',[
        'F14: kiến thức skincare chung cũng rơi vào nhánh này'],size=25)
    d.box(740,760,960,270,'4. Hybrid search + Cross-encoder',[
        'Chroma products · static-similarity-mrl-multilingual-v1',
        'CPU · query normalize=True · model mặc định 1024 chiều',
        'F02a: BM25 rỗng; F02b: TF=1; F02c: lọt filter lexical',
        'RRF: k=60; α=0,5 · retrieve max(2 × top_n, 6)',
        'Reranker: mmarco-mMiniLMv2-L12-H384-v1 · CPU'],size=24)
    d.box(740,1095,960,185,'5. Routine: tìm tổ hợp theo ngân sách',[
        '6 nhóm × tối đa 3 ứng viên → tối đa 729 tổ hợp',
        'Chọn trước khi gộp sản phẩm PHP; nhóm ít linh hoạt',
        'Không có ngân sách → ưu tiên combo rẻ nhất'],size=24)
    d.box(740,1340,960,205,'6. Gộp PHP → lọc → rerank lần nữa',[
        'Sản phẩm trang đang xem được đưa lên trước',
        'F07: PHP ingredients không được đọc đúng field',
        'F08: gộp sau tối ưu làm vượt tổng ngân sách',
        'F09: thiếu kiểm tra cuối cho thành phần cần tránh'],True,24)
    d.box(740,1605,960,205,'7. Tạo context và prompt',[
        'F04: tự sinh giảm giá 10–25% từ mã sản phẩm',
        'F05: hướng dẫn sử dụng ngẫu nhiên theo category',
        'Ingredients 300 ký tự; mô tả 250 ký tự',
        'Prompt đưa bằng HumanMessage; chưa có schema đầu ra'],True,24)
    d.box(740,1870,960,195,'8. LLM generation / failover',[
        'Groq → OpenAI → Gemini → Zhipu → OpenRouter',
        'Phụ thuộc key và test kết nối thành công',
        'temperature=0 · max_tokens=4096 · max_retries=0'],size=25)
    d.box(70,1030,540,230,'F00 · Hai bộ pipeline tồn tại',[
        'pipeline.py / agent.py / llm_pool.py có code riêng',
        'Route hiện tại không gọi các module đó',
        'Gunicorn warm-up retrieval.py nhưng request dùng factory khác'],True,24)
    d.box(70,1325,540,245,'F06 · Lỗi giỏ hàng có cảnh báo',[
        'PHP gửi cart_conflicts = list object',
        'Python dùng join(list object) → TypeError',
        'API lỗi; PHP có thể trả câu mặc định “Tôi đề xuất những sản phẩm này”'],True,24)
    d.box(70,1680,540,335,'9. Trả lời → lưu / cache',[
        'F12: điểm đánh giá chỉ là trùng từ',
        'Câu mâu thuẫn vẫn có thể đạt 1,0',
        'Mongo lưu history cho người đăng nhập',
        'PHP có thể cache cả conversation_id',
        'Người dùng nhận answer + product cards'],size=25)
    d.text(70,2110,'16 tình huống lỗi đã tái hiện offline · Xem README.md và JSON để biết dữ liệu kiểm tra, giới hạn và cách sửa.',24,False,MUTED)
    d.save('current-flow')


def proposed():
    d=Diagram('LUỒNG ĐỀ XUẤT · AI TƯ VẤN ROUTINE CÓ KIỂM CHỨNG', 'Một workflow LangGraph · Tái sử dụng PHP + Flask + MongoDB · Thông số là khởi điểm thử nghiệm, chưa áp dụng')
    d.arrow([(340,415),(340,485)])
    for cy in [225,412,618]:
        d.arrow([(610,615),(670,615),(670,cy),(740,cy)])
    d.arrow([(340,740),(340,800)],label='Thiếu dữ kiện',label_at=(365,758))
    d.arrow([(70,920),(35,920),(35,270),(70,270)])
    d.arrow([(1220,720),(1220,785)])
    d.arrow([(1700,412),(1730,412),(1730,865),(1700,865)],label='Kho kiến thức riêng',label_at=(1450,746))
    d.arrow([(1220,1030),(1220,1090)])
    d.arrow([(1220,1300),(1220,1360)])
    d.arrow([(1220,1540),(1220,1600)])
    d.arrow([(1220,1785),(1220,1840)])
    d.arrow([(740,1940),(670,1940),(670,2050),(610,2050)])
    d.arrow([(1700,225),(1765,225),(1765,2140),(340,2140),(340,2110)])
    d.arrow([(1700,1240),(1747,1240),(1747,1688),(1700,1688)],label='Knowledge bỏ qua chọn routine',label_at=(1260,1564))
    d.arrow([(740,1990),(690,1990),(690,1490),(610,1490)])
    d.arrow([(740,1200),(704,1200),(704,1665),(740,1665)],label='Product detail',label_at=(750,1320))
    d.arrow([(610,1555),(645,1555),(645,325),(610,325)])
    d.box(70,150,540,265,'1. Context chuẩn · PHP → Flask',[
        'Pydantic v2 · JSON object trực tiếp',
        'user / conversation / request ID',
        'profile version + routine version',
        'Đồ đang dùng ≠ giỏ hàng ≠ đã mua',
        'History canonical: role + content'],size=25)
    d.box(70,485,540,255,'2. LangGraph · Route + state',[
        'Một structured call: intent + constraints',
        'Subject; budget; exclusion; references',
        'Luật kiểm tra dữ kiện cần thiết',
        'Lưu pending_question, mục tiêu gốc',
        'Extractor: 400–700 output tokens'],size=24)
    d.box(70,800,540,205,'Hỏi bổ sung / chuyển chuyên gia',[
        'Mỗi lượt 1–2 câu liên quan quyết định',
        'Dừng và giải thích khi ngoài phạm vi',
        'Câu trả lời cập nhật state có cấu trúc'],size=25)
    d.box(740,150,960,155,'A. GREETING / OUT_OF_SCOPE',[
        'Trả lời ngắn hoặc hướng dẫn phạm vi hỗ trợ',
        'Không tự tìm web / chèn sản phẩm bán hàng'],size=25)
    d.box(740,355,960,155,'B. KNOWLEDGE',[
        'RAG trên nguồn đã kiểm duyệt · có source_id',
        'Thiếu chứng cứ → nói rõ; tra nguồn allowlist nếu cần'],size=25)
    d.box(740,560,960,160,'C. PRODUCT / ROUTINE / CHECK-IN',[
        'Exact product ID trước; truy xuất đúng người/routine',
        'Tra giá, tồn kho, INCI, nhãn và lịch sử phản hồi từ DB'],size=25)
    d.box(740,785,960,245,'3. Truy xuất bằng chứng + sản phẩm',[
        'Benchmark E5-small: 384d · CPU · query:/passage:',
        'Dense top30 + BM25Okapi top30 · k1=1,5; b=0,75',
        'Chroma cosine; normalize cả index và query',
        'Weighted RRF: k=60; α=0,5 · cùng filter hai nhánh',
        'Knowledge chunk 250–350 tokens; overlap 50'],size=24)
    d.box(740,1090,960,210,'4. Gộp → hydrate → lọc → rerank',[
        'Stable product_id; dữ kiện thiếu = unknown',
        'Giữ budget / exclusion / category / stock',
        'Cross-encoder một lượt: top30; batch8; max_length512',
        'Knowledge top6; routine 3–5 ứng viên mỗi vai trò'],size=24)
    d.box(740,1360,960,180,'5. Routine planner · Python / OR-Tools',[
        'Tận dụng đồ đang có; tối ưu phần phải mua thêm',
        'Tổng tiền bằng integer VND; kiểm tra cả tổ hợp',
        'Solver 0,5s; phân biệt feasible / infeasible / unknown'],size=25)
    d.box(740,1600,960,185,'6. LLM giải thích plan đã kiểm tra',[
        'Một primary + tối đa một fallback đã benchmark',
        'Structured Outputs: claims + sources + product_ids',
        '800–1200 output tokens; context mục tiêu ≤6000'],size=25)
    d.box(740,1840,960,210,'7. Validator đầu ra · bắt buộc',[
        'Schema; ID; nguồn; tổng tiền; thành phần cần tránh',
        'Sai → repair tối đa 1 lần nếu còn deadline',
        'Vẫn sai → trả giới hạn rõ, không giả vờ tư vấn xong',
        'Giá/card render từ dữ liệu server, không từ prose'],size=24)
    d.box(70,1070,540,245,'Dữ liệu tri thức được chuẩn bị',[
        'Nhãn hãng + nguồn chăm sóc da',
        'INCI đầy đủ; HDSD; ngày kiểm tra',
        'Rule có nguồn + điều kiện áp dụng',
        'Index version + embedding fingerprint',
        'Đổi embedding → collection mới'],size=24)
    d.box(70,1375,540,235,'8. MongoDB + checkpoints',[
        'Profile / routine có phiên bản',
        'Survey state độc lập với văn bản chat',
        'Check-in: trải nghiệm, tuân thủ, thay đổi',
        'Phản hồi cập nhật context lượt sau'],size=24)
    d.box(70,1670,540,240,'Vận hành và đo chất lượng',[
        'Deadline tổng 30s; call ≤12s và thời gian còn lại',
        'Tắt cache response cá nhân hóa',
        'Trace stage/model/docs/reject reasons',
        'Recall/MRR; constraints; p50/p95'],size=24)
    d.box(70,1970,540,140,'9. Người dùng nhận kế hoạch',[
        'Sáng/tối · lý do · nguồn · bước tiếp theo'],size=23)
    d.save('proposed-flow')


if __name__ == '__main__':
    current()
    proposed()
    print('Created current-flow and proposed-flow in PNG + SVG.')
