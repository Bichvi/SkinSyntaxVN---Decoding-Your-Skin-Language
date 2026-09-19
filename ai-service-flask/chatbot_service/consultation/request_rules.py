"""Deterministic interpretation of explicit requests; history only fills gaps."""
import re
import unicodedata

from .context import asks_for_other_person
from .ingredients import extract_exclusions, has_ingredient
from .planning import QueryPlan


def folded(text):
    return ''.join(c for c in unicodedata.normalize('NFD', str(text).casefold().replace('đ', 'd'))
                   if unicodedata.category(c) != 'Mn')


CATEGORY_WORDS = {
    'Sữa Rửa Mặt': ('sua rua mat', 'srm', 'cleanser'),
    'Tẩy Trang Mặt': ('tay trang', 'micellar'),
    'Toner / Nước Cân Bằng Da': ('toner', 'nuoc can bang', 'nuoc hoa hong'),
    'Serum / Tinh Chất': ('serum', 'tinh chat', 'ampoule'),
    'Kem / Gel / Dầu Dưỡng': ('kem duong', 'gel duong', 'dau duong'),
    'Lotion / Sữa Dưỡng': ('lotion', 'sua duong'),
    'Mặt Nạ Giấy': ('mat na giay',), 'Mặt Nạ Rửa': ('mat na rua',),
    'Mặt Nạ Ngủ': ('mat na ngu',),
    'Chống Nắng Da Mặt': ('chong nang', 'kcn', 'sunscreen'),
    'Tẩy Tế Bào Chết Da Mặt': ('tay te bao chet',),
    'Hỗ Trợ Trị Mụn': ('ho tro tri mun', 'tri mun', 'cham mun'),
}


def explicit_category(message):
    t = folded(message)
    # The category chip is an exact request, even if earlier text mentions another type.
    if 'thuoc nhom:' in t:
        t = t.split('thuoc nhom:', 1)[1].strip()
    matches = [c for c, aliases in CATEGORY_WORDS.items()
               if any(re.search(r'\b' + re.escape(w) + r'\b', t) for w in aliases)]
    return matches[0] if len(matches) == 1 else None


def explicit_intent(message, current_id=None):
    t = folded(message)
    if any(w in t for w in ('gio hang', 'gio cua toi', 'xung dot')):
        return 'cart'
    if current_id and any(w in t for w in ('san pham nay', 'cai nay', 'em nay', 'chai nay', 'thanh phan', 'gia bao nhieu')):
        return 'product_detail'
    if re.search(r'\b(?:chua|khong)\s+(?:can|muon)\s+(?:goi y\s+)?mua\b', t):
        return 'knowledge'
    if 'so sanh' in t and re.search(r'\btoner\b', t) and re.search(r'\bserum\b', t):
        return 'knowledge'
    if re.search(r'\b(co nen|co can|la gi|tac dung|co che|phan biet|cach dung|gom nhung buoc|gom cac buoc)\b', t):
        return 'knowledge'
    if re.search(r'\b(routine|chu trinh|tron bo|bo san pham)\b', t):
        return 'routine'
    product_request = re.search(
        r'\b(?:cho toi|cho tui|cho minh|cho em)\b.*\b(?:san pham|chai|lo|serum|toner|kem|sua rua mat|tay trang|chong nang|niacinamide|bha|salicylic|vitamin c|retinol)\b',
        t,
    )
    if product_request:
        return 'search'
    if re.search(r'\b(tim|kiem|goi y|de xuat|mua|recommend|chon giup|san pham nao|nen dung|phu hop)\b', t):
        return 'search'
    return None


def required_ingredients(message):
    excluded = extract_exclusions(message)
    return [name for name in ('retinol', 'retinal', 'bha', 'aha', 'vitamin c', 'niacinamide', 'ceramide')
            if has_ingredient(message, name) and not any(has_ingredient(name, e) for e in excluded)]


def apply_explicit_request(plan, message, history, current_id=None):
    intent = explicit_intent(message, current_id)
    category = explicit_category(message)
    required = required_ingredients(message)
    # Follow-up references must preserve the user's last search even if a model
    # returns a broad search with a missing/wrong category or active.
    if not category and not required and re.search(r'\b(?:re hon|loai khac|chai khac|loai do|duoi \d)\b', folded(message)):
        for index in range(len(history) - 1, -1, -1):
            turn = history[index]
            if turn.get('role') != 'user':
                continue
            previous = RulePlanner().plan(turn['content'], history[:index])
            if previous.intent in ('search', 'routine') and (previous.category or previous.required_ingredients):
                plan = previous.model_copy(update={'query': previous.query + '. ' + message})
                break
    if intent:
        plan.intent = intent
    if category:
        plan.category = category
    # A new, explicit category/active starts a new product search. Do not inherit
    # retinol, toner, brand or exclusions from assistant prose in a previous turn.
    if intent == 'search' and (category or required):
        plan.query = message
        plan.category = category
        plan.required_ingredients = required
        plan.exclusions = extract_exclusions(message)
        if plan.brand and folded(plan.brand) not in folded(message):
            plan.brand = None
    if intent == 'knowledge':
        plan.query = message
    brand = re.search(r'(?:thương hiệu|thuong hieu)\s+([^,.!?;]+)', message, re.I)
    if brand:
        plan.brand = re.split(r'\s+(?:dưới|duoi|cho|không|khong|tầm|tam)\b', brand.group(1), maxsplit=1, flags=re.I)[0].strip()
    count = re.search(r'\b([1-5])\s+(?:chai|san pham|mon|loai|tuyp)\b', folded(message))
    if count:
        plan.count = int(count.group(1))
    if asks_for_other_person(message):
        plan.subject = 'other'
    return plan


class RulePlanner:
    def plan(self, message, history, current_product_id=None):
        intent = explicit_intent(message, current_product_id) or 'general'
        plan = QueryPlan(intent=intent, query=message, exclusions=extract_exclusions(message))
        t = folded(message)
        for skin, terms in {
            'Da dầu/Hỗn hợp dầu': ('da dau', 'nhon'),
            'Da khô/Hỗn hợp khô': ('da kho', 'kho rap'),
            'Da nhạy cảm': ('nhay cam',), 'Da mụn': ('da mun',),
        }.items():
            if any(term in t for term in terms):
                plan.skin_type = skin
                break
        return apply_explicit_request(plan, message, history, current_product_id)
