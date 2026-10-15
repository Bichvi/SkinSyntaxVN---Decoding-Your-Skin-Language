"""Exercise PHP login/session boundaries with two disposable synthetic accounts.

Uses independent cookie jars (HTTP session integration, not browser UI testing).
Never calls registration or mail endpoints. Deletes only records owned by this run.
"""
import json
import argparse
import secrets
import time
from pathlib import Path
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.parse import urlencode
from urllib.error import HTTPError
import bcrypt
from bson import ObjectId
from pymongo import MongoClient

BASE='http://localhost:8080/index.php?'
OUT=Path(__file__).parent/'run-2026-09-09/session-results.json'

def request(client,route,payload=None,form=False,**query):
    body=None if payload is None else (urlencode(payload).encode() if form else json.dumps(payload).encode())
    headers={} if body is None else {'Content-Type':'application/x-www-form-urlencoded' if form else 'application/json'}
    req=Request(BASE+urlencode({'r':route,**query}),data=body,headers=headers)
    try:
        with client.open(req,timeout=150) as r: code,raw=r.status,r.read().decode('utf8')
    except HTTPError as e: code,raw=e.code,e.read().decode('utf8')
    try: data=json.loads(raw)
    except ValueError: data={'html_received':True,'raw_excerpt':raw[:2500]}
    return {'http_status':code,'response':data}

def main():
    global OUT
    parser=argparse.ArgumentParser()
    parser.add_argument('--output',default=str(OUT))
    parser.add_argument('--profile-array',action='store_true',help='Extra compatibility probe: BSON array instead of canonical comma-delimited string')
    args=parser.parse_args()
    OUT=Path(args.output)
    if OUT.exists(): raise FileExistsError(OUT)
    db=MongoClient('mongodb://127.0.0.1:27018',serverSelectionTimeoutMS=5000).skinsyntax
    token=secrets.token_hex(8)
    owned=[]; accounts=[]; results={'scope':'PHP HTTP session integration with independent cookie jars; no browser UI clicks','results':[]}
    try:
        for letter,budget,avoid,skin in [('A',100000,['Fragrance/Parfum'],'Da Dầu'),('B',800000,['Lanolin'],'Da Khô')]:
            email=f'chat-test-{token}-{letter.lower()}@example.invalid'
            uid=secrets.randbelow(900000000)+1000000000
            assert not db.nguoidung.find_one({'id':uid}) and not db.khach_hang.find_one({'ma_kh':uid})
            password=secrets.token_urlsafe(24)
            docs={
                'nguoidung':{'id':uid,'email':email,'ho_ten':'Test Chat '+letter,'mat_khau':bcrypt.hashpw(password.encode(),bcrypt.gensalt()).decode()},
                'khach_hang':{'ma_kh':uid,'email':email,'ho_ten':'Test Chat '+letter,'ngan_sach':budget,'thanh_phan_tranh':avoid if args.profile_array else ', '.join(avoid),'loai_da':skin},
                'skin_profile':{'email':email,'loai_da':skin,'tinh_trang_da':[]},
            }
            for collection,doc in docs.items():
                oid=db[collection].insert_one(doc).inserted_id
                owned.append((collection,oid))
            client=build_opener(HTTPCookieProcessor(CookieJar()))
            account={'letter':letter,'email':email,'client':client,'budget':budget,'avoid':avoid}
            accounts.append(account)
            request(client,'xulydangnhap',{'email':email,'mat_khau':password},form=True)
            check=request(client,'ai_chat_get_conversations')
            if check['http_status']!=200 or check['response'].get('html_received'): raise RuntimeError('Synthetic login failed for '+letter)
            account['chat']=request(client,'ai_chat_assistant',{'message':'Gợi ý toner cho tôi','history':[]})
            print('Completed synthetic session '+letter,flush=True)
        evidence=[]; errors=[]
        for a in accounts:
            reply=a['chat']['response']; cid=reply.get('conversation_id')
            conv=db.chat_conversations.find_one({'_id':ObjectId(cid),'user_email':a['email']}) if cid else None
            if not conv: errors.append(a['letter']+': missing owned conversation')
            if not reply.get('ok') or reply.get('fallback'): errors.append(a['letter']+': invalid/degraded answer')
            if any(p.get('price',0)>a['budget'] for p in reply.get('products',[])): errors.append(a['letter']+': price exceeds profile budget')
            evidence.append({'account':a['letter'],'profile':{'budget':a['budget'],'avoid_ingredients':a['avoid']},'chat':a['chat'],'conversation_owner_verified':bool(conv)})
        a,b=accounts
        cid=a['chat']['response'].get('conversation_id')
        if cid==b['chat']['response'].get('conversation_id'): errors.append('Shared conversation ID')
        results['results'].append({'id':'CHAT-099','status':'AUTO_FAIL' if errors else 'REVIEW_REQUIRED','automated_failures':errors,'evidence':evidence})
        if not cid: raise RuntimeError('No conversation for ownership test')
        before=db.chat_conversations.find_one({'_id':ObjectId(cid),'user_email':a['email']})
        read=request(b['client'],'ai_chat_get_messages',conversation_id=cid)
        send=request(b['client'],'ai_chat_assistant',{'message':'Tiếp tục cuộc hội thoại này','conversation_id':cid,'history':[]})
        after=db.chat_conversations.find_one({'_id':ObjectId(cid),'user_email':a['email']})
        errors=[]
        if read['http_status']==200 and read['response'].get('ok'): errors.append('Cross-owner read accepted')
        if send['http_status']==200 and send['response'].get('ok'): errors.append('Cross-owner send accepted instead of rejected')
        unchanged=before==after
        if not unchanged: errors.append('Other owner conversation mutated')
        results['results'].append({'id':'CHAT-100','status':'AUTO_FAIL' if errors else 'REVIEW_REQUIRED','automated_failures':errors,'read':read,'send':send,'owner_document_unchanged':unchanged})
    except Exception as exc:
        results['execution_error']=type(exc).__name__+': '+str(exc)
    finally:
        for a in accounts:
            try: request(a['client'],'dangxuat')
            except Exception: pass
            db.chat_conversations.delete_many({'user_email':a['email']})
        for collection,oid in reversed(owned): db[collection].delete_one({'_id':oid})
        results['cleanup_verified']=all(db[c].find_one({'_id':i}) is None for c,i in owned) and all(db.chat_conversations.count_documents({'user_email':a['email']})==0 for a in accounts)
        OUT.write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
        print(json.dumps({'results':[(r['id'],r['status']) for r in results['results']],'cleanup_verified':results['cleanup_verified'],'execution_error':results.get('execution_error')},ensure_ascii=False),flush=True)

if __name__=='__main__': main()
