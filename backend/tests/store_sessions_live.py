"""Local HTTP integration: guest/login handoff, profile states and action security.

Creates only tagged synthetic accounts in local Mongo and removes exactly those
records in finally. Does not call registration, mail, order creation or payments.
"""
import json
import re
import secrets
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError
from http.cookiejar import CookieJar
import bcrypt
from pymongo import MongoClient

BASE = 'http://localhost:8080/index.php?'
OUT = Path(__file__).resolve().parents[2]/'report/store-assistance/session-results.json'


def client(): return build_opener(HTTPCookieProcessor(CookieJar()))


def request(session, route, payload=None, form=False, **query):
    data = None if payload is None else (urlencode(payload).encode() if form else json.dumps(payload).encode())
    req = Request(BASE + urlencode({'r':route, **query}), data=data,
                  headers={'Content-Type':'application/x-www-form-urlencoded' if form else 'application/json'})
    try:
        with session.open(req, timeout=60) as response:
            status, raw, url = response.status, response.read().decode('utf8'), response.url
    except HTTPError as error:
        status, raw, url = error.code, error.read().decode('utf8'), error.url
    try: body = json.loads(raw)
    except ValueError: body = raw
    return status, body, url


def token(session):
    status, body, _ = request(session, 'tro_giup')
    assert status == 200 and 'Fatal error' not in body
    return re.search(r'csrf:\s*"([a-f0-9]{64})"', body).group(1)


def main():
    db = MongoClient('mongodb://127.0.0.1:27018', serverSelectionTimeoutMS=5000).skinsyntax
    owned, users, results = [], [], []
    def check(label, ok):
        results.append({'case':label,'status':'PASS' if ok else 'FAIL'})
        if not ok: raise AssertionError(label)
    try:
        password = secrets.token_urlsafe(24)
        for profile in [False, True]:
            uid = secrets.randbelow(900000000) + 1200000000
            email = 'store-test-' + secrets.token_hex(10) + '@example.invalid'
            docs = {'nguoidung':{'id':uid,'email':email,'ho_ten':'Store Acceptance','mat_khau':bcrypt.hashpw(password.encode(),bcrypt.gensalt()).decode()},
                    'khach_hang':{'ma_kh':uid,'email':email,'ho_ten':'Store Acceptance'}}
            if profile:
                docs['khach_hang'].update(loai_da='Da Dầu', ngan_sach=350000, thanh_phan_tranh='')
                docs['skin_profile'] = {'email':email,'loai_da':'Da Dầu','tinh_trang_da':[]}
            for collection, doc in docs.items(): owned.append((collection, db[collection].insert_one(doc).inserted_id))
            users.append(email)
        guest, stranger = client(), client()
        csrf = token(guest)
        status, response, _ = request(guest, 'ai_chat_assistant', {'message':'Đặt cho tôi 2 chai CeraVe Retinol','history':[]})
        offer = response['commerce']
        chosen = next(p for p in offer['products'] if p['id']=='1077')
        check('Guest receives exact real product and requested quantity', status == 200 and offer['quantity']==2)
        action = {'csrf':csrf,'action':'select','offer':offer['offer'],'product_id':chosen['id'],'quantity':2}
        check('GET actions rejected', request(guest,'chat_purchase_action')[0]==405)
        check('Missing CSRF rejected', request(guest,'chat_purchase_action',dict(action, csrf=''))[0]==403)
        check('Offer cannot cross guest sessions', request(stranger,'chat_purchase_action',dict(action, csrf=token(stranger)))[0]==410)
        check('Unoffered product rejected', request(guest,'chat_purchase_action',dict(action, product_id='99999999'))[0]==422)
        for quantity in [0,-1,21,1.5,'2']:
            check('Invalid quantity '+str(quantity), request(guest,'chat_purchase_action',dict(action,quantity=quantity))[0]==422)
        status, quote, _ = request(guest,'chat_purchase_action',action)
        check('Select returns server subtotal', status==200 and quote['commerce']['subtotal']==chosen['price']*2)
        status, policy, _ = request(guest,'ai_chat_assistant', {'message':'Phí ship bao nhiêu?','history':[]})
        check('Support question during choice is grounded', status==200 and policy['support_topic']=='van-chuyen')
        check('Offer remains valid after support question', request(guest,'chat_purchase_action',action)[0]==200)
        checkout = dict(action,action='checkout',expected_price=chosen['price'])
        check('Tampered price rejected', request(guest,'chat_purchase_action',dict(checkout,expected_price=1))[0]==409)
        status, result, _ = request(guest,'chat_purchase_action',checkout)
        check('Guest explicitly handed to login', status==200 and result['redirect_url'].endswith('r=dangnhap'))
        status, html, url = request(guest,'xulydangnhap',{'email':users[0],'mat_khau':password},form=True)
        check('Login resumes existing checkout without skin profile', status==200 and 'r=thanhtoan' in url and chosen['name'] in html)
        check('Checkout shows subtotal for two bottles', f"{chosen['price']*2:,}".replace(',','.') in html)
        _, cart, _ = request(guest,'giohang')
        check('Chat selection does not add products to shopping cart', 'data-product-id="1077"' not in cart)
        with_profile = client()
        request(with_profile,'xulydangnhap',{'email':users[1],'mat_khau':password},form=True)
        csrf2 = token(with_profile)
        _, response, _ = request(with_profile,'ai_chat_assistant',{'message':'Mua chai CeraVe Retinol','history':[]})
        offer2 = response['commerce']
        check('Explicit exact purchase not silently replaced by profile recommendation', any(p['id']=='1077' for p in offer2['products']))
        _, result, _ = request(with_profile,'chat_purchase_action',dict(checkout,csrf=csrf2,offer=offer2['offer'],quantity=1))
        check('Signed-in customer goes directly to checkout', result['redirect_url'].endswith('r=thanhtoan'))
        for route in ['tro_giup','huong_dan_nhan_otp','dieu_kien_giao_dich','chinh_sach_bao_mat','bao_hanh']:
            status, html, _ = request(guest,route)
            check('Canonical page '+route, status==200 and 'ss-help__' in html and 'Fatal error' not in html)
        check('Unknown article returns 404', request(guest,'tro_giup',bai='does-not-exist')[0]==404)
        status, html, _ = request(guest,'tro_giup',q='<script>alert(1)</script>')
        check('Search query HTML escaped', status==200 and '<script>alert(1)</script>' not in html)
    except Exception as error:
        results.append({'case':'execution','status':'FAIL','error':type(error).__name__+': '+str(error)})
    finally:
        # Exact IDs and synthetic addresses created above only; no existing records.
        for collection, oid in reversed(owned): db[collection].delete_one({'_id':oid})
        clean = all(db[collection].find_one({'_id':oid}) is None for collection,oid in owned)
        OUT.parent.mkdir(parents=True, exist_ok=True)
        OUT.write_text(json.dumps({'results':results,'cleanup_verified':clean},ensure_ascii=False,indent=2),encoding='utf8')
        print(json.dumps({'passed':sum(r['status']=='PASS' for r in results),'total':len(results),'cleanup_verified':clean,'failures':[r for r in results if r['status']=='FAIL']},ensure_ascii=False))
    raise SystemExit(1 if any(r['status']=='FAIL' for r in results) else 0)


if __name__ == '__main__': main()
