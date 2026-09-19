"""Regression checks for the real retinol -> toner -> acne failure modes."""
import json
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from chatbot_service.consultation.planning import ModelPlanner, QueryPlan
from chatbot_service.consultation.request_rules import RulePlanner, CATEGORY_WORDS
from chatbot_service.consultation.context import product_metadata, normalize_profile
from chatbot_service.consultation.ingredients import extract_exclusions, has_ingredient
from chatbot_service.consultation.policy import eligible_products
from chatbot_service.consultation.catalog import MongoCatalog, CatalogUnavailable
from chatbot_service.consultation.service import ConsultationService


def doc(pid='1', **overrides):
    fields = dict(ma_san_pham=pid, ten_san_pham='Toner thử nghiệm', loai_san_pham='Toner / Nước Cân Bằng Da',
                  gia_ban=200000, thanh_phan_day_du='Aqua, Glycerin, Panthenol', trang_thai='active')
    fields.update(overrides)
    return SimpleNamespace(id='product_' + pid, metadata=product_metadata(fields), page_content='')


class FakeCatalog:
    def __init__(self, docs): self.docs = docs
    def document(self, metadata): return SimpleNamespace(id='product_' + metadata['id'], metadata=metadata, page_content='')
    def search(self, query, filters=None, limit=20):
        return [d for d in self.docs if not filters or all(d.metadata.get(k) == v for k, v in filters.items())][:limit]


class Requests(unittest.TestCase):
    def test_catalog_copy_cannot_guarantee_safety_for_sensitive_skin(self):
        from chatbot_service.consultation.answer_validation import recommendation_errors
        product = doc()
        plan = QueryPlan(intent='search', query='tìm toner')
        errors = recommendation_errors('Toner thử nghiệm an toàn cho cả làn da nhạy cảm.', [product], plan, {})
        self.assertIn('unsupported_safety_guarantee', errors)

    def test_positive_and_negative_ingredients_stay_separate(self):
        for text in ('tìm serum không hương liệu nhưng có retinol', 'tìm serum không chứa hương liệu và có retinol'):
            p = RulePlanner().plan(text, [])
            self.assertIn('retinol', p.required_ingredients)
            self.assertNotIn('retinol', p.exclusions)
            self.assertIn('hương liệu', p.exclusions)

    def test_explicit_brand_in_fallback(self):
        p = RulePlanner().plan('tìm toner thương hiệu ZZZTestNoSuchBrand dưới 500k', [])
        self.assertEqual(p.brand, 'ZZZTestNoSuchBrand')

    def test_all_category_chips_override_bad_model_and_history(self):
        wrong = QueryPlan(intent='routine', query='toner retinol', category='Toner / Nước Cân Bằng Da', required_ingredients=['retinol'], brand='Invented')
        planner = ModelPlanner(lambda *_: wrong.model_dump_json(), list(CATEGORY_WORDS), [])
        for category in CATEGORY_WORDS:
            with self.subTest(category=category):
                p = planner.plan('Gợi ý cho tôi sản phẩm thuộc nhóm: ' + category,
                                 [{'role':'assistant','content':'Không có toner'}], None)
                self.assertEqual((p.intent, p.category), ('search', category))
                self.assertEqual(p.required_ingredients, [])
                self.assertIsNone(p.brand)

    def test_natural_requests(self):
        cases = [
            ('tôi có nên mua 1 chai retinol ở hiện tại không', 'knowledge'),
            ('bạn kiếm cho mình 1 chai retinol đi', 'search'),
            ('kiem cho minh 1 chai retinol', 'search'),
            ('tìm routine cho da dầu', 'routine'),
            ('routine gồm những bước nào', 'knowledge'),
            ('Có cần dùng toner không', 'knowledge'),
            ('gợi ý cho tôi hỗ trợ trị mụn', 'search'),
            ('kiểm tra giỏ hàng', 'cart'),
        ]
        for message, intent in cases:
            with self.subTest(message=message):
                self.assertEqual(RulePlanner().plan(message, []).intent, intent)
        p = RulePlanner().plan('bạn kiếm cho mình 1 chai retinol đi', [])
        self.assertEqual((p.required_ingredients, p.count), (['retinol'], 1))

    def test_negated_active_not_required(self):
        for message in ('tìm serum không retinol', 'tìm serum không chứa retinol', 'tìm serum tránh retinol'):
            with self.subTest(message=message):
                p = RulePlanner().plan(message, [])
                self.assertNotIn('retinol', p.required_ingredients)
                self.assertIn('retinol', p.exclusions)

    def test_unlimited_is_not_ingredient(self):
        self.assertEqual(extract_exclusions('routine không giới hạn'), [])
        self.assertEqual(extract_exclusions('tìm serum không quá 500k'), [])

    def test_followup_and_topic_reset(self):
        history = [{'role':'user','content':'tìm serum retinol'}, {'role':'assistant','content':'Bạn dùng toner nhé'}]
        followup = RulePlanner().plan('loại rẻ hơn dưới 200k', history)
        self.assertEqual(followup.category, 'Serum / Tinh Chất')
        self.assertIn('retinol', followup.required_ingredients)
        new = RulePlanner().plan('gợi ý toner', history)
        self.assertEqual(new.required_ingredients, [])


class IngredientsAndData(unittest.TestCase):
    def test_fragrance_aliases(self):
        for query in ('Fragrance/Parfum', 'fragrance', 'parfum', 'hương liệu'):
            for actual in ('Fragrance', 'Parfum', 'Perfume'):
                with self.subTest(query=query, actual=actual):
                    self.assertTrue(has_ingredient('Aqua, ' + actual, query))

    def test_full_data_mapping(self):
        m = product_metadata({'ma_san_pham':'5','thanh_phan_day_du':'Aqua, Retinol','hdsd':'Hướng dẫn của hãng','loai_san_pham':'Serum / Tinh Chất'})
        self.assertEqual(m['id'], '5')
        self.assertEqual(m['thanh_phan_day_du'], 'Aqua, Retinol')
        self.assertTrue(m['ingredients_complete'])
        self.assertEqual(m['huong_dan_su_dung'], 'Hướng dẫn của hãng')

    def test_unknown_is_not_complete(self):
        for unknown in ('Unknown', 'N/A', '', None):
            with self.subTest(value=unknown):
                d = doc(thanh_phan_day_du=unknown)
                selected, rejected = eligible_products([d], exclusions=['Lanolin'])
                self.assertFalse(selected)
                self.assertEqual(rejected[0]['reason'], 'ingredients_unverified')

    def test_filter_actual_profile_keeps_known_good(self):
        selected, rejected = eligible_products([doc(), doc('2', thanh_phan_day_du='Aqua, Parfum'),
                                              doc('3', thanh_phan_day_du='Aqua, Lanolin')],
                                              exclusions=['Fragrance/Parfum','Lanolin'], budget=500000)
        self.assertEqual([d.id for d in selected], ['product_1'])
        self.assertEqual([r['reason'] for r in rejected], ['excluded_ingredient'] * 2)

    def test_fatty_alcohol(self):
        self.assertFalse(has_ingredient('Cetearyl Alcohol, Cetyl Alcohol', 'cồn'))
        self.assertTrue(has_ingredient('Alcohol Denat., Glycerin', 'cồn'))

    def test_hidden_and_zero_stock(self):
        for fields in ({'trang_thai':'hidden'}, {'so_luong_ton':0}, {'stock':0}):
            with self.subTest(fields=fields):
                selected, rejected = eligible_products([doc(**fields)])
                self.assertFalse(selected)
                self.assertEqual(rejected[0]['reason'], 'stock')

    def test_profile_none_marker(self):
        self.assertEqual(normalize_profile({'avoid_ingredients':['Không có / không quan tâm']})['avoid_ingredients'], [])

    def test_hydration_does_not_keep_missing_products(self):
        class Collection:
            def find(self, *args): return [{'ma_san_pham':'1','ten_san_pham':'Fresh','gia_ban':123000,'thanh_phan_day_du':'Aqua'}]
        catalog = MongoCatalog(None, lambda **kw: SimpleNamespace(**kw), Collection())
        rows = catalog.hydrate([doc(), doc('999')])
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0].metadata['gia_ban'], 123000)
        self.assertTrue(rows[0].metadata['ingredients_complete'])


class Responses(unittest.TestCase):
    def service(self, docs, answer='Mình không có sản phẩm toner nào để đề xuất.'):
        return ConsultationService(RulePlanner(), FakeCatalog(docs), lambda *_: answer, lambda _: [])

    def test_nonempty_catalog_cannot_deny_products(self):
        out = self.service([doc()]).respond('gợi ý toner')
        self.assertEqual(len(out['products']), 1)
        self.assertIn('Toner thử nghiệm', out['answer'])
        self.assertNotIn('không có sản phẩm', out['answer'])
        self.assertIn('denies_selected_products', out['diagnostics']['answer_validation'])

    def test_empty_explains_missing_inci(self):
        out = self.service([doc(thanh_phan_day_du='')]).respond('gợi ý toner', {'customer_profile':{'avoid_ingredients':['Lanolin']}})
        self.assertEqual(out['products'], [])
        self.assertIn('bảng thành phần đầy đủ', out['answer'])
        self.assertFalse(out['fallback'])

    def test_generation_failure_uses_catalog(self):
        service = self.service([doc()])
        def fail(*args): raise TimeoutError()
        service.generate = fail
        out = service.respond('gợi ý toner')
        self.assertIn('Toner thử nghiệm', out['answer'])
        self.assertEqual(len(out['products']), 1)

    def test_catalog_failure_is_distinct(self):
        service = self.service([])
        def fail(*args): raise CatalogUnavailable()
        service.catalog.search = fail
        out = service.respond('gợi ý toner')
        self.assertTrue(out['fallback'])
        self.assertEqual(out['fallback_reason'], 'catalog_unavailable')
        self.assertIn('lỗi', out['answer'])

    def test_required_retinol_not_substituted(self):
        out = self.service([doc(), doc('2', ten_san_pham='Retinol thử nghiệm', thanh_phan_day_du='Aqua, Retinol')]).respond('kiếm cho mình 1 chai retinol')
        self.assertEqual([p['id'] for p in out['products']], ['2'])

    def test_acne_does_not_repeat_toner(self):
        out = self.service([doc('2', ten_san_pham='Gel trị mụn', loai_san_pham='Hỗ Trợ Trị Mụn')]).respond(
            'gợi ý Hỗ Trợ Trị Mụn', {'conversation_history':[{'role':'assistant','content':'Không có toner'}]})
        self.assertEqual(out['intent_mode'], 'search')
        self.assertNotIn('toner', out['answer'].casefold())
        self.assertIn('Gel trị mụn', out['answer'])

    def test_advisory_is_not_product_search(self):
        service = self.service([],'Bạn đang hỏi quyết định sử dụng retinol.')
        def fail(*args): raise AssertionError('Must not search')
        service.catalog.search = fail
        out = service.respond('có nên mua retinol hiện tại không')
        self.assertEqual(out['intent_mode'], 'knowledge')
        self.assertEqual(out['products'], [])

    def test_irritated_skin_does_not_receive_starting_regimen(self):
        profile = {'concerns':['Da khô căng', 'bong tróc']}
        d = doc('2', ten_san_pham='Retinol thử nghiệm', loai_san_pham='Serum / Tinh Chất', thanh_phan_day_du='Aqua, Retinol')
        service = self.service([d], 'Retinol thử nghiệm phù hợp với bạn, dùng hàng ngày sáng và tối.')
        advisory = service.respond('có nên mua retinol hiện tại không', {'customer_profile':profile})
        self.assertIn('chưa khuyên bắt đầu retinol ngay', advisory['answer'])
        self.assertNotIn('hàng ngày', advisory['answer'])
        search = service.respond('kiếm cho mình 1 chai retinol', {'customer_profile':profile})
        self.assertEqual(len(search['products']), 1)
        self.assertIn('chưa khuyên bắt đầu retinol ngay', search['answer'])
        self.assertNotIn('hàng ngày', search['answer'])

    def test_wrong_price_is_replaced(self):
        answer = '[Toner thử nghiệm](index.php?r=chitiet&id=1) giá 999.000đ.'
        result = self.service([doc()], answer).respond('gợi ý toner')
        self.assertNotIn('999.000', result['answer'])
        self.assertIn('200.000', result['answer'])

    def test_current_report_can_clear_old_symptom(self):
        from chatbot_service.consultation.advisory import reported_irritation
        self.assertFalse(reported_irritation('Da đã hết bong tróc', {'concerns':['bong tróc']}))
        self.assertTrue(reported_irritation('Da vẫn bong tróc', {}))

    def test_bottle_prioritizes_serum_over_mask(self):
        docs = [doc('1', ten_san_pham='Mặt nạ retinol', loai_san_pham='Mặt Nạ Giấy', thanh_phan_day_du='Aqua, Retinol'),
                doc('2', ten_san_pham='Serum retinol', loai_san_pham='Serum / Tinh Chất', thanh_phan_day_du='Aqua, Retinol')]
        result = self.service(docs).respond('kiếm 1 chai retinol')
        self.assertEqual([p['id'] for p in result['products']], ['2'])

    def test_history_unlimited_overrides_account_budget(self):
        result = self.service([doc(gia_ban=900000)]).respond('gợi ý toner', {
            'customer_profile':{'budget':500000},
            'conversation_history':[{'role':'user','content':'ngân sách không giới hạn'}]})
        self.assertEqual(len(result['products']), 1)

    def test_new_person_does_not_inherit_previous_budget(self):
        result = self.service([doc(gia_ban=900000)]).respond('gợi ý toner cho mẹ da khô', {
            'customer_profile':{'budget':500000},
            'conversation_history':[{'role':'user','content':'tìm serum dưới 100k'}]})
        self.assertEqual(len(result['products']), 1)

    def test_user_exclusions_survive_category_switch(self):
        result = self.service([doc(thanh_phan_day_du='Aqua, Parfum')]).respond('gợi ý toner', {
            'conversation_history':[{'role':'user','content':'tìm serum không hương liệu'},
                                    {'role':'assistant','content':'Mình sẽ tìm serum'}]})
        self.assertEqual(result['products'], [])

    def test_assistant_does_not_create_exclusion(self):
        result = self.service([doc(thanh_phan_day_du='Aqua, Parfum')]).respond('gợi ý toner', {
            'conversation_history':[{'role':'assistant','content':'Bạn nên tránh hương liệu'}]})
        self.assertEqual(len(result['products']), 1)


if __name__ == '__main__':
    unittest.main(verbosity=2)
