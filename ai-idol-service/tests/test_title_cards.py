import os
import sys
import tempfile
import unittest
import wave
from pathlib import Path

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from engines.audio_mixer import build_mix_command
from engines.title_cards import CROSSFADE, build_card_graph, card_inputs, format_price, wrap_text
from engines.voice_engine import _write_silence, section_pauses

CARDS = {
    "width": 1280, "height": 720, "first": "/w/first.png", "last": "/w/last.png",
    "product": "/w/product.jpg", "name": "/w/name.txt", "price": "/w/price.txt", "cta": "/w/cta.txt",
}


class CardTextTests(unittest.TestCase):
    def test_price_format(self):
        self.assertEqual(format_price(289000), "289.000đ")
        self.assertEqual(format_price("1250000.0"), "1.250.000đ")
        self.assertEqual(format_price(0), "")
        self.assertEqual(format_price("không rõ"), "")

    def test_wrap_limits_lines_and_marks_truncation(self):
        self.assertEqual(wrap_text("Kem dưỡng ẩm", 30), "Kem dưỡng ẩm")
        wrapped = wrap_text("Serum " * 30, 20)
        self.assertEqual(len(wrapped.split("\n")), 2)
        self.assertTrue(wrapped.endswith("…"))


class CardGraphTests(unittest.TestCase):
    def test_main_video_enters_exactly_when_speech_starts(self):
        graph = build_card_graph(CARDS, 2.5, 2.2, 20.0)
        self.assertIn("xfade=transition=fade:duration=0.4:offset=2.500[im]", graph)
        self.assertIn(f"offset={2.5 + 20.0 - CROSSFADE:.3f}[vout]", graph)
        self.assertIn("textfile=/w/price.txt", graph)
        self.assertIn("boxcolor=0xE91E63", graph)
        self.assertIn("[5:v]format=rgba", graph)

    def test_cards_without_product_or_price(self):
        cards = dict(CARDS, product="", price="")
        graph = build_card_graph(cards, 2.0, 2.0, 10.0)
        self.assertNotIn("[5:v]", graph)
        self.assertNotIn("price.txt", graph)
        self.assertEqual(card_inputs(cards, 2.0, 2.0).count("-i"), 2)

    def test_card_inputs_are_long_enough_for_crossfade(self):
        args = card_inputs(CARDS, 2.5, 2.0)
        self.assertEqual(args.count("-i"), 3)
        self.assertEqual(args[args.index("/w/first.png") - 2], f"{2.5 + CROSSFADE:.3f}")
        self.assertEqual(args[args.index("/w/last.png") - 2], f"{2.0 + CROSSFADE:.3f}")

    def test_mix_command_uses_cards_instead_of_frozen_frames(self):
        cmd = build_mix_command("v.mp4", "voice.wav", "m.mp3", "o.mp4", 20.0, 2.5, 2.2,
                                cards=CARDS, main_seconds=20.0)
        graph = cmd[cmd.index("-filter_complex") + 1]
        self.assertIn("xfade", graph)
        self.assertNotIn("tpad", graph)
        output_limit = len(cmd) - 1 - cmd[::-1].index("-t")  # card inputs also use -t
        self.assertEqual(cmd[output_limit + 1], "24.700")


class PauseTests(unittest.TestCase):
    def test_pauses_vary_within_range_and_are_stable(self):
        pauses = section_pauses("job-1", 6, (0.35, 0.8))
        self.assertEqual(len(pauses), 5)
        self.assertTrue(all(0.35 <= pause <= 0.8 for pause in pauses))
        self.assertGreater(len(set(pauses)), 1)
        self.assertEqual(pauses, section_pauses("job-1", 6, (0.35, 0.8)))
        self.assertEqual(section_pauses("job-1", 1), [])

    def test_silence_matches_tts_format(self):
        with tempfile.TemporaryDirectory() as folder:
            path = str(Path(folder) / "pause.wav")
            _write_silence(path, 0.5)
            with wave.open(path, "rb") as handle:
                self.assertEqual((handle.getnchannels(), handle.getsampwidth(), handle.getframerate()), (1, 2, 44100))
                self.assertEqual(handle.getnframes(), 22050)


if __name__ == "__main__":
    unittest.main()
