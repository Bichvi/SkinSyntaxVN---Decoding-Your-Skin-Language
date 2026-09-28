import time
import requests
import json

url = "http://127.0.0.1/index.php?r=home"
times = []
print("Testing route ?r=home 3 times...")
for i in range(3):
    t0 = time.perf_counter()
    resp = requests.get(url, timeout=60)
    elapsed = (time.perf_counter() - t0) * 1000
    print(f"  Run {i+1}: {elapsed:.2f} ms (Status: {resp.status_code})")
    times.append(elapsed)
    time.sleep(0.5)

avg = sum(times) / len(times)
print(f"\nAverage: {avg:.2f} ms")
print(json.dumps({"runs": times, "avg": avg}, indent=2))
