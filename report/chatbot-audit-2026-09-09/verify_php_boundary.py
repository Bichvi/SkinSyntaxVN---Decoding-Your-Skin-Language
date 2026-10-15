"""Guest HTTP tests through PHP, plus invalid Flask request contracts."""
import json
import urllib.request
import urllib.error
from pathlib import Path


def call(url, payload):
    request = urllib.request.Request(url, data=json.dumps(payload,ensure_ascii=False).encode(),
                                     headers={'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as exc:
        return exc.code, json.load(exc)


def main():
    results = []
    for payload in ([], None, 123, {'message':[]}, {'message':None}, {'message':'  '},
                    {'customer_question':3}, {'message':json.dumps({'customer_question':[]})}):
        status, body = call('http://localhost:5001/api/chat/auto',payload)
        results.append({'case':'invalid_'+str(len(results)+1),'passed':status==400 and body.get('ok') is False,'status':status})
        print(results[-1],flush=True)
    for name, message, history, count in [
        ('php_empty','gợi ý toner dưới 1k',[],0),
        ('php_knowledge','routine gồm những bước nào',[],0),
        ('php_retinol','kiếm cho mình 1 chai retinol',[],1),
        ('php_category_switch','Gợi ý sản phẩm thuộc nhóm: Hỗ Trợ Trị Mụn',
         [{'role':'user','content':'gợi ý toner'}, {'role':'assistant','content':'Mình không có sản phẩm toner nào để đề xuất.'}],3),
    ]:
        status, body = call('http://localhost:8080/index.php?r=ai_chat_assistant',{'message':message,'history':history})
        passed = status==200 and body.get('ok') and not body.get('fallback') and len(body.get('products',[]))==count and body.get('eval_scores') is None
        if name=='php_category_switch': passed = passed and 'toner' not in body.get('answer','').casefold()
        results.append({'case':name,'passed':bool(passed),'status':status,'response':body})
        print({'case':name,'passed':bool(passed),'products':len(body.get('products',[]))},flush=True)
    Path(__file__).with_name('php-boundary-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
    return not all(r['passed'] for r in results)


if __name__=='__main__':
    raise SystemExit(main())
