"""100 real HTTP chat requests against one local version; no orders/emails sent.

Reports route/contract checks, not an overall clinical answer-quality score.
Each question uses a fresh guest cookie jar. A separate script covers auth/actions.
"""
import concurrent.futures
import hashlib
import json
import time
from pathlib import Path
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor, Request

ROOT = Path(__file__).resolve().parents[2]
SPEC = json.loads(Path(__file__).with_name('store_chat_cases.json').read_text(encoding='utf8'))


def execute(case):
    start = time.monotonic()
    result = dict(case)
    try:
        client = build_opener(HTTPCookieProcessor(CookieJar()))
        req = Request('http://localhost:8080/index.php?r=ai_chat_assistant',
                      data=json.dumps({'message':case['message'],'history':[]}).encode(),
                      headers={'Content-Type':'application/json'})
        with client.open(req, timeout=100) as response:
            data = json.load(response)
        failures = []
        if case.get('invalid'):
            if data.get('ok'): failures.append('invalid quantity accepted')
        elif not data.get('ok'): failures.append('request failed')
        if case['kind'] == 'support':
            if data.get('support_topic') != case['topic']: failures.append('wrong support topic')
            if data.get('products') or data.get('commerce'): failures.append('support injected products')
            if not data.get('sources'): failures.append('missing source')
        elif case['kind'] == 'purchase':
            commerce = data.get('commerce') or {}
            ids = [p['id'] for p in commerce.get('products', [])]
            if case.get('required_id') not in ids and case.get('required_id'): failures.append('required product missing')
            if case.get('empty') and ids: failures.append('unrelated products')
            if ids and commerce.get('quantity') != case.get('quantity',1): failures.append('wrong quantity')
        elif data.get('commerce') or data.get('intent_mode') == 'PURCHASE': failures.append('advice routed to purchase')
        # Offers are session-scoped capabilities; keep them out of report artifacts.
        if data.get('commerce'): data['commerce'].pop('offer', None)
        result.update(status='FAIL' if failures else 'PASS', failures=failures, response=data)
    except Exception as error:
        result.update(status='FAIL', error=type(error).__name__ + ': ' + str(error))
    result['seconds'] = round(time.monotonic() - start, 2)
    return result


def main():
    cases = [{'kind':'support','topic':topic,'message':message} for topic, questions in SPEC['support'].items() for message in questions]
    cases += [dict(kind='purchase', **case) for case in SPEC['purchase']]
    cases += [{'kind':'advice','message':message} for message in SPEC['advice']]
    assert len(cases) == len({case['message'] for case in cases}) == 100
    for index, case in enumerate(cases, 1): case['id'] = f'STORE-{index:03}'
    files = [ROOT/'backend/app/content/support.json', *sorted((ROOT/'backend/app/services').glob('*Purchase*.php')), ROOT/'backend/app/services/SupportKnowledge.php', ROOT/'backend/app/services/ChatProductCatalog.php', ROOT/'backend/app/controllers/ShopAssistantController.php']
    snapshot = {str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        results = list(pool.map(execute, cases))
    unchanged = all(hashlib.sha256((ROOT/name).read_bytes()).hexdigest() == value for name, value in snapshot.items())
    report = {'scope':'100 guest HTTP requests, routing/facts contract only; skincare prose not clinically scored', 'same_code_snapshot':unchanged, 'snapshot':snapshot, 'passed':sum(r['status']=='PASS' for r in results), 'results':results}
    target = ROOT/'report/store-assistance/live-100.json'
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf8')
    print(json.dumps({'passed':report['passed'],'same_code_snapshot':unchanged,'failures':[(r['id'],r.get('failures',r.get('error'))) for r in results if r['status']=='FAIL']}, ensure_ascii=False), flush=True)
    raise SystemExit(0 if report['passed'] == 100 and unchanged else 1)


if __name__ == '__main__': main()
