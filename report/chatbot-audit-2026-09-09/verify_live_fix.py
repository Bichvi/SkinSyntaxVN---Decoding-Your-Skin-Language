"""Opt-in live tests: synthetic guest conversations, no profile/history writes.

Run from the repository root. Uses the local AI API and reads the local catalog.
Pass --cases name1,name2 to rerun only affected cases; results are merged by name.
"""
import argparse
import json
import sys
import time
import urllib.request
from pathlib import Path

from pymongo import MongoClient

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'ai-service-flask'))
from chatbot_service.consultation.ingredients import has_ingredient
from chatbot_service.consultation.request_rules import CATEGORY_WORDS

PROFILE = {'skin_type':'Da Dầu', 'concerns':['Da khô căng','bong tróc'],
           'avoid_ingredients':['Fragrance/Parfum','Lanolin'], 'budget':500000, 'sensitivity':'Rất dễ'}
CHAIN = [
    'tôi có nên mua 1 chai retinol ở hiện tại không',
    'bạn kiếm cho mình 1 chai retinol đi',
    'Gợi ý cho tôi một vài sản phẩm phù hợp với da tôi thuộc nhóm: Toner / Nước Cân Bằng Da',
    'Gợi ý cho tôi một vài sản phẩm phù hợp với da tôi thuộc nhóm: Hỗ Trợ Trị Mụn',
]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--cases', default='')
    args = parser.parse_args()
    selected = set(args.cases.split(',')) if args.cases else None
    collection = MongoClient('mongodb://127.0.0.1:27018', serverSelectionTimeoutMS=3000).skinsyntax.san_pham
    path = Path(__file__).with_name('live-fix-results.json')
    saved = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}
    cases = []
    for index, message in enumerate(CHAIN):
        cases.append((f'chain_{index+1}', message, {'customer_profile':PROFILE},
                      {'intent':'knowledge' if index == 0 else 'search', 'positive':index > 0,
                       'category': ['','','Toner / Nước Cân Bằng Da','Hỗ Trợ Trị Mụn'][index],
                       'active':'retinol' if index == 1 else None, 'chain':True}))
    for index, category in enumerate(CATEGORY_WORDS):
        cases.append((f'category_{index+1}', 'Gợi ý cho tôi sản phẩm thuộc nhóm: '+category,
                      {'customer_profile':PROFILE}, {'intent':'search','category':category}))
    cases += [
        ('no_accents','kiem cho minh 1 chai retinol',{}, {'intent':'search','positive':True,'active':'retinol'}),
        ('serum_200k','tìm serum dưới 200k',{}, {'intent':'search','positive':True,'budget':200000,'category':'Serum / Tinh Chất'}),
        ('no_retinol','gợi ý serum không chứa retinol',{}, {'intent':'search','positive':True,'exclude':['retinol']}),
        ('no_fragrance','gợi ý toner không hương liệu',{}, {'intent':'search','positive':True,'exclude':['hương liệu']}),
        ('impossible_budget','gợi ý toner dưới 1k',{}, {'intent':'search','zero':True}),
        ('unknown_brand','tìm toner thương hiệu ZZZTestNoSuchBrand',{}, {'intent':'search','zero':True}),
        ('routine_500k','gợi ý routine tổng dưới 500k',{'customer_profile':PROFILE}, {'intent':'routine','positive':True,'total':500000}),
        ('routine_unlimited','routine không giới hạn',{'customer_profile':PROFILE}, {'intent':'routine','positive':True}),
        ('other_person','gợi ý toner cho mẹ da khô',{'customer_profile':PROFILE}, {'intent':'search','positive':True,'other':True}),
        ('detail','chai này có retinol không',{'current_product_id':'1077'}, {'intent':'product_detail','zero':True}),
        ('missing_detail','chai này có retinol không',{'current_product_id':'999999999'}, {'intent':'product_detail','zero':True}),
        ('knowledge','routine gồm những bước nào',{}, {'intent':'knowledge','zero':True}),
        ('mixed_constraints','kiếm cho mình 1 chai serum không hương liệu nhưng có retinol',{},
         {'intent':'search','positive':True,'active':'retinol','exclude':['hương liệu']}),
        ('cleared_symptom','da đã hết bong tróc, có nên mua retinol không',{'customer_profile':{'concerns':['bong tróc']}},
         {'intent':'knowledge','zero':True}),
    ]
    history = []
    failures = []
    for name, message, data, expected in cases:
        if selected is not None and name not in selected:
            continue
        payload = {'message':message, **data}
        if expected.get('chain'):
            payload['conversation_history'] = history
        # Deliberately include stale assistant text on every category change.
        elif name.startswith('category_'):
            payload['conversation_history'] = [{'role':'user','content':CHAIN[2]},
                {'role':'assistant','content':'Mình không có sản phẩm toner nào để đề xuất.'}]
        started = time.monotonic()
        try:
            request = urllib.request.Request('http://localhost:5001/api/chat/auto',
                data=json.dumps(payload,ensure_ascii=False).encode(), headers={'Content-Type':'application/json'})
            response = json.load(urllib.request.urlopen(request,timeout=120))
            errors = []
            products = response.get('products', [])
            if not response.get('ok') or response.get('fallback'): errors.append('failed/degraded response')
            if response.get('intent_mode') != expected['intent']: errors.append('wrong intent')
            if expected.get('positive') and not products: errors.append('expected products')
            if expected.get('zero') and products: errors.append('unexpected products')
            profile = {} if expected.get('other') else data.get('customer_profile', {})
            budget = expected.get('budget', profile.get('budget'))
            if name == 'routine_unlimited': budget = None
            for card in products:
                pid = card['id']
                row = collection.find_one({'ma_san_pham':{'$in':[pid,int(pid)] if pid.isdigit() else [pid]}})
                if not row:
                    errors.append('missing product in MongoDB'); continue
                if card['name'] != row.get('ten_san_pham'): errors.append('wrong product name')
                if card['price'] != row.get('gia_ban'): errors.append('wrong price')
                if budget and card['price'] > budget: errors.append('over budget')
                if expected.get('category') and row.get('loai_san_pham') != expected['category']: errors.append('wrong category')
                if expected.get('active') and not has_ingredient(row.get('thanh_phan_day_du'),expected['active']): errors.append('missing requested active')
                for ingredient in expected.get('exclude', []) + profile.get('avoid_ingredients', []):
                    if not row.get('thanh_phan_day_du') or has_ingredient(row['thanh_phan_day_du'],ingredient): errors.append('exclusion not satisfied')
                if card['name'].casefold() not in response.get('answer','').casefold(): errors.append('selected product absent from prose')
            if expected.get('total') and sum(p['price'] for p in products) > expected['total']: errors.append('routine exceeds total')
            if name == 'chain_4' and 'toner' in response.get('answer','').casefold(): errors.append('stale toner answer')
            if name == 'chain_2' and len(products) != 1: errors.append('expected one bottle')
            if name in ('chain_1','chain_2') and 'chưa khuyên bắt đầu retinol ngay' not in response.get('answer',''):
                errors.append('missing current-skin caution')
            if name in ('chain_1','chain_2') and 'hàng ngày' in response.get('answer',''):
                errors.append('unreviewed daily retinol regimen')
            if expected.get('chain'):
                history += [{'role':'user','content':message},{'role':'assistant','content':response.get('answer','')}]
            saved[name] = {'message':message,'errors':errors,'seconds':round(time.monotonic()-started,2),'response':response}
        except Exception as exc:
            errors = [type(exc).__name__ + ': ' + str(exc)]
            saved[name] = {'message':message,'errors':errors}
        path.write_text(json.dumps(saved,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps({'case':name,'passed':not errors,'errors':errors,
                          'products':len(saved[name].get('response',{}).get('products',[]))},ensure_ascii=False),flush=True)
        if errors: failures.append(name)
    print(json.dumps({'executed':sum(1 for c in cases if selected is None or c[0] in selected),'failed':failures}),flush=True)
    return bool(failures)


if __name__ == '__main__':
    raise SystemExit(main())
