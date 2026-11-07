"""Offline checks of the test runner, not executions of the 100 chatbot cases."""
import contextlib
import copy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import run_cases as runner


class RunnerChecks(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.suite = json.loads((runner.ROOT/'cases.json').read_text(encoding='utf-8'))
        cls.by_id = {c['id']:c for c in cls.suite['cases']}

    def test_exactly_100_unique_cases_and_supported_checks(self):
        self.assertEqual(len(runner.validate(self.suite)),100)

    def test_default_never_calls_network(self):
        with patch('sys.argv',['run_cases.py']), patch.object(runner.urllib.request,'urlopen') as network, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(runner.main(),0)
            network.assert_not_called()

    def test_fixture_not_mistaken_for_executed_pass(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)/'results.json'
            with patch('sys.argv',['run_cases.py','--execute','--ids','CHAT-034','--output',str(output)]), patch.object(runner.urllib.request,'urlopen') as network, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.main(),0)
                network.assert_not_called()
            result = json.loads(output.read_text(encoding='utf-8'))['results'][0]
            self.assertEqual(result['status'],'SETUP_REQUIRED')

    def test_unknown_case_id_is_rejected(self):
        with patch('sys.argv',['run_cases.py','--ids','CHAT-101']), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit): runner.main()

    def test_missing_catalog_requires_review(self):
        body={'ok':True,'answer':'Một toner để tham khảo.','intent_mode':'search','products':[{'id':'A','name':'Toner A','price':100000}]}
        failures,pending=runner.evaluate(self.by_id['CHAT-013'],200,body)
        self.assertEqual(failures,[])
        self.assertTrue(any('snapshot missing' in p for p in pending))
        self.assertTrue(all(r in pending for r in self.by_id['CHAT-013']['review']))

    def test_incorrect_catalog_facts_fail(self):
        body={'ok':True,'answer':'Toner A','intent_mode':'search','products':[{'id':'A','name':'Toner A','price':100000}]}
        catalog={'A':{'id':'A','name':'Serum A','price':200000,'category':'Serum / Tinh Chất','inci':'Aqua, Parfum'}}
        failures,_=runner.evaluate(self.by_id['CHAT-013'],200,body,catalog)
        self.assertTrue(any('Name differs' in f for f in failures))
        self.assertTrue(any('Price differs' in f for f in failures))
        self.assertTrue(any('Wrong category' in f for f in failures))
        self.assertTrue(any('contains excluded' in f for f in failures))

    def test_budget_and_total_reject_invalid_prices(self):
        body={'ok':True,'answer':'Các món được chọn','intent_mode':'routine','products':[{'id':'A','name':'A','price':600000}]}
        failures,_=runner.evaluate(self.by_id['CHAT-076'],200,body)
        self.assertIn('Routine exceeds total budget',failures)
        body['products'][0]['price']='NaN'
        failures,_=runner.evaluate(self.by_id['CHAT-076'],200,body)
        self.assertTrue(any('Invalid numeric' in f for f in failures))

    def test_invalid_request_requires_400_not_500(self):
        body={'ok':False,'message':'Payload không hợp lệ'}
        self.assertEqual(runner.evaluate(self.by_id['CHAT-092'],400,body)[0],[])
        self.assertTrue(runner.evaluate(self.by_id['CHAT-092'],500,body)[0])

    def test_negation_checks_and_unverified_inci(self):
        body={'ok':True,'answer':'Serum A','intent_mode':'search','products':[{'id':'A','name':'Serum A','price':100000}]}
        catalog={'A':{'id':'A','name':'Serum A','price':100000,'inci':'Aqua, Retinol, Parfum'}}
        failures,_=runner.evaluate(self.by_id['CHAT-030'],200,body,catalog)
        self.assertTrue(any('contains excluded' in f for f in failures))
        catalog['A']['inci']='Unknown'
        failures,_=runner.evaluate(self.by_id['CHAT-030'],200,body,catalog)
        self.assertTrue(any('No complete INCI' in f for f in failures))

    def test_no_automated_overall_pass(self):
        response=io.BytesIO(json.dumps({'ok':True,'answer':'Xin chào!','products':[],'intent_mode':'general'}).encode())
        response.status=200
        with tempfile.TemporaryDirectory() as directory:
            output=Path(directory)/'results.json'
            with patch('sys.argv',['run_cases.py','--execute','--ids','CHAT-001','--output',str(output)]), patch.object(runner.urllib.request,'urlopen',return_value=response), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(runner.main(),0)
            result=json.loads(output.read_text(encoding='utf-8'))['results'][0]
            self.assertEqual(result['status'],'REVIEW_REQUIRED')
            self.assertTrue(result['pending_review'])


if __name__=='__main__': unittest.main(verbosity=2)
