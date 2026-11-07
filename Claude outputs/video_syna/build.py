"""Dựng video MP4 giới thiệu Syna: slide HTML -> PNG (Edge headless), lời đọc -> WAV (Piper tiếng Việt
trong container skinsyntax-ai-idol), ghép bằng FFmpeg. Chạy: python build.py"""
import html, subprocess, sys
from pathlib import Path

from scenes import SCENES

ROOT = Path(__file__).resolve().parent
OUT = ROOT / "build"
OUT.mkdir(exist_ok=True)
EDGE = r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
CONTAINER = "skinsyntax-ai-idol"
VOICE = "/opt/piper/vi_VN-vais1000-medium.onnx"

CSS = """
*{box-sizing:border-box;margin:0;padding:0}
body{width:1920px;height:1080px;overflow:hidden;font-family:'Segoe UI',Arial,sans-serif;color:#1d2433;
 background:linear-gradient(135deg,#fff7f3 0%,#fdeef0 45%,#eef4ff 100%);padding:70px 90px;position:relative}
.kicker{font-size:30px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:#d0567a}
h1{font-size:72px;font-weight:800;margin:10px 0 44px;color:#1d2433}
.row{display:flex;align-items:center;gap:16px;margin:22px 0;flex-wrap:nowrap}
.box{flex:1;min-width:0;background:#fff;border:3px solid #e6d5dc;border-radius:22px;padding:22px 18px;text-align:center;
 box-shadow:0 8px 24px rgba(160,80,110,.10)}
.box b{display:block;font-size:30px}.box span{display:block;font-size:22px;color:#5b6475;margin-top:8px}
.box.hl{border-color:#d0567a;background:#fff3f6}.box.hl2{border-color:#4f7cff;background:#f2f6ff}
.arrow{font-size:26px;font-weight:800;color:#d0567a;white-space:nowrap}
.why{display:grid;gap:18px;margin-top:26px}
.w{display:flex;gap:24px;background:#fff;border-radius:20px;padding:22px 28px;border-left:10px solid #4f7cff;box-shadow:0 6px 18px rgba(0,0,0,.05)}
.w .q{flex:0 0 360px;font-size:30px;font-weight:800;color:#2b4fc7}.w .a{font-size:28px;line-height:1.35}
.grid5{display:grid;grid-template-columns:repeat(5,1fr);gap:22px}
.card{background:#fff;border-radius:26px;padding:34px 24px;min-height:330px;box-shadow:0 10px 30px rgba(160,80,110,.12)}
.card .n{width:64px;height:64px;border-radius:50%;background:#d0567a;color:#fff;font-size:34px;font-weight:800;
 display:flex;align-items:center;justify-content:center;margin-bottom:22px}
.card b{display:block;font-size:34px;margin-bottom:14px}.card span{font-size:26px;color:#5b6475}
.motto{margin-top:46px;font-size:34px;font-weight:700;color:#fff;background:#1d2433;border-radius:20px;padding:26px 34px}
.note{margin-top:34px;font-size:30px;color:#3b4456;background:#fff;border-radius:18px;padding:22px 30px;border:2px dashed #d0567a}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.st{background:#fff;border-radius:22px;padding:24px 26px;box-shadow:0 6px 18px rgba(0,0,0,.06)}
.st i{font-style:normal;display:inline-block;background:#4f7cff;color:#fff;border-radius:12px;padding:2px 16px;font-size:26px;font-weight:800}
.st b{display:block;font-size:32px;margin:12px 0 8px}.st span{font-size:25px;color:#5b6475}
.tools{display:flex;flex-wrap:wrap;gap:12px;margin:18px 0}
.tools span{font-family:Consolas,monospace;font-size:24px;background:#1d2433;color:#ffd6e2;border-radius:10px;padding:8px 14px}
.q1{font-size:40px;font-style:italic;color:#d0567a;margin-bottom:10px}
.foot{position:absolute;left:90px;right:90px;bottom:36px;display:flex;justify-content:space-between;align-items:center;font-size:24px;color:#8a8fa0}
.bar{flex:1;height:8px;background:#ead9df;border-radius:4px;margin:0 30px;overflow:hidden}.bar div{height:100%;background:#d0567a}
"""


def slide(i, s):
    n = len(SCENES)
    return (f"<!doctype html><html><head><meta charset='utf-8'><style>{CSS}</style></head><body>"
            f"<div class='kicker'>{html.escape(s['kicker'])}</div><h1>{html.escape(s['title'])}</h1>{s['body']}"
            f"<div class='foot'><span>SkinSyntaxVN · Syna</span><div class='bar'><div style='width:{(i + 1) / n * 100:.1f}%'></div></div>"
            f"<span>{i + 1}/{n}</span></div></body></html>")


def run(cmd, **kw):
    r = subprocess.run(cmd, capture_output=True, **kw)
    if r.returncode != 0:
        sys.exit(f"Lỗi: {cmd[0]}\n{r.stderr.decode('utf8', 'replace')[-1500:]}")
    return r


def duration(path):
    r = run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)])
    return float(r.stdout.decode().strip())


segments = []
for i, s in enumerate(SCENES):
    tag = f"s{i + 1:02d}"
    htm, png, wav, mp4 = (OUT / f"{tag}.{e}" for e in ("html", "png", "wav", "mp4"))
    htm.write_text(slide(i, s), encoding="utf8")
    run([EDGE, "--headless=new", "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
         "--window-size=1920,1080", f"--screenshot={png}", htm.as_uri()])
    run(["docker", "exec", "-i", CONTAINER, "piper", "--model", VOICE, "--sentence_silence", "0.35",
         "--output_file", f"/tmp/{tag}.wav"], input=s["say"].encode("utf8"))
    run(["docker", "cp", f"{CONTAINER}:/tmp/{tag}.wav", str(wav)])
    run(["docker", "cp", str(png), f"{CONTAINER}:/tmp/{tag}.png"])
    total = duration(wav) + 1.2
    # FFmpeg trên máy quá cũ, nên mã hóa bằng FFmpeg 7 trong container.
    run(["docker", "exec", CONTAINER, "ffmpeg", "-y", "-loop", "1", "-framerate", "25", "-i", f"/tmp/{tag}.png",
         "-i", f"/tmp/{tag}.wav", "-af", "adelay=500:all=1,apad", "-t", f"{total:.2f}",
         "-vf", "fade=in:0:10,format=yuv420p", "-c:v", "libx264", "-tune", "stillimage", "-preset", "veryfast",
         "-c:a", "aac", "-b:a", "128k", "-ar", "44100", "-ac", "2", f"/tmp/{tag}.mp4"])
    segments.append(f"/tmp/{tag}.mp4")
    print(f"{tag}: {total:.1f}s", flush=True)

lst = OUT / "list.txt"
lst.write_text("".join(f"file '{p}'\n" for p in segments), encoding="utf8")
run(["docker", "cp", str(lst), f"{CONTAINER}:/tmp/syna_list.txt"])
run(["docker", "exec", CONTAINER, "ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", "/tmp/syna_list.txt",
     "-c", "copy", "-movflags", "+faststart", "/tmp/syna_final.mp4"])
final = ROOT / "Syna_gioi_thieu_chatbot.mp4"
run(["docker", "cp", f"{CONTAINER}:/tmp/syna_final.mp4", str(final)])
run(["docker", "exec", CONTAINER, "sh", "-c", "rm -f /tmp/s[0-9][0-9].* /tmp/syna_list.txt /tmp/syna_final.mp4"])
print(f"XONG: {final} ({duration(final):.0f}s)")
