"""Validate the specification, or explicitly execute API cases as guests.

No model/network calls without --execute. Passing automated checks results in
REVIEW_REQUIRED, never an automatic overall PASS for a natural-language answer.
Uses only the Python standard library; does not import application logic.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import json
import math
from pathlib import Path
import re
import time
import unicodedata
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent
CHECKS = {'http_status','ok','intent_in','max_products','min_products','required_ingredients',
          'excluded_ingredients','nonempty_or_explained','category','price_max','price_min',
          'required_product_ids','forbidden_product_ids','unique_products','total_max',
          'forbidden_categories','fallback','fallback_reason','catalog_facts'}


def validate(suite):
    cases = suite['cases']
    assert suite['case_count'] == len(cases) == 100, 'Expected exactly 100 cases'
    assert [c['id'] for c in cases] == [f'CHAT-{i:03}' for i in range(1,101)], 'IDs must be unique and sequential'
    assert len({c['title'] for c in cases}) == 100, 'Duplicate titles'
    for case in cases:
        assert case['mode'] in ('api','fixture','manual'), case['id']
        assert case['review'] and case['preconditions'], case['id']
        assert set(case['checks']) <= CHECKS, (case['id'],set(case['checks']) - CHECKS)
        assert case['status'] == 'NOT_RUN', 'Specification must not claim execution'
        payload = case['payload']
        if isinstance(payload,dict):
            assert not payload.get('user_email') and not payload.get('conversation_id'), 'Never use real account identity'
    return cases


def text(value):
    return unicodedata.normalize('NFC', str(value or '')).casefold()


def contains_ingredient(inci, name):
    name = text(name).strip()
    aliases = {
        'fragrance/parfum':['fragrance','parfum','perfume','hương liệu'],
        'hương liệu':['fragrance','parfum','perfume','hương liệu'],
        'bha':['salicylic acid','bha'],
        'cồn':['alcohol','alcohol denat','ethanol','sd alcohol'],
    }.get(name, [name])
    value = text(inci)
    if name == 'cồn':
        value = re.sub(r'\b(?:cetyl|cetearyl|stearyl|behenyl|benzyl)\s+alcohol\b', '', value)
    return any(re.search(r'(?<!\w)'+re.escape(a)+r'(?!\w)',value) for a in aliases)


def number(value):
    try:
        value = float(value)
        return value if math.isfinite(value) else None
    except (TypeError, ValueError):
        return None


def load_catalog(path):
    if not path:
        return None
    data = json.loads(Path(path).read_text(encoding='utf-8-sig'))
    if isinstance(data,dict):
        data = data['products'] if 'products' in data else list(data.values())
    if not isinstance(data,list):
        raise ValueError('Catalog must be a list of product objects, or {products:[...]}')
    result = {}
    for row in data:
        key = str(row.get('ma_san_pham') or row.get('id') or '')
        if not key:
            raise ValueError('Catalog row without product ID')
        if key in result:
            raise ValueError('Duplicate catalog ID: '+key)
        result[key] = row
    return result


def evaluate(case, status, body, catalog=None):
    expected = case['checks']
    failures, pending = [], []
    if status != expected['http_status']:
        failures.append(f'HTTP {status}; expected {expected["http_status"]}')
    if not isinstance(body,dict):
        return failures + ['Response is not a JSON object'], pending
    if body.get('ok') is not expected['ok']:
        failures.append('Wrong ok flag')
    if status >= 400:
        if not body.get('message'): failures.append('Missing error explanation')
        return failures, pending
    answer = body.get('answer')
    if not isinstance(answer,str) or not answer.strip(): failures.append('Missing answer')
    products = body.get('products')
    if not isinstance(products,list) or not all(isinstance(p,dict) for p in products):
        return failures + ['Invalid product list'], pending
    if expected.get('intent_in') and body.get('intent_mode') not in expected['intent_in']:
        failures.append('Wrong intent: '+str(body.get('intent_mode')))
    for key in ('fallback','fallback_reason'):
        if key in expected and body.get(key) != expected[key]: failures.append('Wrong '+key)
    if 'fallback' not in expected and body.get('fallback') is True:
        failures.append('Unexpected fallback/degraded response')
    if 'max_products' in expected and len(products) > expected['max_products']: failures.append('Too many products')
    if 'min_products' in expected and len(products) < expected['min_products']: failures.append('Too few products')
    ids = [str(p.get('id') or '') for p in products]
    if any(not pid for pid in ids): failures.append('Missing product ID')
    if len(ids) != len(set(ids)): failures.append('Duplicate product IDs')
    if set(expected.get('required_product_ids',[])) - set(ids): failures.append('Missing required fixture product')
    if set(expected.get('forbidden_product_ids',[])) & set(ids): failures.append('Forbidden fixture product selected')
    if expected.get('nonempty_or_explained') and not products:
        pending.append('Manually verify whether an empty list/clarification is justified by the actual catalog and conditions')
    prices = [number(p.get('price')) for p in products]
    for price in prices:
        if price is None or price < 0:
            failures.append('Invalid numeric price'); continue
        if 'price_max' in expected and (price <= 0 or price > expected['price_max']): failures.append('Above maximum/unknown price')
        if 'price_min' in expected and price < expected['price_min']: failures.append('Below minimum price')
    if 'total_max' in expected:
        if any(p is None or p <= 0 for p in prices): failures.append('Cannot verify total with unknown price')
        elif sum(prices) > expected['total_max']: failures.append('Routine exceeds total budget')
        routine = body.get('routine')
        if isinstance(routine,dict) and all(p is not None for p in prices) and routine.get('total_vnd') != sum(prices):
            failures.append('Routine total does not equal sum of selected cards')
    if products and catalog is None:
        pending.append('Catalog snapshot missing: verify IDs, names, prices, category, stock and INCI manually')
    if catalog is not None:
        for card in products:
            pid = str(card.get('id') or '')
            row = catalog.get(pid)
            if row is None:
                failures.append('Product absent from supplied catalog snapshot: '+pid); continue
            if card.get('name') != (row.get('ten_san_pham') or row.get('name')): failures.append('Name differs from catalog: '+pid)
            if number(card.get('price')) != number(row.get('gia_ban',row.get('price'))): failures.append('Price differs from catalog: '+pid)
            category = row.get('loai_san_pham') or row.get('category')
            if 'category' in expected and category != expected['category']: failures.append('Wrong category: '+pid)
            if category in expected.get('forbidden_categories',[]): failures.append('Forbidden category: '+pid)
            status_value = str(row.get('stock_status') or row.get('trang_thai') or row.get('status') or '').casefold()
            if status_value in ('hidden','inactive','out_of_stock','disabled','off','0','tam_an'): failures.append('Hidden/inactive product: '+pid)
            for field in ('so_luong_ton','ton_kho','stock','quantity'):
                if field in row and number(row[field]) is not None and number(row[field]) <= 0: failures.append('Out of stock: '+pid)
            inci = row.get('thanh_phan_day_du') or row.get('inci')
            required = expected.get('required_ingredients',[])
            excluded = expected.get('excluded_ingredients',[])
            if required or excluded:
                if text(inci).strip() in ('','unknown','n/a','none','null'):
                    failures.append('No complete INCI for ingredient checks: '+pid)
                else:
                    for ingredient in required:
                        if not contains_ingredient(inci,ingredient): failures.append(f'{pid}: missing required {ingredient}')
                    for ingredient in excluded:
                        if contains_ingredient(inci,ingredient): failures.append(f'{pid}: contains excluded {ingredient}')
    # Meaning cannot be certified by keyword matches. Keep these checks explicit.
    pending += case['review']
    return list(dict.fromkeys(failures)), list(dict.fromkeys(pending))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute',action='store_true',help='Actually call the local chatbot/model')
    parser.add_argument('--ids',default='',help='Comma-separated case IDs')
    parser.add_argument('--endpoint',default='http://localhost:5001/api/chat/auto')
    parser.add_argument('--catalog',help='Optional JSON catalog snapshot for factual checks')
    parser.add_argument('--timeout',type=float,default=120)
    parser.add_argument('--output',help='New result filename; existing files are never overwritten')
    args = parser.parse_args()
    suite = json.loads((ROOT/'cases.json').read_text(encoding='utf-8'))
    cases = validate(suite)
    if args.ids:
        wanted = {s.strip() for s in args.ids.split(',') if s.strip()}
        unknown = wanted - {c['id'] for c in cases}
        if unknown: parser.error('Unknown case IDs: '+', '.join(sorted(unknown)))
        cases = [c for c in cases if c['id'] in wanted]
    summary={'specification_cases':100,'selected':len(cases),'modes':dict(Counter(c['mode'] for c in cases))}
    if not args.execute:
        print(json.dumps({'status':'SPEC_VALID','network_calls':0,**summary},ensure_ascii=False,indent=2))
        return 0
    catalog = load_catalog(args.catalog)
    if args.timeout <= 0: parser.error('Timeout must be positive')
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    output = Path(args.output) if args.output else ROOT/('results-'+stamp+'.json')
    if output.exists(): parser.error('Output already exists; choose a new path')
    run={'created_at_utc':stamp,'endpoint':args.endpoint,'catalog_snapshot':args.catalog,
         'summary':summary,'results':[]}
    with output.open('x',encoding='utf-8') as handle:
        json.dump(run,handle,ensure_ascii=False,indent=2)
    for case in cases:
        started = time.monotonic()
        item={'id':case['id'],'title':case['title']}
        if case['mode'] != 'api':
            item.update(status='SETUP_REQUIRED',mode=case['mode'],preconditions=case['preconditions'],review=case['review'])
        else:
            request = urllib.request.Request(args.endpoint,data=json.dumps(case['payload'],ensure_ascii=False).encode('utf-8'),
                                             headers={'Content-Type':'application/json','Accept':'application/json'})
            try:
                try:
                    with urllib.request.urlopen(request,timeout=args.timeout) as response:
                        status, raw = response.status, response.read()
                except urllib.error.HTTPError as exc:
                    status, raw = exc.code, exc.read()
                body = json.loads(raw)
                failures,pending = evaluate(case,status,body,catalog)
                item.update(status='AUTO_FAIL' if failures else 'REVIEW_REQUIRED',http_status=status,
                            automated_failures=failures,pending_review=pending,response=body)
            except Exception as exc:
                item.update(status='EXECUTION_ERROR',error=type(exc).__name__+': '+str(exc))
        item['elapsed_seconds']=round(time.monotonic()-started,2)
        run['results'].append(item)
        run['result_counts']=dict(Counter(r['status'] for r in run['results']))
        output.write_text(json.dumps(run,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps({'id':case['id'],'status':item['status'],'elapsed_seconds':item['elapsed_seconds']},ensure_ascii=False),flush=True)
    print('Results: '+str(output.resolve()))
    return int(any(r['status'] in ('AUTO_FAIL','EXECUTION_ERROR') for r in run['results']))


if __name__=='__main__':
    raise SystemExit(main())
