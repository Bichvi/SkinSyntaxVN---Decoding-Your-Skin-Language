import requests
import re
from bs4 import BeautifulSoup
import json

resp = requests.get("http://127.0.0.1/index.php?r=home", timeout=30)
html = resp.text

soup = BeautifulSoup(html, "html.parser")
cards = soup.find_all(class_=re.compile(r'(product-card|flash-product)'))

products = []
for c in cards:
    title = c.find(class_=re.compile(r'(title|name)'))
    price = c.find(class_=re.compile(r'price'))
    products.append({
        'title': title.get_text(strip=True) if title else '',
        'price': price.get_text(strip=True) if price else ''
    })

print(f"Total product cards rendered on home: {len(products)}")
with open("backend/tests/home_cards_rendered.json", "w", encoding="utf-8") as f:
    json.dump(products, f, ensure_ascii=False, indent=2)
print("Saved product cards to backend/tests/home_cards_rendered.json")
