"""Entity-aware text normalization applied only to the text sent to TTS."""
import json
import os
import re

from core.vi_money import number_words

_DICT_PATH = os.path.join(os.path.dirname(__file__), "pronunciation.json")
_LETTERS = dict(zip(
    "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
    ["a", "bê", "xê", "đê", "e", "ép", "gờ", "hát", "i", "di", "ca", "eo", "em",
     "en", "o", "pi", "kiu", "a rờ", "ét", "ti", "u", "vê", "đắp liu", "ích", "quai", "dét"]))
_UNITS = {"ml": "mi-li-lít", "l": "lít", "g": "gam", "mg": "mi-li-gam", "kg": "ki-lô-gam",
          "mm": "mi-li-mét", "cm": "xen-ti-mét", "m": "mét"}


def load_dictionary(path: str = _DICT_PATH) -> dict:
    try:
        with open(path, encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {}


_DICT = load_dictionary()
_DICT_RE = None
if _DICT:
    _keys = sorted(_DICT, key=len, reverse=True)
    _DICT_RE = re.compile(
        r"(?<![\w])(" + "|".join(re.escape(k).replace(r"\ ", r"\s+") for k in _keys) + r")(?![\w])",
        re.IGNORECASE)
    _DICT_LOWER = {re.sub(r"\s+", " ", k).lower(): v for k, v in _DICT.items()}

_SPF = re.compile(r"(?<![\w])SPF\s?(\d+)(\+?)")
_PA = re.compile(r"(?<![\w])PA(\+{1,4})")
_CODE = re.compile(r"(?<![\w])([A-Z]{1,3})(\d+)(?![\w])")
_NUM = r"\d{1,3}(?:\.\d{3})+|\d+(?:[.,]\d+)?"
_NUMBER = re.compile(
    rf"(?<![\w.,])({_NUM})(?:\s?[–—-]\s?({_NUM}))?"
    r"(?:\s?(%|đ|₫|VND|ml|mg|kg|g|l|mm|cm|m)(?![\w]))?")
_PH = re.compile("([-])")


def _num(token: str) -> str:
    if re.fullmatch(r"\d{1,3}(?:\.\d{3})+", token):
        return number_words(int(token.replace(".", "")))
    m = re.fullmatch(r"(\d+)[.,](\d+)", token)
    if m:
        frac = " ".join(number_words(int(d)) for d in m.group(2))
        return f"{number_words(int(m.group(1)))} phẩy {frac}"
    return number_words(int(token))


def _number_sub(m: re.Match) -> str:
    out = _num(m.group(1))
    if m.group(2):
        out += " đến " + _num(m.group(2))
    unit = m.group(3)
    if unit == "%":
        out += " phần trăm"
    elif unit in ("đ", "₫", "VND"):
        out += " đồng"
    elif unit:
        out += " " + _UNITS[unit]
    return out


def normalize_for_tts(text: str) -> str:
    if not text:
        return text
    slots: list[str] = []

    def protect(reading: str) -> str:
        slots.append(reading)
        return f"{chr(0xE100 + len(slots) - 1)}"

    def dict_sub(m):
        return protect(_DICT_LOWER[re.sub(r"\s+", " ", m.group(1)).lower()])

    def spf_sub(m):
        return protect("ét pi ép " + number_words(int(m.group(1))) + (" cộng" if m.group(2) else ""))

    def code_sub(m):
        letters = " ".join(_LETTERS[c] for c in m.group(1))
        return protect(f"{letters} {number_words(int(m.group(2)))}")

    if _DICT_RE:
        text = _DICT_RE.sub(dict_sub, text)
    text = _SPF.sub(spf_sub, text)
    text = _PA.sub(lambda m: protect("pi ây " + number_words(len(m.group(1))) + " cộng"), text)
    text = _CODE.sub(code_sub, text)
    text = _NUMBER.sub(_number_sub, text)
    return _PH.sub(lambda m: slots[ord(m.group(1)) - 0xE100], text)
