"""Regressions for the failures found by the real 100-scenario acceptance run."""
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from chatbot_service.consultation.planning import ModelPlanner, QueryPlan
from chatbot_service.consultation.runtime import CATEGORIES, SKIN_TYPES
from chatbot_service.consultation.request_rules import RulePlanner
from chatbot_service.consultation.service import ConsultationService
from test_chat_regressions import doc, FakeCatalog


class AcceptanceFixes(unittest.TestCase):
    def service(self, docs=(), planner=None, answer=''):
        return ConsultationService(planner or RulePlanner(), FakeCatalog(list(docs)), lambda *_: answer, lambda _: [])

    def bad_model(self, **changes):
        wrong = QueryPlan(intent='search', query='sản phẩm', **changes)
        return ModelPlanner(lambda *_: wrong.model_dump_json(), CATEGORIES, SKIN_TYPES)

    def test_social_turns_do_not_ask_for_a_profile(self):
        service = self.service()
        service.planner = Mock()
        for message, expected in [('Xin chào Ngọc Vi', 'Mình là Ngọc Vi'), ('Cảm ơn bạn nhé', 'Không có gì')]:
            with self.subTest(message=message):
                result = service.respond(message, {'customer_profile': {'budget': 350000}})
                self.assertEqual(result['products'], [])
                self.assertIn(expected, result['answer'])
        service.planner.plan.assert_not_called()

    def test_comparison_without_buying_overrides_search_model(self):
        plan = self.bad_model().plan('So sánh toner và serum giúp tôi, chưa cần gợi ý mua', [], None)
        self.assertEqual(plan.intent, 'knowledge')

    def test_active_product_requests_are_searches(self):
        from chatbot_service import chatbot_flask as routes

        for message, expected in [
            ('cho tôi vài sản phẩm cho da tôi đi', 'PRODUCT_INQUIRY'),
            ('cho tôi vài sản phẩm Niacinamide', 'PRODUCT_INQUIRY'),
            ('cho tôi vài sản phẩm Salicylic Acid', 'PRODUCT_INQUIRY'),
        ]:
            with self.subTest(message=message):
                intent, _ = routes.classify_intent(message, [])
                self.assertEqual(intent, expected)
                self.assertEqual(RulePlanner().plan(message, []).intent, 'search')
        self.assertEqual(routes.rule_based_parse('cho tôi vài sản phẩm Niacinamide').thanh_phan_yeu_cau, ['niacinamide'])
        self.assertEqual(routes.rule_based_parse('cho tôi vài sản phẩm Salicylic Acid').thanh_phan_yeu_cau, ['bha'])
        self.assertEqual(routes.classify_intent('Niacinamide là gì', [])[0], 'COSMETIC_KNOWLEDGE')
        self.assertEqual(routes.classify_intent('có nên mua retinol không', [])[0], 'COSMETIC_KNOWLEDGE')

    def test_active_filters_use_full_ingredient_data(self):
        from chatbot_service import chatbot_flask as routes

        niacinamide = doc('niacinamide', thanh_phan_chinh='', thanh_phan_day_du='Aqua, Niacinamide 5%')
        bha = doc('bha', thanh_phan_chinh='', thanh_phan_day_du='Aqua, Salicylic Acid')
        unrelated = doc('unrelated', thanh_phan_chinh='', thanh_phan_day_du='Aqua, Glycerin')
        self.assertEqual([d.metadata['id'] for d in routes.filter_docs_by_ingredient(
            [niacinamide, bha, unrelated], 'niacinamide')], ['niacinamide'])
        self.assertEqual([d.metadata['id'] for d in routes.filter_docs_by_ingredient(
            [niacinamide, bha, unrelated], 'bha')], ['bha'])
        self.assertTrue(routes.skin_type_compatible('Da dầu', 'Da dầu/Hỗn hợp dầu'))
        self.assertTrue(routes.skin_type_compatible('Mọi loại da', 'Da dầu/Hỗn hợp dầu'))
        self.assertFalse(routes.skin_type_compatible('Da khô/Hỗn hợp khô', 'Da dầu/Hỗn hợp dầu'))

    def test_active_filters_accept_canonical_mongo_ingredient_aliases(self):
        from chatbot_service import chatbot_flask as routes

        niacinamide = routes.MockDocument(
            page_content='',
            metadata={'id': 'mongo-niacinamide', 'ten_san_pham': 'Serum phục hồi', 'thanh_phan': 'Aqua, Niacinamide 5%'},
            id='product_mongo-niacinamide',
        )
        bha = routes.MockDocument(
            page_content='',
            metadata={'id': 'mongo-bha', 'ten_san_pham': 'Gel làm sạch', 'thanh_phan_full': 'Aqua, Salicylic Acid'},
            id='product_mongo-bha',
        )
        unrelated = routes.MockDocument(
            page_content='',
            metadata={'id': 'mongo-unrelated', 'ten_san_pham': 'Kem dưỡng', 'thanh_phan_sach': 'Aqua, Glycerin'},
            id='product_mongo-unrelated',
        )
        self.assertEqual([d.metadata['id'] for d in routes.filter_docs_by_ingredient(
            [niacinamide, bha, unrelated], 'niacinamide')], ['mongo-niacinamide'])
        self.assertEqual([d.metadata['id'] for d in routes.filter_docs_by_ingredient(
            [niacinamide, bha, unrelated], 'bha')], ['mongo-bha'])

    def test_missing_promotion_data_is_never_invented(self):
        from chatbot_service import chatbot_flask as routes

        product = routes.MockDocument(
            page_content='',
            metadata={'id': 'no-promo', 'ten_san_pham': 'Serum thử nghiệm', 'gia_ban': 200000,
                      'thanh_phan_chinh': 'Aqua, Niacinamide', 'loai_san_pham': 'Serum / Tinh Chất'},
            id='product_no-promo',
        )
        formatted = routes.format_search_results([product])
        self.assertNotIn('ưu đãi giảm đến', formatted)
        self.assertIn('Giá gốc thị trường: Chưa có dữ liệu', formatted)
        self.assertIn('Tiền tiết kiệm: Chưa có dữ liệu', formatted)

    def test_retinal_and_retinol_remain_distinct(self):
        docs = [doc('retinal', loai_san_pham='Serum / Tinh Chất', thanh_phan_day_du='Aqua, Retinal'),
                doc('retinol', loai_san_pham='Serum / Tinh Chất', thanh_phan_day_du='Aqua, Retinol'),
                doc('neither', loai_san_pham='Serum / Tinh Chất')]
        out = self.service(docs).respond('Tìm serum retinal, không retinol')
        self.assertEqual([p['id'] for p in out['products']], ['retinal'])

    def test_dry_alcohol_exclusion_checks_cards_not_just_prose(self):
        docs = [doc('fatty', loai_san_pham='Kem / Gel / Dầu Dưỡng', thanh_phan_day_du='Aqua, Cetearyl Alcohol'),
                doc('ethanol', loai_san_pham='Kem / Gel / Dầu Dưỡng', thanh_phan_day_du='Aqua, Ethanol')]
        out = self.service(docs).respond('Tìm kem dưỡng không cồn khô')
        self.assertEqual([p['id'] for p in out['products']], ['fatty'])

    def test_model_cannot_reject_valid_fatty_alcohol_candidate(self):
        d = doc('A', ten_san_pham='Sản phẩm thử nghiệm A', loai_san_pham='Kem / Gel / Dầu Dưỡng',
                thanh_phan_day_du='Aqua, Cetearyl Alcohol')
        answer = 'Mình không tìm thấy kem dưỡng nào đáp ứng. Sản phẩm thử nghiệm A có cồn béo, không phù hợp với yêu cầu.'
        out = self.service([d], answer=answer).respond('Tìm kem dưỡng không cồn khô')
        self.assertEqual([p['id'] for p in out['products']], ['A'])
        self.assertNotIn('không tìm thấy', out['answer'])
        self.assertNotIn('không phù hợp', out['answer'])

    def test_model_cannot_invent_fragrance_in_verified_retinol(self):
        d = doc('A', ten_san_pham='Serum A', loai_san_pham='Serum / Tinh Chất', thanh_phan_day_du='Aqua, Retinol')
        answer = '[Serum A](index.php?r=chitiet&id=A) giá 200000đ, có chứa hương liệu.'
        out = self.service([d], answer=answer).respond('Tìm serum có retinol nhưng không hương liệu')
        self.assertEqual([p['id'] for p in out['products']], ['A'])
        self.assertNotIn('có chứa hương liệu', out['answer'])

    def test_minimum_and_range_are_actual_price_filters(self):
        docs = [doc(str(price), loai_san_pham='Serum / Tinh Chất', gia_ban=price)
                for price in (299999, 300000, 500000, 500001, 800000)]
        service = self.service(docs)
        for query, expected in [('Tìm serum trên 500k', ['500001', '800000']),
                                ('Tìm serum từ 300 đến 500k', ['300000', '500000']),
                                ('Tìm serum tối thiểu 500k', ['500000', '500001', '800000'])]:
            with self.subTest(query=query):
                self.assertEqual([p['id'] for p in service.respond(query)['products']], expected)

    def test_old_price_does_not_cap_new_purchase_or_inherit_profile(self):
        out = self.service([doc(gia_ban=900000)]).respond(
            'Chai cũ của tôi giá 350k; tìm toner mới giúp tôi, chưa chốt ngân sách',
            {'customer_profile': {'budget': 500000}, 'conversation_history': [{'role':'user','content':'Toner dưới 200k'}]})
        self.assertEqual(len(out['products']), 1)
        self.assertIsNone(out['diagnostics']['constraints']['maximum_price_vnd'])

    def test_followup_overrides_wrong_model_category_and_keeps_active(self):
        history = [{'role':'user','content':'Tìm serum retinol dưới 500k'}]
        plan = self.bad_model(category='Toner / Nước Cân Bằng Da').plan('Loại rẻ hơn dưới 200k đi', history, None)
        self.assertEqual(plan.category, 'Serum / Tinh Chất')
        self.assertEqual(plan.required_ingredients, ['retinol'])
        self.assertEqual(plan.intent, 'search')

    def test_followup_preserves_relative_and_category(self):
        history = [{'role':'user','content':'Tìm kem dưỡng cho mẹ tôi da khô'}]
        plan = self.bad_model().plan('Có loại rẻ hơn cho bà không?', history, None)
        self.assertEqual((plan.subject, plan.category, plan.skin_type), ('other', 'Kem / Gel / Dầu Dưỡng', 'Da khô/Hỗn hợp khô'))
        out = self.service(planner=self.bad_model()).respond('Có loại rẻ hơn cho bà không?', {
            'conversation_history':history, 'customer_profile':{'budget':500000, 'skin_type':'Da dầu/Hỗn hợp dầu'}})
        self.assertEqual(out['intent_mode'], 'clarify')
        self.assertIn('Kem / Gel / Dầu Dưỡng cho người thân', out['answer'])

    def test_missing_profile_asks_before_selecting_arbitrary_products(self):
        service = self.service([doc()])
        service.catalog.search = Mock(side_effect=AssertionError('Must clarify before selecting'))
        result = service.respond('Tìm sản phẩm phù hợp với da tôi')
        self.assertEqual(result['intent_mode'], 'clarify')
        self.assertEqual(result['products'], [])
        self.assertIn('Da bạn thuộc loại nào', result['answer'])

    def test_omitted_routine_category_is_not_an_ingredient(self):
        result = self.service([doc('cream', loai_san_pham='Kem / Gel / Dầu Dưỡng')]).respond('Gợi ý routine không dùng toner, tổng 700k')
        self.assertEqual(result['diagnostics']['constraints']['avoid_ingredients'], [])

    def test_revoking_one_condition_preserves_other_conditions(self):
        service = self.service([doc('fragrance', thanh_phan_day_du='Aqua, Parfum'),
                                doc('lanolin', thanh_phan_day_du='Aqua, Lanolin')])
        history = [{'role':'user','content':'Tìm serum không hương liệu và lanolin'}]
        out = service.respond('Giờ bỏ điều kiện không hương liệu, tìm toner cho tôi', {'conversation_history':history})
        self.assertEqual([p['id'] for p in out['products']], ['fragrance'])
        self.assertEqual(out['diagnostics']['constraints']['avoid_ingredients'], ['lanolin'])
        history += [{'role':'user','content':'Bỏ điều kiện không hương liệu'}]
        out = service.respond('Giờ tìm toner', {'conversation_history':history})
        self.assertEqual([p['id'] for p in out['products']], ['fragrance'])

    def test_merged_variant_claims_never_reach_recommendation(self):
        product = doc(thanh_phan_day_du='Aqua, Lavender Oil. 2. Nước Hoa Hồng Không Mùi Thành phần: Aqua, Glycerin',
                      mo_ta='Sản phẩm hoàn toàn không mùi, chứa niacinamide')
        self.assertTrue(product.metadata['variant_unverified'])
        answer = '[Toner thử nghiệm](index.php?r=chitiet&id=1) giá 200000đ, hoàn toàn không mùi và có niacinamide.'
        out = self.service([product], answer=answer).respond('Gợi ý toner')
        self.assertNotIn('hoàn toàn không mùi', out['answer'])
        self.assertNotIn('niacinamide', out['answer'])
        self.assertIn('chưa xác minh', out['answer'])
        self.assertEqual(out['products'][0]['summary'], '')

    def test_merged_formula_not_eligible_for_ingredient_filter(self):
        merged = doc(thanh_phan_day_du='Aqua. 2. Serum Khác Thành phần: Aqua, Retinol')
        self.assertFalse(self.service([merged]).respond('Tìm sản phẩm có retinol')['products'])
        self.assertFalse(self.service([merged]).respond('Tìm toner không hương liệu')['products'])

    def test_owned_cleanser_is_not_a_missing_or_new_purchase(self):
        docs = [doc('clean', loai_san_pham='Sữa Rửa Mặt'),
                doc('cream', loai_san_pham='Kem / Gel / Dầu Dưỡng'),
                doc('spf', loai_san_pham='Chống Nắng Da Mặt')]
        out = self.service(docs).respond('Tôi đã có sữa rửa mặt, tìm routine phần mua thêm dưới 500k')
        self.assertEqual({p['id'] for p in out['products']}, {'cream', 'spf'})
        self.assertEqual(out['routine']['total_vnd'], 400000)
        self.assertEqual(out['routine']['missing_steps'], [])
        self.assertIn('Sữa Rửa Mặt', out['routine']['owned_steps'])
        self.assertIn('Bạn đã có', out['answer'])

    def test_incomplete_owned_routine_explicitly_reports_missing_step(self):
        out = self.service([doc('cream', loai_san_pham='Kem / Gel / Dầu Dưỡng')],
                           answer='Chỉ cần kem này là đủ.').respond('Tôi đã có sữa rửa mặt, tìm routine dưới 500k')
        self.assertEqual(out['routine']['missing_steps'], ['Chống Nắng Da Mặt'])
        self.assertIn('Chưa chọn được: Chống Nắng Da Mặt', out['answer'])

    def test_affordable_routine_not_limited_to_first_three_expensive_hits(self):
        docs = []
        for category in ('Sữa Rửa Mặt', 'Kem / Gel / Dầu Dưỡng', 'Chống Nắng Da Mặt'):
            for n, price in enumerate([400000, 350000, 300000, 50000]):
                docs.append(doc(category+str(n), loai_san_pham=category, gia_ban=price))
        result = self.service(docs).respond('Tìm routine tổng dưới 200k')
        self.assertEqual(len(result['products']), 3)
        self.assertEqual(result['routine']['missing_steps'], [])


class IngredientSafetyGate(unittest.TestCase):
    def test_vitamin_c_sensitive_acne_profile_gets_explained_delay(self):
        from chatbot_service import chatbot_flask as routes

        message = 'cho tôi một sản phẩm vitamin c làm sáng da hợp với tui'
        profiles = [
            {'skin_type': 'Da dầu/Hỗn hợp dầu', 'sensitivity': 'Rất dễ', 'concerns': ['Mụn viêm, sưng đỏ']},
            {'loai_da': 'Da dầu/Hỗn hợp dầu', 'do_nhay_cam': 'Rất dễ', 'van_de_da': 'Mụn viêm, sưng đỏ'},
        ]

        for profile in profiles:
            with self.subTest(profile=profile):
                yc = routes.rule_based_parse(message)
                self.assertIn('vitamin c', yc.thanh_phan_yeu_cau)
                self.assertTrue(routes.should_delay_vitamin_c_for_profile(message, profile, yc))
                answer = routes.build_vitamin_c_delay_answer(profile, yc)
                self.assertIn('chưa khuyên bạn chốt mua vitamin C ngay lúc này', answer)
                self.assertIn('Không phải vì SkinSyntax không có vitamin C', answer)
                self.assertIn('mức nhạy cảm **Rất dễ**', answer)
                self.assertIn('Mụn viêm, sưng đỏ', answer)


class ConversationAccess(unittest.TestCase):
    def test_cross_owner_send_stops_before_generation_and_save(self):
        from chatbot_service import chatbot_flask as routes
        with patch.object(routes, 'get_conversation_by_id', return_value=None), \
             patch.object(routes, 'xu_ly_cau_hoi') as generate, patch.object(routes, 'save_chat_messages') as save:
            response = routes.app.test_client().post('/api/chat/auto', json={
                'message':'Tiếp tục', 'user_email':'b@example.invalid', 'conversation_id':'0123456789abcdef01234567'})
        self.assertEqual(response.status_code, 403)
        self.assertFalse(response.json['ok'])
        generate.assert_not_called()
        save.assert_not_called()

    def test_missing_session_and_invalid_id_rejected(self):
        from chatbot_service import chatbot_flask as routes
        with patch.object(routes, 'xu_ly_cau_hoi') as generate:
            for payload, status in [({'conversation_id':'0123456789abcdef01234567'}, 401),
                                    ({'conversation_id':'not-an-id', 'user_email':'a@example.invalid'}, 400)]:
                with self.subTest(payload=payload):
                    response = routes.app.test_client().post('/api/chat/auto', json={'message':'Tiếp tục', **payload})
                    self.assertEqual(response.status_code, status)
        generate.assert_not_called()


if __name__ == '__main__': unittest.main()
