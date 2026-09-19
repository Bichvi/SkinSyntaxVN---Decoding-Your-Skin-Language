"""Conservative consultation boundaries, separate from catalog availability.

Source reviewed 2026-09-09: AAD, Retinoid or retinol? AAD advises avoiding
retinoids with substantial dryness/redness and discussing sensitive skin with
a dermatologist. This rule does not diagnose the cause of a reported symptom.
"""
import re
from .request_rules import folded

RETINOL_SOURCE = 'https://www.aad.org/public/everyday-care/skin-care-secrets/anti-aging/retinoid-retinol'


def reported_irritation(message, profile):
    symptoms = ('bong troc', 'kho cang', 'do rat', 'nong rat')
    current = folded(message)
    previous = folded(' '.join(profile.get('concerns', [])))
    for symptom in symptoms:
        if re.search(r'(?:khong con|da het|het)\s+(?:bi\s+)?' + symptom, current):
            current = re.sub(r'(?:khong con|da het|het)\s+(?:bi\s+)?' + symptom, '', current)
            previous = previous.replace(symptom, '')
    return any(s in current or s in previous for s in symptoms)


def retinol_caution():
    return ('Nếu tình trạng khô căng, bong tróc hoặc đỏ rát bạn đã nêu vẫn còn, mình chưa khuyên '
            'bắt đầu retinol ngay. Retinoid có thể làm da khô và kích ứng thêm; nên trao đổi với '
            'bác sĩ da liễu trước khi bắt đầu nếu da đang nhạy cảm. '
            f'[Hướng dẫn của AAD]({RETINOL_SOURCE})')
