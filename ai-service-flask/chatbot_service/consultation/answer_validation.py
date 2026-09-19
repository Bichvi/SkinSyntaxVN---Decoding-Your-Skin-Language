"""Check recommendation prose against selected facts, with a factual fallback."""
import re
from .presentation import product_cards


def selection_label(plan):
    target = plan.category or 'sản phẩm'
    if plan.required_ingredients:
        target += ' chứa ' + ', '.join(plan.required_ingredients)
    return target


def empty_answer(plan, reasons, candidate_count):
    target = selection_label(plan)
    if 'ingredients_unverified' in reasons:
        return (f'Mình đã tìm trong nhóm {target}, nhưng các lựa chọn còn lại chưa có bảng thành phần đầy đủ '
                'để đối chiếu thành phần bạn cần tránh. Điều này chưa có nghĩa cửa hàng không có sản phẩm đó. '
                'Bạn có chai cụ thể muốn mình kiểm tra không?')
    conditions = []
    if 'budget_or_unknown_price' in reasons:
        conditions.append('ngân sách hoặc giá chưa xác định')
    if 'excluded_ingredient' in reasons:
        conditions.append('thành phần cần tránh')
    if 'stock' in reasons:
        conditions.append('tình trạng còn bán/còn hàng')
    if conditions:
        return f'Mình đã kiểm tra nhóm {target}, nhưng chưa chọn được sản phẩm đáp ứng đồng thời các điều kiện: ' + ', '.join(conditions) + '. Bạn muốn kiểm tra một sản phẩm cụ thể hay điều chỉnh khoảng giá?'
    return f'Mình chưa tìm được lựa chọn thuộc nhóm {target} đáp ứng yêu cầu trong dữ liệu hiện có. Bạn có tên sản phẩm hoặc thương hiệu muốn mình tìm cụ thể không?'


def recommendation_errors(answer, docs, plan, context=None):
    context = context or {}
    errors = []
    if re.search(r'(?:không|chưa)\s+(?:tìm\s+(?:thấy|được)\s+|có\s+)(?:sản phẩm|lựa chọn).*?(?:nào|đề xuất|gợi ý)', answer, re.I):
        errors.append('denies_selected_products')
    cards = product_cards(docs)
    if context.get('avoid_ingredients'):
        # The policy already checked the complete formula. Free-form prose
        # must not reverse that verdict or invent an excluded ingredient.
        errors.append('ingredient_constraints_require_verified_summary')
    if any(d.metadata.get('variant_unverified') for d in docs):
        errors.append('variant_facts_unverified')
    if plan.intent == 'routine' and (context.get('missing_steps') or context.get('owned_steps')):
        # Keep the owned/new/missing distinction explicit in every response.
        errors.append('routine_scope_needs_summary')
    # Catalog marketing copy cannot establish individual tolerability, even
    # when the customer has not reported irritation or filled in a profile.
    if re.search(r'an toàn (?:cho|với) (?:cả |mọi |làn |da|bạn)|không gây kích ứng|bảo đảm.*hợp da', answer, re.I):
        errors.append('unsupported_safety_guarantee')
    if context.get('reported_irritation') and re.search(r'phù hợp với (?:bạn|da|tình trạng)|phù hợp cho da|an toàn cho da', answer, re.I):
        errors.append('unsupported_suitability')
    if context.get('defer_retinoid_start'):
        # Do not turn generic catalog directions into a personal starting regimen.
        errors.append('retinoid_requires_review')
    allowed_prices = {c['price'] for c in cards} | {context.get('budget_vnd'), context.get('routine_total_vnd')}
    from .money import parse_budget
    for amount in re.findall(r'\d+(?:[.,]\d+)*\s*(?:VNĐ|VND|đồng|đ\b|k\b|nghìn|triệu)', answer, re.I):
        if parse_budget(amount) not in allowed_prices:
            errors.append('unsupported_price')
    for card in cards:
        if card['name'].casefold() not in answer.casefold():
            errors.append('missing_selected_product')
            break
    allowed_urls = {c['detail_url'] for c in cards}
    for url in re.findall(r'\]\(([^)]+)\)', answer):
        if url not in allowed_urls:
            errors.append('unknown_product_link')
    # A previous topic must not replace the explicit category in the introduction.
    if plan.category:
        from .request_rules import explicit_category
        intro = answer.split('\n', 1)[0]
        category = explicit_category(intro)
        if category and category != plan.category:
            errors.append('stale_category')
    return list(dict.fromkeys(errors))


def catalog_answer(docs, plan, context):
    target = selection_label(plan)
    intro = f'Mình tìm được {len(docs)} lựa chọn {target} để bạn tham khảo, đã lọc theo yêu cầu trong dữ liệu cửa hàng:'
    if plan.intent == 'routine':
        intro = 'Mình chọn được phương án chăm sóc cơ bản sau' + (' (còn thiếu bước):' if context.get('missing_steps') else ':')
    lines = [intro]
    for card in product_cards(docs):
        price = f"{card['price']:,}".replace(',', '.') + 'đ' if card['price'] else 'chưa xác định giá'
        lines.append(f"- [{card['name']}]({card['detail_url']}) — {price}.")
    if context.get('avoid_ingredients'):
        lines.append('Mình đã đối chiếu các thành phần bạn cần tránh với bảng thành phần được lưu; điều này không bảo đảm sản phẩm sẽ hợp da hoặc không gây kích ứng.')
    if any(d.metadata.get('variant_unverified') for d in docs):
        lines.append('Một số dữ liệu mô tả gộp nhiều phiên bản; mình chưa xác minh được thành phần và cách dùng của đúng phiên bản đó. Các lựa chọn này chỉ để tham khảo tên và giá.')
    if plan.intent == 'routine':
        if context.get('owned_steps'):
            lines.append('Bạn đã có: ' + ', '.join(context['owned_steps']) + '; không tính mua lại trong tổng tiền này.')
        lines.append('Tổng tiền phần mua mới: ' + f"{context['routine_total_vnd']:,}".replace(',', '.') + 'đ.')
        if context.get('missing_steps'):
            lines.append('Chưa chọn được: ' + ', '.join(context['missing_steps']) + '.')
    lines.append('Bạn muốn mình phân tích kỹ lựa chọn nào trước?')
    answer = '\n\n'.join([lines[0], '\n'.join(lines[1:1 + len(docs)]), *lines[1 + len(docs):]])
    if context.get('defer_retinoid_start'):
        from .advisory import retinol_caution
        answer = retinol_caution() + '\n\n' + answer
    return answer
