import requests
import re

resp = requests.get("http://127.0.0.1/index.php?r=home", timeout=30)
html = resp.text
# Strip dynamic timestamp query params if any (like ?v=123456789)
cleaned = re.sub(r'\?v=\d+', '?v=DYNAMIC', html)
with open("backend/tests/baseline_home.html", "w", encoding="utf-8") as f:
    f.write(cleaned)
print(f"Captured baseline home HTML: {len(html)} bytes, status: {resp.status_code}")
