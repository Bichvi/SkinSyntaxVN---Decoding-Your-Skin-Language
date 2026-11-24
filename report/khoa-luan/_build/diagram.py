# -*- coding: utf-8 -*-
"""Bộ vẽ sơ đồ kiểu draw.io bằng matplotlib (UML activity, flowchart, swimlane)."""
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.patches import FancyBboxPatch, Polygon, Circle, Rectangle

plt.rcParams["font.family"] = "Arial"
FS = 8.5
ACT = ("#dae8fc", "#6c8ebf")   # draw.io xanh
DEC = ("#fff2cc", "#d6b656")   # draw.io vàng
NOTE = ("#f5f5f5", "#666666")
DATA = ("#d5e8d4", "#82b366")  # draw.io xanh lá
EXT = ("#f8cecc", "#b85450")   # draw.io đỏ nhạt


class Diagram:
    def __init__(self, w, h, scale=0.62):
        self.w, self.h = w, h
        self.fig = plt.figure(figsize=(w * scale, h * scale), dpi=200)
        self.ax = self.fig.add_axes([0, 0, 1, 1])
        self.ax.set_xlim(0, w); self.ax.set_ylim(0, h); self.ax.axis("off")
        self.n = {}
        self.scale = scale
        self.ymin = 0

    # ---------- khung ----------
    def lanes(self, names, top=None, bottom=0.2, head=0.55, left=0.2, right=None):
        right = right if right is not None else self.w - 0.2
        top = top if top is not None else self.h - 0.2
        lw = (right - left) / len(names)
        xs = []
        for i, name in enumerate(names):
            x0 = left + i * lw
            self.ax.add_patch(Rectangle((x0, bottom), lw, top - bottom, fill=False, ec="#333333", lw=0.9))
            self.ax.add_patch(Rectangle((x0, top - head), lw, head, fc="#f5f5f5", ec="#333333", lw=0.9))
            self.ax.text(x0 + lw / 2, top - head / 2, name, ha="center", va="center", fontsize=FS + 0.5, weight="bold")
            xs.append(x0 + lw / 2)
        return xs

    # ---------- nút ----------
    def _add(self, key, x, y, w, h, kind):
        self.n[key] = dict(x=x, y=y, w=w, h=h, kind=kind)

    def act(self, key, x, y, text, w=2.9, h=0.75, style=ACT, rounded=True):
        pad = 0.12 if rounded else 0.0
        self.ax.add_patch(FancyBboxPatch((x - w / 2 + pad, y - h / 2 + pad), w - 2 * pad, h - 2 * pad,
                                         boxstyle=f"round,pad={pad}" if rounded else "square,pad=0",
                                         fc=style[0], ec=style[1], lw=1))
        self.ax.text(x, y, text, ha="center", va="center", fontsize=FS, linespacing=1.15)
        self._add(key, x, y, w, h, "box")

    def box(self, key, x, y, text, w=2.9, h=0.75, style=ACT):
        self.act(key, x, y, text, w, h, style, rounded=False)

    def db(self, key, x, y, text, w=2.2, h=0.9, style=DATA):
        e = 0.16
        self.ax.add_patch(Rectangle((x - w / 2, y - h / 2 + e), w, h - 2 * e, fc=style[0], ec=style[1], lw=1))
        from matplotlib.patches import Ellipse
        self.ax.add_patch(Ellipse((x, y - h / 2 + e), w, 2 * e, fc=style[0], ec=style[1], lw=1))
        self.ax.add_patch(Rectangle((x - w / 2 + 0.01, y - h / 2 + e), w - 0.02, 0.02, fc=style[0], ec=style[0]))
        self.ax.add_patch(Ellipse((x, y + h / 2 - e), w, 2 * e, fc=style[0], ec=style[1], lw=1))
        self.ax.text(x, y - 0.05, text, ha="center", va="center", fontsize=FS)
        self._add(key, x, y, w, h, "box")

    def dec(self, key, x, y, text="", w=1.5, h=0.9):
        self.ax.add_patch(Polygon([(x, y + h / 2), (x + w / 2, y), (x, y - h / 2), (x - w / 2, y)],
                                  closed=True, fc=DEC[0], ec=DEC[1], lw=1))
        if text:
            self.ax.text(x, y, text, ha="center", va="center", fontsize=FS - 0.5, linespacing=1.1)
        self._add(key, x, y, w, h, "dia")

    def start(self, key, x, y, r=0.17):
        self.ax.add_patch(Circle((x, y), r, fc="black", ec="black"))
        self._add(key, x, y, 2 * r, 2 * r, "dot")

    def end(self, key, x, y, r=0.19):
        self.ax.add_patch(Circle((x, y), r, fc="white", ec="black", lw=1.2))
        self.ax.add_patch(Circle((x, y), r * 0.6, fc="black", ec="black"))
        self._add(key, x, y, 2 * r, 2 * r, "dot")

    def frame(self, x0, y0, x1, y1, text):
        self.ax.add_patch(Rectangle((x0, y0), x1 - x0, y1 - y0, fill=False, ec="#666666", lw=0.9, ls=(0, (5, 3))))
        self.ax.text(x0 + 0.12, y1 - 0.12, text, ha="left", va="top", fontsize=FS, weight="bold", color="#333333")

    def label(self, x, y, text, size=None, **kw):
        self.ax.text(x, y, text, fontsize=size or FS - 0.5, **kw)

    # ---------- cạnh ----------
    def _anchor(self, k, tx, ty):
        n = self.n[k]
        x, y, w, h = n["x"], n["y"], n["w"], n["h"]
        dx, dy = tx - x, ty - y
        if abs(dx) * h >= abs(dy) * w:
            return (x + (w / 2 if dx > 0 else -w / 2), y)
        return (x, y + (h / 2 if dy > 0 else -h / 2))

    def edge(self, a, b, label=None, via=None, mode="v", lpos=None, dashed=False):
        """mode 'v': đi dọc rồi ngang; 'h': ngang rồi dọc; via: danh sách điểm gấp."""
        A, B = self.n[a], self.n[b]
        def inx(N, x): return N["x"] - N["w"] / 2 + 0.05 < x < N["x"] + N["w"] / 2 - 0.05
        def iny(N, y): return N["y"] - N["h"] / 2 + 0.05 < y < N["y"] + N["h"] / 2 - 0.05
        if via is None:
            if abs(A["x"] - B["x"]) < 1e-6 or abs(A["y"] - B["y"]) < 1e-6:
                via = []
            elif inx(B, A["x"]) and abs(A["y"] - B["y"]) > (A["h"] + B["h"]) / 2:
                sx = A["x"]; sgn = 1 if B["y"] > A["y"] else -1
                pts = [(sx, A["y"] + sgn * A["h"] / 2), (sx, B["y"] - sgn * B["h"] / 2)]
                return self._draw(pts, label, lpos, dashed)
            elif mode == "v":
                via = [(A["x"], B["y"])]
            else:
                via = [(B["x"], A["y"])]
        first = via[0] if via else (B["x"], B["y"])
        last = via[-1] if via else (A["x"], A["y"])
        sp = self._anchor(a, *first)
        if via and inx(A, first[0]) and not iny(A, first[1]):
            sp = (first[0], A["y"] + (A["h"] / 2 if first[1] > A["y"] else -A["h"] / 2))
        ep = self._anchor(b, *last)
        if via and inx(B, last[0]) and not iny(B, last[1]):
            ep = (last[0], B["y"] + (B["h"] / 2 if last[1] > B["y"] else -B["h"] / 2))
        elif via and iny(B, last[1]) and not inx(B, last[0]):
            ep = (B["x"] + (B["w"] / 2 if last[0] > B["x"] else -B["w"] / 2), last[1])
        pts = [sp] + list(via) + [ep]
        return self._draw(pts, label, lpos, dashed)

    def _draw(self, pts, label, lpos, dashed):
        ls = (0, (4, 3)) if dashed else "-"
        for p, q in zip(pts[:-2], pts[1:-1]):
            self.ax.plot([p[0], q[0]], [p[1], q[1]], color="black", lw=0.9, ls=ls, solid_capstyle="butt")
        self.ax.annotate("", xy=pts[-1], xytext=pts[-2],
                         arrowprops=dict(arrowstyle="-|>,head_length=0.5,head_width=0.25", color="black", lw=0.9, ls=ls,
                                         shrinkA=0, shrinkB=0))
        if label:
            if lpos is None:
                p, q = pts[0], pts[1]
                lpos = ((p[0] + q[0]) / 2 + 0.08, (p[1] + q[1]) / 2 + 0.06)
            self.ax.text(*lpos, label, fontsize=FS - 1, ha="left", va="bottom",
                         bbox=dict(fc="white", ec="none", pad=0.6))

    # ---------- UML use case / đồ thị ----------
    def actor(self, key, x, y, name, s=1.0):
        import numpy as np
        hr = 0.17 * s
        self.ax.add_patch(Circle((x, y + 0.42 * s), hr, fc="white", ec="black", lw=1))
        k = dict(color="black", lw=1)
        self.ax.plot([x, x], [y + 0.25 * s, y - 0.12 * s], **k)
        self.ax.plot([x - 0.3 * s, x + 0.3 * s], [y + 0.12 * s, y + 0.12 * s], **k)
        self.ax.plot([x - 0.25 * s, x, x + 0.25 * s], [y - 0.5 * s, y - 0.12 * s, y - 0.5 * s], **k)
        self.ax.text(x, y - 0.62 * s, name, ha="center", va="top", fontsize=FS, linespacing=1.1)
        self._add(key, x, y - 0.3 * s, 0.9 * s, 1.75 * s, "box")

    def uc(self, key, x, y, text, w=3.0, h=0.8, style=ACT):
        from matplotlib.patches import Ellipse
        self.ax.add_patch(Ellipse((x, y), w, h, fc=style[0], ec=style[1], lw=1, zorder=2))
        self.ax.text(x, y, text, ha="center", va="center", fontsize=FS, linespacing=1.1, zorder=3)
        self._add(key, x, y, w, h, "ell")

    def node(self, key, x, y, text, r=0.55, fc="#dae8fc", ec="#6c8ebf"):
        self.ax.add_patch(Circle((x, y), r, fc=fc, ec=ec, lw=1.2))
        self.ax.text(x, y, text, ha="center", va="center", fontsize=FS, weight="bold")
        self._add(key, x, y, 2 * r, 2 * r, "circ")

    def _clip(self, k, tx, ty):
        import math
        n = self.n[k]
        x, y, w, h = n["x"], n["y"], n["w"], n["h"]
        dx, dy = tx - x, ty - y
        if dx == 0 and dy == 0:
            return (x, y)
        if n["kind"] in ("ell", "circ", "dot"):
            t = 1 / math.sqrt((dx / (w / 2)) ** 2 + (dy / (h / 2)) ** 2)
        else:
            t = min((w / 2) / abs(dx) if dx else 1e9, (h / 2) / abs(dy) if dy else 1e9)
        return (x + dx * t, y + dy * t)

    def link(self, a, b, kind="assoc", label=None, lpos=None, rot=False):
        """assoc: nét liền không mũi tên; arrow: mũi tên đặc; dep: nét đứt mũi tên mở;
        gen: nét liền tam giác rỗng (kế thừa)."""
        A, B = self.n[a], self.n[b]
        p = self._clip(a, B["x"], B["y"]); q = self._clip(b, A["x"], A["y"])
        if kind == "assoc":
            self.ax.plot([p[0], q[0]], [p[1], q[1]], color="black", lw=0.9, zorder=1)
        else:
            style = {"arrow": "-|>,head_length=0.5,head_width=0.25", "dep": "->,head_length=0.5,head_width=0.25",
                     "gen": "-|>,head_length=0.8,head_width=0.45"}[kind]
            self.ax.annotate("", xy=q, xytext=p, arrowprops=dict(
                arrowstyle=style, color="black", fc="white" if kind == "gen" else "black", lw=0.9,
                ls=(0, (4, 3)) if kind == "dep" else "-", shrinkA=0, shrinkB=0), zorder=1)
        if label:
            import math
            mx, my = lpos if lpos else ((p[0] + q[0]) / 2, (p[1] + q[1]) / 2)
            ang = math.degrees(math.atan2(q[1] - p[1], q[0] - p[0])) if rot else 0
            if ang > 90: ang -= 180
            if ang < -90: ang += 180
            self.ax.text(mx, my, label, fontsize=FS - 1, ha="center", va="center", rotation=ang,
                         bbox=dict(fc="white", ec="none", pad=0.5), zorder=4)

    def loop(self, k, label, side="top", size=0.7):
        n = self.n[k]; x, y, r = n["x"], n["y"], n["w"] / 2
        s = 1 if side == "top" else -1
        a, b = (x + r * 0.6, y + s * r * 0.8), (x - r * 0.6, y + s * r * 0.8)
        top = y + s * (r + size)
        self.ax.plot([a[0], a[0], b[0]], [a[1], top, top], color="black", lw=0.9)
        self.ax.annotate("", xy=b, xytext=(b[0], top), arrowprops=dict(
            arrowstyle="-|>,head_length=0.5,head_width=0.25", color="black", lw=0.9, shrinkA=0, shrinkB=0))
        self.ax.text(x, top + s * 0.05, label, fontsize=FS - 1, ha="center", va="bottom" if s > 0 else "top",
                     bbox=dict(fc="white", ec="none", pad=0.5))

    def line(self, pts, label=None, lpos=None, dashed=False):
        return self._draw(pts, label, lpos, dashed)

    def save(self, path):
        if self.ymin:
            self.ax.set_ylim(self.ymin, self.h)
            self.fig.set_size_inches(self.w * self.scale, (self.h - self.ymin) * self.scale)
        self.fig.savefig(path, dpi=200, facecolor="white")
        plt.close(self.fig)
