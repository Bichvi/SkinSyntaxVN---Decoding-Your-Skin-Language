from core.script_text import split_labeled_sections
from engines.syna_persona import PERSONA_RULES_VI, ROBOTIC_WORDS, SHOP_FACTS_VI


def test_new_product_clip_labels_are_split():
    script = "[Lead-in]\nA\n[Pain Point]\nB\n[Why]\nC\n[Solution]\nD\n[Feel]\nE\n[How to Use]\nF\n[Price]\nG\n[CTA]\nH"
    assert [label for label, _ in split_labeled_sections(script)] == [
        "lead_in", "pain_point", "why", "solution", "feel", "how_to_use", "price", "cta"]


def test_legacy_labels_unchanged():
    assert split_labeled_sections("[Opening]\nx\n[Key Benefits]\ny")[1][0] == "key_benefits"


def test_persona_mentions_no_skin_joke_and_bans_fake_promos():
    assert "không có da" in PERSONA_RULES_VI
    assert "flash sale" in PERSONA_RULES_VI.lower()


def test_robotic_words():
    assert ROBOTIC_WORDS.search("theo dữ liệu của Syna")
    assert not ROBOTIC_WORDS.search("Syna thuộc làu bảng thành phần")


def test_shop_facts_exact():
    assert SHOP_FACTS_VI == (
        "sản phẩm chính hãng", "giao hàng toàn quốc", "hỗ trợ tận tâm",
        "cá nhân hóa routine theo làn da", "chat với Syna trên web để được tư vấn")


def test_lead_in_variants():
    assert split_labeled_sections("[Lead in]\nx")[0][0] == "lead_in"
    assert split_labeled_sections("[Leadin]\nx")[0][0] == "lead_in"
