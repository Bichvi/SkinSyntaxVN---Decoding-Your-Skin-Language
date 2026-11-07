import os
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from core.tts_normalize import normalize_for_tts as n


class TtsNormalizeTests(unittest.TestCase):
    def test_dictionary(self):
        for src, out in {
            "Mexoryl XS": "Mê-xô-rin ích ét", "La Roche-Posay": "La Rốt Pô-dê",
            "CeraVe": "Xê-ra-vi", "Torriden": "To-ri-đen", "Niacinamide": "Nai-a-xin-a-mai",
            "Hyaluronic Acid": "Hai-a-lu-rô-níc a-xít", "Avobenzone": "A-vô-ben-dôn",
            "Airlicium": "E-li-xi-um", "UVA": "u vê a", "UVB": "u vê bê",
            "TiO2": "ti-tan đi-ô-xít", "B5": "bê năm", "BHA": "bê hát a",
        }.items():
            self.assertEqual(n(f"Dùng {src} nhé"), f"Dùng {out} nhé")

    def test_spf_pa(self):
        self.assertEqual(n("SPF 50+"), "ét pi ép năm mươi cộng")
        self.assertEqual(n("SPF30"), "ét pi ép ba mươi")
        self.assertEqual(n("PA++++"), "pi ây bốn cộng")

    def test_uvinul(self):
        out = n("Uvinul T150")
        self.assertEqual(out, "U-vi-nun T một trăm năm mươi")
        self.assertNotIn("một năm", out)

    def test_numbers(self):
        self.assertEqual(n("15"), "mười lăm")
        self.assertNotIn("mười năm", n("15"))
        self.assertEqual(n("833.000đ"), "tám trăm ba mươi ba nghìn đồng")
        self.assertEqual(n("50 ml"), "năm mươi mi-li-lít")
        self.assertEqual(n("15–20 phút"), "mười lăm đến hai mươi phút")
        self.assertEqual(n("12%"), "mười hai phần trăm")
        self.assertEqual(n("21"), "hai mươi mốt")
        self.assertEqual(n("105"), "một trăm linh năm")
        self.assertEqual(n("1.500.000đ"), "một triệu năm trăm nghìn đồng")

    def test_generic_code_and_unchanged(self):
        self.assertEqual(n("T150"), "ti một trăm năm mươi")
        s = "Xin chào các bạn, hôm nay mình review kem chống nắng."
        self.assertEqual(n(s), s)
        self.assertEqual(n(""), "")

    def test_timings_keep_original_text(self):
        import json
        import tempfile
        import engines.voice_engine as ve
        sent = []

        class FakeTTS:
            def generate(self, text, path):
                sent.append(text)
                open(path, "wb").close()
                return True

        tmp = tempfile.mkdtemp()
        out = os.path.join(tmp, "voice_j.wav")
        original = "Kem SPF 50+ giá 833.000đ"
        with patch.object(ve, "TTSProvider", FakeTTS), patch.object(ve, "AUDIO_DIR", tmp), \
                patch.object(ve, "_audio_duration", return_value=1.0), \
                patch.object(ve, "section_pauses", return_value=[]), \
                patch.object(ve, "split_labeled_sections", return_value=[("Hook", original)]), \
                patch.object(ve.subprocess, "run") as run:
            run.return_value.returncode = 0
            with patch.object(ve.os.path, "exists", return_value=True), \
                    patch.object(ve.os, "remove"):
                try:
                    ve.generate_voice_audio("j", original, {})
                except Exception:
                    pass
        self.assertEqual(sent, ["Kem ét pi ép năm mươi cộng giá tám trăm ba mươi ba nghìn đồng"])
        tpath = out + ".timings.json"
        if os.path.exists(tpath):
            self.assertEqual(json.load(open(tpath, encoding="utf-8"))[0]["text"], original)


if __name__ == "__main__":
    unittest.main()
