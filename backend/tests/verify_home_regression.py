import requests
import re
import sys

resp = requests.get("http://127.0.0.1/index.php?r=home", timeout=30)
html = resp.text
cleaned = re.sub(r'\?v=\d+', '?v=DYNAMIC', html).replace('\r\n', '\n')

with open("backend/tests/baseline_home.html", "r", encoding="utf-8") as f:
    baseline = f.read().replace('\r\n', '\n')

if cleaned == baseline:
    print("REGRESSION SUCCESS: Home HTML matches baseline 100%!")
else:
    print(f"DIFFERENCE DETECTED! Current length: {len(cleaned)}, baseline length: {len(baseline)}")
    for i in range(min(len(cleaned), len(baseline))):
        if cleaned[i] != baseline[i]:
            print(f"First diff at pos {i}:")
            print("Baseline:", repr(baseline[max(0, i-30):i+30]))
            print("Current: ", repr(cleaned[max(0, i-30):i+30]))
            break
    sys.exit(1)
