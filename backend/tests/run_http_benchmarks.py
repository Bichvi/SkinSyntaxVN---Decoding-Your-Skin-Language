import time
import requests
import json

base_url = "http://127.0.0.1"
routes = [
    ("?r=home", "Trang chu"),
    ("?r=chitiet&id=111", "Chi tiet san pham"),
    ("?r=goiy", "Goi y san pham")
]

results = {}

for query, name in routes:
    url = f"{base_url}/index.php{query}"
    times = []
    print(f"Testing route {query}...")
    for i in range(3):
        t0 = time.perf_counter()
        resp = requests.get(url, timeout=60)
        elapsed = (time.perf_counter() - t0) * 1000
        print(f"  Run {i+1}: {elapsed:.2f} ms (Status: {resp.status_code})")
        times.append(elapsed)
        time.sleep(0.5)
    avg = sum(times) / len(times)
    results[query] = {
        "name": name,
        "runs": times,
        "avg": avg
    }

print("\n--- RESULTS JSON ---")
print(json.dumps(results, indent=2))
