"""
Audit schema of skinsyntax.san_pham for clustering research.
"""

import os
import json
from pymongo import MongoClient

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ATLAS_URI = 'mongodb+srv://lamngoc562004_db_user:6NQ1A2vXuDnbWchv@skinsyntaxvn-db.3edac4m.mongodb.net/?appName=SkinSyntaxVN-DB'


def audit_catalog_schema():
    client = MongoClient(ATLAS_URI)
    db = client['skinsyntax']
    col = db['san_pham']

    total_docs = col.count_documents({})
    fields_info = {}

    for doc in col.find():
        for k, v in doc.items():
            if k not in fields_info:
                fields_info[k] = {'non_null_count': 0, 'types': set(), 'example': None}
            if v is not None and v != '' and v != []:
                fields_info[k]['non_null_count'] += 1
                fields_info[k]['types'].add(type(v).__name__)
                if fields_info[k]['example'] is None:
                    # ascii representation for safe printing/json
                    fields_info[k]['example'] = str(v)[:100]

    # Expert assessment for clustering suitability
    field_evaluations = {
        'ma_san_pham': (False, 'Discrete unique primary key; clustering on IDs has no mathematical semantic meaning.'),
        'ten_san_pham': (False, 'Freeform textual title; high cardinality, needs NLP embedding or serves as label only.'),
        'ma_danh_muc': (False, 'Arbitrary database integer identifier; Euclidean distance between category IDs is meaningless.'),
        'danh_muc_day_du': (True, 'Hierarchical taxonomic breadcrumb; highly suitable for deriving one-hot role/category features.'),
        'ma_thuong_hieu': (False, 'Arbitrary brand ID; unsuitable for direct numerical Euclidean distance.'),
        'gia_ban': (True, 'Continuous price in VND; highly suitable after log1p and z-score normalization.'),
        'gia_thi_truong': (False, 'Reference list price; redundant with gia_ban.'),
        'tien_tiet_kiem': (False, 'Derived promotional difference; unstable and market-dependent.'),
        'phan_tram_giam': (False, 'Promotional discount rate; business metadata, not intrinsic product property.'),
        'dung_tich': (False, 'Unstructured capacity string (e.g. 50ml, 150ml, 30g); inconsistent units.'),
        'loai_da': (True, 'Skin type suitability string; suitable for multi-hot skin tag encoding (Oily, Dry, Sensitive, etc.).'),
        'ma_loai_da': (False, 'Arbitrary integer ID for skin type; redundant with loai_da text.'),
        'ma_xuat_xu': (False, 'Origin country integer ID; sparse categorical.'),
        'ma_noi_san_xuat': (False, 'Manufacturing country integer ID; sparse categorical.'),
        'diem_danh_gia': (False, 'Average rating (0-5); noisy engagement proxy, not product characteristic.'),
        'so_luong_danh_gia': (False, 'Review count; engagement volume, not sales or product property.'),
        'so_luong_da_ban': (False, 'CRITICAL: Must NEVER be used as verified sales. Highly skewed synthetic/seeded counter.'),
        'thanh_phan': (False, 'Key active ingredients summary; useful in advanced stages, but excluded in Step 6A for manual inspectability.'),
        'thanh_phan_full': (False, 'Raw INCI chemical composition; excessive dimensionality for manual Euclidean calculation.'),
        'thanh_phan_sach': (False, 'Tokenized ingredient array; high dimensionality (>500 dimensions).'),
        'mo_ta': (False, 'Unstructured HTML product description; high-dimensional text.'),
        'hdsd': (False, 'Usage instructions text; unsuitable for product clustering.'),
        'link_hinh_anh': (False, 'Asset URL; non-numerical.'),
        'ngay_tao': (False, 'Creation timestamp; temporal metadata.'),
        'luot_xem': (False, 'View count; popularity proxy, not product formulation.'),
        'trang_thai': (False, 'System lifecycle status string (active); constant filter.'),
        'da_khoi_tao_kho': (False, 'Internal inventory initialization flag.'),
        'so_luong_ton_kho': (False, 'Inventory count; logistics metadata.'),
        'trang_thai_kho': (False, 'Stock availability status.'),
        'updated_at': (False, 'Timestamp.'),
        'da_khoi_tao_so_luong_ban': (False, 'Internal seeding flag.')
    }

    schema_report = []
    for k in sorted(fields_info.keys()):
        meta = fields_info[k]
        cov = round(meta['non_null_count'] / total_docs * 100, 2)
        suitable, reason = field_evaluations.get(k, (False, 'Not evaluated / unverified metadata'))
        schema_report.append({
            'field': k,
            'coverage_percent': cov,
            'non_null_count': meta['non_null_count'],
            'total_docs': total_docs,
            'data_types': sorted(list(meta['types'])),
            'example': meta['example'],
            'suitable_for_clustering': suitable,
            'reason': reason
        })

    out_file = os.path.join(SCRIPT_DIR, 'schema_audit.json')
    with open(out_file, 'w', encoding='utf-8') as f:
        json.dump({
            'total_products_audited': total_docs,
            'collection': 'skinsyntax.san_pham',
            'schema_fields': schema_report
        }, f, ensure_ascii=False, indent=2)

    print(f"Audit completed for {total_docs} products. Saved: {out_file}")
    for r in schema_report:
        suit_str = "YES" if r['suitable_for_clustering'] else "NO "
        print(f"[{suit_str}] {r['field']:25s} | Cov: {r['coverage_percent']:6.2f}% | {r['reason'][:60]}")


if __name__ == '__main__':
    audit_catalog_schema()
