from __future__ import annotations

import sys
from datetime import datetime, timezone
import csv
import os

from config import LlamaIndexConfig
from rag.mongo_hybrid_service import get_mongo_hybrid_service


def normalize_text(value: str) -> str:
    return ' '.join((value or '').strip().split())


def build_product_content(row: dict) -> str:
    segments = [
        row.get('ma_san_pham') or '',
        row.get('ten_san_pham') or '',
        row.get('ten_thuong_hieu') or '',
        row.get('ten_danh_muc') or '',
        row.get('ten_xuat_xu') or '',
        str(row.get('gia_ban') or ''),
        row.get('loai_da') or '',
        row.get('mo_ta') or '',
        row.get('thanh_phan_chinh') or '',
        row.get('thanh_phan_day_du') or '',
        row.get('hdsd') or '',
        row.get('attribute') or '',
    ]
    return normalize_text(' '.join(str(item) for item in segments if item))


def first_image(raw: str) -> str:
    return next((item.strip() for item in str(raw or '').split('|') if item.strip()), '')


def sync_products_from_shop_mongodb() -> int:
    service = get_mongo_hybrid_service()
    source = service.db['san_pham']
    synced = 0
    embed_flag = os.getenv('SYNC_PRODUCT_EMBEDDINGS', '1').strip().lower() in {'1', 'true', 'yes', 'on'}
    has_keys = bool(LlamaIndexConfig.get_google_api_keys())

    for row in source.find({}):
        row = dict(row)
        status = str(row.get('trang_thai') or row.get('status') or 'active').strip().lower()
        if status in {'inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'}:
            continue

        stock = None
        for field in ('so_luong_ton', 'ton_kho', 'stock', 'quantity'):
            if field in row and row.get(field) not in (None, ''):
                stock = max(0, int(row.get(field) or 0))
                break
        if stock is not None and stock <= 0:
            continue

        brand = str(row.get('thuong_hieu') or '').strip()
        if not brand and row.get('ma_thuong_hieu') is not None:
            th = service.db['thuong_hieu'].find_one({'ma_thuong_hieu': row.get('ma_thuong_hieu')})
            if th:
                brand = str(th.get('ten_thuong_hieu') or '').strip()

        category = str(row.get('loai_san_pham') or row.get('danh_muc_day_du') or '').strip()
        if not category and row.get('ma_danh_muc') is not None:
            dm = service.db['danh_muc'].find_one({'ma_danh_muc': row.get('ma_danh_muc')})
            if dm:
                category = str(dm.get('ten_danh_muc') or '').strip()

        product_id = str(row.get('ma_san_pham') or '').strip()
        if not product_id:
            continue

        key_ingredients = [item.strip() for item in str(row.get('thanh_phan_chinh') or '').replace('|', ',').split(',') if item.strip()]
        content = normalize_text(' '.join(str(item or '') for item in [
            product_id,
            row.get('ten_san_pham'),
            brand,
            category,
            row.get('loai_da'),
            row.get('gia_ban'),
            row.get('mo_ta'),
            row.get('thanh_phan_chinh'),
            row.get('thanh_phan_day_du'),
            row.get('hdsd'),
        ]))
        if not content:
            continue

        if embed_flag and has_keys:
            try:
                embedding = service.embed_text(content)
                if not embedding:
                    embedding = [0.1] * 768
            except Exception as embed_err:
                print(f"[WARN] Embedding failed for {product_id}: {embed_err}")
                embedding = [0.1] * 768
        else:
            embedding = [0.1] * 768

        document = {
            'product_id': product_id,
            'name': str(row.get('ten_san_pham') or '').strip(),
            'brand': brand,
            'category': category,
            'origin': str(row.get('xuat_xu_thuong_hieu') or '').strip(),
            'price': int(float(row.get('gia_ban') or row.get('gia_thi_truong') or 0)),
            'image_url': first_image(row.get('link_hinh_anh') or row.get('hinh_anh')),
            'description': str(row.get('mo_ta') or '').strip(),
            'key_ingredients': key_ingredients[:8],
            'content': content,
            'metadata': {
                'brand': brand,
                'category': category,
                'price': int(float(row.get('gia_ban') or row.get('gia_thi_truong') or 0)),
                'skin_type': str(row.get('loai_da') or '').strip(),
                'rating': float(row.get('diem_danh_gia') or 0),
            },
            'embedding': embedding,
            'updated_at': datetime.now(timezone.utc),
        }
        service.products.update_one({'product_id': product_id}, {'$set': document}, upsert=True)
        synced += 1

    return synced


def sync_products() -> int:
    mongo_count = sync_products_from_shop_mongodb()
    if mongo_count > 0:
        return mongo_count

    service = get_mongo_hybrid_service()
    synced = 0
    embed_flag = os.getenv('SYNC_PRODUCT_EMBEDDINGS', '1').strip().lower() in {'1', 'true', 'yes', 'on'}
    has_keys = bool(LlamaIndexConfig.get_google_api_keys())

    csv_file = os.path.join(os.path.dirname(__file__), '..', 'database', 'data_clean_final.csv')
    
    if not os.path.exists(csv_file):
        print(f"CSV file not found: {csv_file}")
        return 0

    with open(csv_file, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for row in reader:
            content = build_product_content(row)
            if content == '':
                continue

            key_ingredients = [item.strip() for item in (row.get('thanh_phan_chinh') or '').split(',') if item.strip()]
            image_url = normalize_text(str(row.get('link_hinh_anh') or '').split('|')[0])
            embedding: list[float]
            if embed_flag and has_keys:
                try:
                    embedding = service.embed_text(content)
                    if not embedding:
                        embedding = [0.1] * 768
                except Exception as embed_err:
                    print(f"[WARN] Embedding failed for {row.get('ma_san_pham')}: {embed_err}")
                    embedding = [0.1] * 768
            else:
                if embed_flag and not has_keys:
                    print('[WARN] SYNC_PRODUCT_EMBEDDINGS bật nhưng chưa có GOOGLE_API_KEY — dùng vector giả lập.')
                embedding = [0.1] * 768
            document = {
                'product_id': row['ma_san_pham'],
                'name': row.get('ten_san_pham') or '',
                'brand': row.get('ten_thuong_hieu') or '',
                'category': row.get('ten_danh_muc') or '',
                'origin': row.get('ten_xuat_xu') or '',
                'price': int(row.get('gia_ban') or 0),
                'image_url': image_url,
                'description': row.get('mo_ta') or '',
                'key_ingredients': key_ingredients[:8],
                'content': content,
                'metadata': {
                    'brand': row.get('ten_thuong_hieu') or '',
                    'category': row.get('ten_danh_muc') or '',
                    'origin': row.get('ten_xuat_xu') or '',
                    'price': int(row.get('gia_ban') or 0),
                    'skin_type': row.get('loai_da') or '',
                    'rating': float(row.get('diem_danh_gia') or 0),
                },
                'embedding': embedding,
                'updated_at': datetime.now(timezone.utc),
            }
            service.products.update_one({'product_id': document['product_id']}, {'$set': document}, upsert=True)
            synced += 1

    return synced


def sync_user_profiles() -> int:
    service = get_mongo_hybrid_service()
    synced = 0

    sql = """
        SELECT kh.ma_kh, kh.ho_ten, kh.email, kh.gioi_tinh, kh.nam_sinh,
               kh.muc_do_nhay_cam, kh.van_de_da, kh.muc_tieu_cham_soc,
               kh.thanh_phan_tranh, kh.ngan_sach, kh.loai_da
        FROM khach_hang kh
        ORDER BY kh.updated_at DESC NULLS LAST, kh.created_at DESC NULLS LAST, kh.ma_kh DESC
    """

    keyword_sql = """
        SELECT tu_khoa
        FROM lich_su_tim_kiem
        WHERE ma_kh = %s
        GROUP BY tu_khoa
        ORDER BY MAX(ngay_tim) DESC
        LIMIT 5
    """

    with pg_connect() as conn, conn.cursor(cursor_factory=RealDictCursor) as cursor:
        cursor.execute(sql)
        rows = cursor.fetchall()

        for row in rows:
            cursor.execute(keyword_sql, (row['ma_kh'],))
            recent_keywords = [item['tu_khoa'] for item in cursor.fetchall() if item.get('tu_khoa')]
            concerns = [item.strip() for item in str(row.get('van_de_da') or '').replace('|', ',').split(',') if item.strip()]
            avoid_ingredients = [item.strip() for item in str(row.get('thanh_phan_tranh') or '').replace('|', ',').split(',') if item.strip()]

            document = {
                'customer_id': int(row['ma_kh']),
                'name': row.get('ho_ten') or '',
                'email': row.get('email') or '',
                'gender': row.get('gioi_tinh') or '',
                'birth_year': int(row.get('nam_sinh') or 0) if row.get('nam_sinh') else None,
                'skin_type': row.get('loai_da') or '',
                'sensitivity': row.get('muc_do_nhay_cam') or '',
                'skin_issues': concerns,
                'goals': [item.strip() for item in str(row.get('muc_tieu_cham_soc') or '').replace('|', ',').split(',') if item.strip()],
                'avoid_ingredients': avoid_ingredients,
                'budget': int(row.get('ngan_sach') or 0),
                'recent_keywords': recent_keywords,
                'updated_at': datetime.now(timezone.utc),
            }
            service.user_profiles.update_one({'customer_id': document['customer_id']}, {'$set': document}, upsert=True)
            synced += 1

    return synced


def sync_order_history() -> int:
    service = get_mongo_hybrid_service()
    synced = 0

    sql = """
        SELECT hd.ma_hoa_don, hd.ma_kh, hd.ngay_dat, hd.trang_thai, hd.tong_tien,
               ct.ma_san_pham, ct.so_luong, ct.don_gia,
               sp.ten_san_pham
        FROM hoa_don hd
        LEFT JOIN chi_tiet_hoa_don ct ON ct.ma_hoa_don = hd.ma_hoa_don
        LEFT JOIN san_pham sp ON sp.ma_san_pham = ct.ma_san_pham
        ORDER BY hd.ngay_dat DESC, hd.ma_hoa_don DESC, ct.id ASC
    """

    grouped: dict[int, dict] = {}
    with pg_connect() as conn, conn.cursor(cursor_factory=RealDictCursor) as cursor:
        cursor.execute(sql)
        for row in cursor.fetchall():
            order_id = int(row['ma_hoa_don'])
            document = grouped.setdefault(order_id, {
                'order_id': order_id,
                'customer_id': int(row.get('ma_kh') or 0),
                'status': row.get('trang_thai') or '',
                'total_amount': int(row.get('tong_tien') or 0),
                'created_at': row.get('ngay_dat') or datetime.now(timezone.utc),
                'items': [],
            })

            if row.get('ma_san_pham'):
                document['items'].append({
                    'product_id': row.get('ma_san_pham') or '',
                    'name': row.get('ten_san_pham') or '',
                    'quantity': int(row.get('so_luong') or 0),
                    'unit_price': int(row.get('don_gia') or 0),
                })

    for document in grouped.values():
        service.order_history.update_one({'order_id': document['order_id']}, {'$set': document}, upsert=True)
        synced += 1

    return synced


def main() -> int:
    try:
        print('Syncing products to MongoDB...')
        product_count = sync_products()
        print(f'Products synced: {product_count}')

        # TODO: Implement user profiles and order history sync from CSV if needed
        # print('Syncing user profiles to MongoDB...')
        # profile_count = sync_user_profiles()
        # print(f'User profiles synced: {profile_count}')

        # print('Syncing order history to MongoDB...')
        # order_count = sync_order_history()
        # print(f'Order history synced: {order_count}')
        return 0
    except Exception as error:
        print(f'Sync failed: {error}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
