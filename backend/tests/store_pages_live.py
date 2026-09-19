"""Read-only checks for every support article on the running local website."""
import json
from html import escape
from pathlib import Path
from urllib.request import urlopen


ROOT = Path(__file__).resolve().parents[2]


def main():
    content = json.loads((ROOT / 'backend/app/content/support.json').read_text(encoding='utf8'))
    results = []
    for article in content['articles']:
        failures = []
        try:
            url = 'http://localhost:8080/index.php?r=tro_giup&bai=' + article['slug']
            with urlopen(url, timeout=30) as response:
                html = response.read().decode('utf8')
                if response.status != 200:
                    failures.append('HTTP status')
            if '<h1>' + escape(article['title']) + '</h1>' not in html:
                failures.append('Missing article heading')
            if content['version'] not in html or 'Fatal error' in html:
                failures.append('Version or rendering error')
            for section in article['sections']:
                if '<h2>' + escape(section['title']) + '</h2>' not in html:
                    failures.append('Missing section: ' + section['title'])
            if article['group'] == 'legal' and 'Thông tin pháp lý đang được hoàn thiện' not in html:
                failures.append('Missing legal publication notice')
        except Exception as error:
            failures.append(type(error).__name__ + ': ' + str(error))
        results.append({'slug': article['slug'], 'status': 'FAIL' if failures else 'PASS', 'failures': failures})
    report = {'passed': sum(r['status'] == 'PASS' for r in results), 'total': len(results), 'results': results}
    target = ROOT / 'report/store-assistance/pages-results.json'
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf8')
    print(json.dumps(report, ensure_ascii=False))
    raise SystemExit(0 if report['passed'] == report['total'] else 1)


if __name__ == '__main__':
    main()
