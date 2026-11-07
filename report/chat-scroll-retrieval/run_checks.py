"""Focused live regression checks using synthetic context and the local catalog."""
import json
import re
import sys
import urllib.request
from pathlib import Path

import pymongo

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-flask'))
from chatbot_service.consultation.ingredients import has_ingredient

PHP = 'http://localhost:8080/index.php?r=ai_chat_api'
FLASK = 'http://localhost:5001/api/chat/auto'
profile = {'skin_type': 'Da dầu/Hỗn hợp dầu', 'concerns': ['khô căng', 'bong tróc'],
           'avoid_ingredients': ['Không có / Không quan tâm']}
cases = [
    ('guest_retinol', PHP, {'message': 'bạn kiếm cho mình 1 chai retinol đi'}, 'retinol'),
    ('guest_toner_after_retinol', PHP, {'message': 'Gợi ý cho tôi một vài sản phẩm thuộc nhóm: Toner / Nước Cân Bằng Da',
        'history': [{'role': 'user', 'content': 'bạn kiếm cho mình 1 chai retinol đi'},
                    {'role': 'assistant', 'content': 'Mình chưa có sản phẩm retinol nào.'}]}, 'toner'),
    ('profile_toner', FLASK, {'message': 'Gợi ý cho tôi một vài sản phẩm phù hợp với da tôi thuộc nhóm: Toner / Nước Cân Bằng Da',
                            'customer_profile': profile}, 'toner'),
    ('profile_retinol_decision', FLASK, {'message': 'tôi có nên mua 1 chai retinol ở hiện tại không',
                                       'customer_profile': profile}, 'decision'),
    ('retinol_budget_5000', PHP, {'message': 'Tìm 1 chai retinol, ngân sách tối đa 5.000 đồng'}, 'empty'),
    ('category_switch', FLASK, {'message': 'Gợi ý cho tôi một vài sản phẩm thuộc nhóm: Hỗ Trợ Trị Mụn',
         'conversation_history': [{'role': 'user', 'content': 'tìm toner'},
                                  {'role': 'assistant', 'content': 'Mình chưa có toner nào.'}]}, 'acne'),
]

db = pymongo.MongoClient('mongodb://localhost:27018', serverSelectionTimeoutMS=3000).skinsyntax
results = []
for name, url, payload, expectation in cases:
    try:
        request = urllib.request.Request(url, json.dumps(payload, ensure_ascii=False).encode('utf-8'),
                                        headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(request, timeout=100) as response:
            result = json.load(response)
        products = result.get('products', [])
        errors = []
        if not result.get('ok') or result.get('fallback'):
            errors.append('service_failure')
        if expectation in ('retinol', 'toner', 'acne') and not products:
            errors.append('missing_products')
        if expectation == 'retinol' and len(products) != 1:
            errors.append('wrong_count')
        for card in products:
            pid = str(card['id'])
            row = db.san_pham.find_one({'ma_san_pham': {'$in': [pid, int(pid)] if pid.isdigit() else [pid]}})
            if not row:
                errors.append('unknown_product')
                continue
            if int(float(row['gia_ban'])) != card['price']:
                errors.append('wrong_price')
            if expectation == 'retinol' and not has_ingredient(row.get('thanh_phan_day_du') or row.get('thanh_phan_chinh'), 'retinol'):
                errors.append('missing_retinol')
            category = {'toner': 'Toner / Nước Cân Bằng Da', 'acne': 'Hỗ Trợ Trị Mụn'}.get(expectation)
            if category and row.get('loai_san_pham') != category:
                errors.append('wrong_category')
        if expectation in ('empty', 'decision') and products:
            errors.append('unexpected_cards')
        if expectation == 'decision' and not (
            result.get('intent_mode') == 'knowledge'
            and re.search(r'chưa (?:nên|khuyên)|không nên|tạm hoãn', result.get('answer', ''), re.I)
            and 'retinol' in result.get('answer', '').lower()
        ):
            errors.append('missing_decision')
        results.append({'case': name, 'endpoint': url, 'payload': payload, 'errors': errors, 'response': result})
        print(name, 'PASS' if not errors else errors, flush=True)
    except Exception as exc:
        results.append({'case': name, 'errors': [type(exc).__name__]})
        print(name, type(exc).__name__, flush=True)
    Path(__file__).with_name('live-results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding='utf-8')
raise SystemExit(int(any(r['errors'] for r in results)))
