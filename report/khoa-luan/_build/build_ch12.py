# -*- coding: utf-8 -*-
"""Sinh file Word Mở đầu + Chương 1 + Chương 2 theo mẫu KLTN (IUH)."""
import re, sys
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING, WD_TAB_ALIGNMENT, WD_COLOR_INDEX, WD_BREAK
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

OUT = sys.argv[1]
FONT = "Times New Roman"
doc = Document()

# ---------- trang & style ----------
for s in doc.sections:
    s.page_height, s.page_width = Cm(29.7), Cm(21)
    s.top_margin, s.bottom_margin = Cm(3), Cm(3)
    s.left_margin, s.right_margin = Cm(3.5), Cm(2)

def set_font(style, size, bold=None, italic=None, caps=False):
    f = style.font
    f.name, f.size = FONT, Pt(size)
    f.bold, f.italic, f.all_caps = bold, italic, caps
    f.color.rgb = RGBColor(0, 0, 0)
    rpr = style.element.get_or_add_rPr()
    rf = rpr.find(qn("w:rFonts"))
    if rf is None:
        rf = OxmlElement("w:rFonts"); rpr.append(rf)
    for a in ("w:ascii", "w:hAnsi", "w:eastAsia", "w:cs"):
        rf.set(qn(a), FONT)

def set_para(style, before, after, line, align):
    pf = style.paragraph_format
    pf.space_before, pf.space_after = Pt(before), Pt(after)
    pf.line_spacing = line
    pf.first_line_indent = Cm(0)
    pf.left_indent = Cm(0)
    pf.alignment = align

st = doc.styles
set_font(st["Normal"], 13); set_para(st["Normal"], 10, 0, 1.5, WD_ALIGN_PARAGRAPH.JUSTIFY)
set_font(st["Heading 1"], 14, True, False, True); set_para(st["Heading 1"], 24, 24, 1.15, WD_ALIGN_PARAGRAPH.LEFT)
set_font(st["Heading 2"], 13, True, False); set_para(st["Heading 2"], 6, 12, 1.15, WD_ALIGN_PARAGRAPH.LEFT)
set_font(st["Heading 3"], 13, True, True); set_para(st["Heading 3"], 6, 12, 1.15, WD_ALIGN_PARAGRAPH.LEFT)
set_font(st["Heading 4"], 13, False, True); set_para(st["Heading 4"], 6, 12, 1.15, WD_ALIGN_PARAGRAPH.LEFT)
set_font(st["Caption"], 13, False, False); set_para(st["Caption"], 6, 12, 1.15, WD_ALIGN_PARAGRAPH.CENTER)
set_font(st["List Bullet"], 13); set_para(st["List Bullet"], 0, 0, 1.5, WD_ALIGN_PARAGRAPH.LEFT)
for h in ("Heading 1", "Heading 2", "Heading 3", "Heading 4"):
    st[h].paragraph_format.keep_with_next = True

# số trang góc phải dưới
fp = doc.sections[0].footer.paragraphs[0]
fp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
r = fp.add_run()
for tag, txt in (("begin", None), (None, "PAGE"), ("end", None)):
    if tag:
        e = OxmlElement("w:fldChar"); e.set(qn("w:fldCharType"), tag)
    else:
        e = OxmlElement("w:instrText"); e.set(qn("xml:space"), "preserve"); e.text = txt
    r._r.append(e)
hp = doc.sections[0].header.paragraphs[0]
hp.text = "Khóa luận tốt nghiệp – SkinSyntaxVN"; hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
for rr in hp.runs: rr.font.size = Pt(10); rr.font.italic = True

TOKEN = re.compile(r"(\[\[.*?\]\]|\*\*.*?\*\*)")

def runs(p, text, size=None):
    for part in TOKEN.split(text):
        if not part:
            continue
        if part.startswith("[["):
            r = p.add_run(part[2:-2]); r.font.highlight_color = WD_COLOR_INDEX.YELLOW
        elif part.startswith("**"):
            r = p.add_run(part[2:-2]); r.bold = True
        else:
            r = p.add_run(part)
        if size: r.font.size = Pt(size)
    return p

def H1(t): doc.add_paragraph(t, style="Heading 1")
def H2(t): doc.add_paragraph(t, style="Heading 2")
def H3(t): doc.add_paragraph(t, style="Heading 3")
def H4(t): doc.add_paragraph(t, style="Heading 4")
def P(t): runs(doc.add_paragraph(style="Normal"), t)
def B(*items):
    for t in items: runs(doc.add_paragraph(style="List Bullet"), t)
def PB():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)

def shade(cell, hexcolor):
    tcPr = cell._tc.get_or_add_tcPr()
    sh = OxmlElement("w:shd"); sh.set(qn("w:val"), "clear"); sh.set(qn("w:color"), "auto"); sh.set(qn("w:fill"), hexcolor)
    tcPr.append(sh)

def T(caption, header, rows, widths=None, size=11):
    runs(doc.add_paragraph(style="Caption"), caption).paragraph_format.keep_with_next = True
    t = doc.add_table(rows=1, cols=len(header)); t.style = "Table Grid"; t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(header):
        c = t.rows[0].cells[i]; c.text = ""
        p = c.paragraphs[0]; p.paragraph_format.space_before = Pt(2); p.paragraph_format.line_spacing = 1.15
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        rr = runs(p, h, size); [setattr(x, "bold", True) for x in p.runs]
        shade(c, "D9E2F3")
    for row in rows:
        cells = t.add_row().cells
        for i, v in enumerate(row):
            p = cells[i].paragraphs[0]; p.paragraph_format.space_before = Pt(2); p.paragraph_format.line_spacing = 1.15
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT
            runs(p, v, size)
    if widths:
        for row in t.rows:
            for i, w in enumerate(widths): row.cells[i].width = Cm(w)
    doc.add_paragraph(style="Normal").paragraph_format.space_before = Pt(0)

FIG_DIR = __import__("os").path.join(__import__("os").path.dirname(__import__("os").path.abspath(sys.argv[0])), "figs")

def F(caption, todo):
    import os
    m = re.match(r"Hình\s+(\S+)", caption)
    img = os.path.join(FIG_DIR, m.group(1) + ".png") if m else None
    if img and os.path.exists(img):
        from PIL import Image
        w, h = Image.open(img).size
        p = doc.add_paragraph(); p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        p.paragraph_format.first_line_indent = Cm(0); p.paragraph_format.keep_with_next = True
        if h / w * 15 > 19:
            p.add_run().add_picture(img, height=Cm(19))
        else:
            p.add_run().add_picture(img, width=Cm(15))
        runs(doc.add_paragraph(style="Caption"), caption)
        return
    t = doc.add_table(rows=1, cols=1); t.style = "Table Grid"; t.alignment = WD_TABLE_ALIGNMENT.CENTER
    c = t.rows[0].cells[0]; c.width = Cm(15)
    p = c.paragraphs[0]; p.alignment = WD_ALIGN_PARAGRAPH.LEFT; p.paragraph_format.line_spacing = 1.15
    runs(p, "[[CHÈN HÌNH – nhóm tự vẽ bằng draw.io/StarUML/Figma, không dùng AI vẽ.]] " + todo, 11)
    for _ in range(3): c.add_paragraph()
    runs(doc.add_paragraph(style="Caption"), caption)

def EQ(formula, num):
    p = doc.add_paragraph(style="Normal")
    ts = p.paragraph_format.tab_stops
    ts.add_tab_stop(Cm(7.75), WD_TAB_ALIGNMENT.CENTER); ts.add_tab_stop(Cm(15.5), WD_TAB_ALIGNMENT.RIGHT)
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    r = p.add_run("\t" + formula + "\t(" + num + ")"); r.italic = False

exec(open(sys.argv[2], encoding="utf-8").read())
doc.save(OUT)
print("saved", OUT)
