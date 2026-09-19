import datetime as dt
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import AUDIO_PIPELINE_VERSION
from core.campaigns import create_campaign
from core.pipeline import run_ai_idol_pipeline
from engines.syna_engine import build_manifest, ingredient_titles, render_syna_video


class SynaTests(unittest.TestCase):
    def test_ingredients_are_source_labels_not_invented_benefits(self):
        raw = '• 4% Niacinamide: giúp làm sáng da.\\r\\n• 0.15% Retinol nguyên chất: giúp tái tạo.'
        self.assertEqual(ingredient_titles(raw), ['4% Niacinamide', '0.15% Retinol nguyên chất'])
        self.assertEqual(ingredient_titles('UNKNOWN'), [])
        self.assertEqual(ingredient_titles(None), [])
        self.assertEqual(ingredient_titles(['A', 'B', 'C', 'D']), ['A', 'B', 'C'])

    def test_manifest_uses_actual_product_price_and_measured_timing(self):
        snapshot = {'name': 'Vichy', 'ingredients': '• 4% Niacinamide: thông tin', 'price': 1126000}
        timing = [{'start': 16, 'end': 22, 'text': 'Niacinamide là một thành phần của sản phẩm.'}]
        m = build_manifest(snapshot, timing[0]['text'], 23, timing, {'format': '16:9'})
        self.assertEqual(m['product'], {'name': 'Vichy', 'price': 1126000})
        self.assertEqual(m['cues'][0]['start'], 16)
        self.assertEqual(m['cues'][0]['ingredient'], 0)
        self.assertNotIn('Centella', str(m))

    def test_invalid_format_duration_and_missing_timing_fail(self):
        for duration, timing, config in [(181, [{}], {}), (5, [], {}), (5, [{}], {'format': '9:16'})]:
            with self.assertRaises(ValueError):
                build_manifest({}, '', duration, timing, config)

    def test_campaign_rejects_vertical_syna_before_database_write(self):
        with patch('core.campaigns.get_db') as db:
            with self.assertRaisesRegex(ValueError, '16:9'):
                create_campaign({'product_ids': ['995'], 'scheduled_at': (dt.datetime.now(dt.timezone.utc) + dt.timedelta(days=1)).isoformat(),
                                 'configuration': {'avatar_mode': 'syna_3d', 'format': '9:16'}})
            db.assert_not_called()

    def test_syna_keeps_employee_approval_and_bypasses_human_face_renderer(self):
        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / 'audio.wav'
            audio.touch()
            job = {'product_id': '995', 'content_mode': 'Product Intro', 'configuration': {'avatar_mode': 'syna_3d', 'format': '16:9'},
                   'product_snapshot': {'name': 'Vichy'}, 'script_status': 'PENDING_APPROVAL',
                   'artifacts': {'knowledge': 'known', 'script_text': 'Lời đọc do nhân viên đã xem.',
                                 'audio_path': str(audio), 'audio_pipeline_version': AUDIO_PIPELINE_VERSION,
                                 'final_video_path': '/old/photo.mp4'}}
            db = MagicMock()
            db.ai_idol_videos.find_one.return_value = {'_id': 'existing-video'}
            with patch('core.pipeline.get_job', return_value=job), patch('core.pipeline.get_db', return_value=db), \
                 patch('core.pipeline.update_job_stage'), patch('core.pipeline.checkpoint_job'), \
                 patch('engines.syna_engine.render_syna_video', return_value='/native/syna.mp4') as syna, \
                 patch('engines.avatar_engine.generate_avatar_video') as avatar, \
                 patch('engines.video_composer.compose_final_video') as compose, \
                 patch('engines.video_composer.get_audio_duration', return_value=20), \
                 patch('engines.voice_engine.generate_voice_audio') as voice:
                self.assertFalse(run_ai_idol_pipeline('64b64b64b64b64b64b64b64b'))
                syna.assert_not_called(); avatar.assert_not_called(); voice.assert_not_called()
                job['script_status'] = 'APPROVED'
                self.assertTrue(run_ai_idol_pipeline('64b64b64b64b64b64b64b64b'))
                syna.assert_called_once(); avatar.assert_not_called(); compose.assert_not_called()
                self.assertEqual(db.ai_idol_jobs.update_one.call_args.args[1]['$set']['final_video_path'], '/native/syna.mp4')

    def test_legacy_avatar_still_composes(self):
        job = {'product_id': '995', 'content_mode': 'Product Intro', 'configuration': {'avatar_mode': 'photo'},
               'product_snapshot': {'name': 'Vichy'}, 'script_status': 'APPROVED',
               'artifacts': {'knowledge': 'known', 'script_text': 'Kịch bản.', 'audio_pipeline_version': 'old'}}
        db = MagicMock(); db.ai_idol_videos.find_one.return_value = {'_id': 'video'}
        with patch('core.pipeline.get_job', return_value=job), patch('core.pipeline.get_db', return_value=db), \
             patch('core.pipeline.update_job_stage'), patch('core.pipeline.checkpoint_job'), \
             patch('engines.voice_engine.generate_voice_audio', return_value='/voice.wav'), \
             patch('engines.avatar_engine.generate_avatar_video', return_value='/avatar.mp4') as avatar, \
             patch('engines.video_composer.compose_final_video', return_value='/final.mp4') as compose, \
             patch('engines.video_composer.get_audio_duration', return_value=20), \
             patch('engines.syna_engine.render_syna_video') as syna:
            self.assertTrue(run_ai_idol_pipeline('64b64b64b64b64b64b64b64b'))
            avatar.assert_called_once(); compose.assert_called_once(); syna.assert_not_called()

    def test_no_network_for_invalid_job_identifier(self):
        with patch('engines.syna_engine.requests.post') as post:
            with self.assertRaises(ValueError): render_syna_video('../escape', '', '', {}, {})
            post.assert_not_called()


if __name__ == '__main__':
    unittest.main()
