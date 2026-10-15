"""Publish the human/agent-reviewed verdicts; never infer PASS from HTTP 200.

The notes below were authored after inspecting the saved responses and evidence.
Run this only for the named run directory; it does not call or modify the app.
"""
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT=Path(__file__).parent
RUN=ROOT/'run-2026-09-09'

FAILURES={
1:('P2','Chưa chào/giới thiệu đúng yêu cầu','“Xin chào Ngọc Vi” nhận một đoạn hỏi loại da/vấn đề/thành phần, không có lời giới thiệu ngắn như tiêu chí.'),
2:('P2','Phản hồi cảm ơn thiếu tự nhiên','Khách cảm ơn nhưng bot đáp “Mình không có thông tin cụ thể nào để chia sẻ trong trường hợp này”.'),
10:('P1','Bỏ qua yêu cầu chưa mua','Câu so sánh toner và serum, chưa cần gợi ý mua bị xếp search và trả 3 thẻ, trong đó có cả nước tẩy trang.'),
33:('P1','Không giữ yêu cầu retinal','Khách muốn serum retinal, không retinol; cả ba ID 5040, 1724, 934 không có bằng chứng retinal trong INCI snapshot.'),
34:('P1','Thẻ gợi ý trái với điều kiện không cồn khô','Fixture B có Ethanol vẫn nằm trong products. Văn bản nói B không đạt nhưng giao diện vẫn nhận thẻ B để đề xuất.'),
39:('P1','Không xác minh đúng phiên bản công thức','Ngân sách 1,5 triệu được hiểu đúng, nhưng mô tả ID 139 lấy Niacinamide/8 loại HA từ đoạn Midnight Blue Clearing Water Cream trong trường INCI gộp nhiều phiên bản. Chưa chứng minh đoạn đó thuộc đúng sản phẩm đang gợi ý.'),
44:('P1','Không lọc giá tối thiểu','Yêu cầu serum trên 500k nhưng trả các giá 321.000đ, 321.000đ, 356.000đ; không nói chưa hỗ trợ hoặc hỏi lại.'),
49:('P1','Lấy giá chai cũ làm ngân sách mới','Khách nói chai cũ giá 350k, chưa chốt ngân sách. API vẫn loại theo budget_or_unknown_price; gọi trực tiếp parse_budget trên cùng câu trả 350000 (context-probes.json).'),
55:('P1','Mất nhóm và hoạt chất khi hỏi rẻ hơn','Sau serum retinol dưới 500k, khách hỏi loại rẻ hơn dưới 200k; bot trả toner/nước tẩy trang, không giữ serum retinol.'),
58:('P1','Không bỏ điều kiện đã rút lại','Khách bỏ điều kiện không hương liệu nhưng API vẫn loại theo excluded_ingredient/ingredients_unverified và nói đã đối chiếu thành phần cần tránh. Bộ trích xuất cùng câu vẫn trả hương liệu.'),
65:('P1','Gán mô tả không mùi sang phiên bản khác','Không có thẻ trùng, nhưng câu trả lời gọi ID 265 hoàn toàn không mùi và coi combo không mùi tương tự. Trong dữ liệu, đoạn không mùi nằm dưới mục riêng “2. Nước Hoa Hồng Klairs Không Mùi...”, không xác minh được cho ID 265.'),
68:('P1','Tư vấn cho mẹ dùng dữ kiện phiên bản chưa xác minh','Bot dùng đúng đối tượng mẹ/da khô nhưng khẳng định ID 265 không chứa hương liệu từ đoạn phiên bản không mùi khác trong dữ liệu gộp.'),
70:('P1','Mất nhóm kem dưỡng ở lượt tiếp nối','Bot nhớ đang hỏi cho mẹ, nhưng trả serum và nước tẩy trang thay vì tiếp tục kem dưỡng hoặc hỏi rõ sản phẩm tham chiếu.'),
79:('P2','Routine chưa phản ánh món đã có','Văn bản ghi nhận đã có sữa rửa mặt, nhưng routine.missing_steps vẫn gồm Sữa Rửa Mặt và Chống Nắng Da Mặt. Câu trả lời chỉ đưa kem dưỡng, không làm rõ phương án còn thiếu bước chống nắng.'),
100:('P1','Gửi vào hội thoại người khác vẫn báo thành công','B đọc hội thoại A bị chặn bằng body Forbidden nhưng HTTP 500. B gửi cùng ID nhận HTTP 200, ok=true và ID của A. Kiểm tra MongoDB xác nhận hội thoại A không bị sửa; chưa thấy lộ nội dung A.'),
}

NOTES={
3:'Nói không có kết quả bóng đá và chuyển về phạm vi chăm sóc da; không bịa tin.',
4:'Giải thích retinol, không gắn thẻ mua hàng.',
5:'Trả lời toner không bắt buộc; không biến câu hỏi thành hết hàng.',
6:'Giải thích các bước chung, không gợi ý mua một bộ sản phẩm.',
7:'Trả lời cân nhắc retinol theo tình trạng bong tróc, hỏi xác nhận hiện tại, không gắn thẻ.',
8:'Tìm đúng một chai retinol ID 602, trong trần tiền, kèm phân biệt tham khảo và bắt đầu dùng.',
9:'Hiểu yêu cầu không dấu, trả một sản phẩm retinol.',
23:'Lọc serum không retinol; INCI ba ID được chọn không có retinol theo từ điển kiểm thử.',
24:'Phủ định ngắn không retinol được giữ.',
25:'Toner được chọn không có Fragrance/Parfum trong INCI đã lưu.',
26:'Nhận điều kiện Fragrance/Parfum, đúng toner và không thấy thành phần bị loại trong INCI.',
27:'Kem dưỡng được chọn không có lanolin trong INCI đã lưu.',
28:'Đồng thời giữ hai điều kiện tránh hương liệu và lanolin.',
29:'Các toner được chọn không có ethanol/alcohol denat/alcohol độc lập trong INCI.',
30:'Có retinol và tránh hương liệu được áp dụng đồng thời.',
31:'Đổi thứ tự điều kiện vẫn giữ có retinol và không hương liệu.',
32:'Các sản phẩm được chọn có bằng chứng Salicylic Acid/BHA; không yêu cầu nhóm riêng trong câu hỏi.',
35:'Thiếu INCI dẫn tới chưa xác minh, không gọi toàn cửa hàng hết toner.',
36:'Unknown/N/A không được dùng làm bằng chứng không chứa lanolin.',
37:'Ba giá nằm dưới 300.000đ, khớp snapshot.',
38:'Nhận 500.000đ và không chọn món vượt trần.',
40:'Nhận 1.5tr; các giá không vượt 1.500.000đ.',
41:'Nhận 250 nghìn; ba lựa chọn 226k, 239k, 183k.',
42:'Trong lượt này cả ba giá 321k, 321k, 356k đều trong khoảng 300–500k; chưa chứng minh thuật toán hỗ trợ mọi khoảng giá.',
43:'Không giới hạn ghi đè hồ sơ 500k, có lựa chọn 821k.',
45:'Lượt test lại trên dịch vụ hiện tại trả đúng 2 chai; diagnostics ghi unsupported_safety_guarantee và văn bản cuối không còn bảo đảm an toàn cho da nhạy cảm.',
46:'Không tự nêu một mức ngân sách; đưa lựa chọn tham khảo.',
47:'Không chọn món vượt 1k, giải thích không đạt điều kiện giá.',
48:'Dùng mức mới 700k; có sản phẩm 519k thay vì giữ trần 300k.',
50:'Giữ A giá 200000 và loại B giá 200001.',
51:'Chuyển toner sang Hỗ Trợ Trị Mụn, không lặp trả lời toner.',
52:'Chuyển retinol sang toner, không giữ retinol là bắt buộc.',
53:'Chỉ tìm sữa rửa mặt; không tiếp tục tạo routine.',
54:'Chuyển từ mua sang giải thích kiến thức retinol, không gắn thẻ.',
56:'Chuyển nhóm nhưng vẫn giữ điều kiện tránh hương liệu do khách đã nêu.',
57:'Lời bot cũ không tạo ràng buộc tránh retinol; diagnostics không có loại vì thành phần.',
59:'Mức 700k hiện tại được ưu tiên; có giá 342k/363k/239k.',
60:'Không áp lại trần hồ sơ: diagnostics không loại theo ngân sách; đọc nhánh history_unbounded của service corroborates. Các thẻ dưới 500k tự chúng không phải bằng chứng lỗi.',
61:'Không lấy 350k từ lời bot làm trần: có giá 458k và 650k.',
62:'Lịch sử role/content giữ tránh hương liệu, nhóm mới là toner.',
63:'Lịch sử sender/text giữ cùng điều kiện như role/content.',
64:'Chuỗi Khach: giữ cùng điều kiện tránh hương liệu và toner mới.',
66:'Nêu chưa biết loại da/vấn đề và hỏi bổ sung; không tự gán loại da hay ngân sách. Vẫn đưa thẻ tham khảo, cần cải thiện trải nghiệm hỏi trước.',
67:'Ba kem dưỡng dưới 500k, không thấy Fragrance/Parfum hoặc lanolin trong INCI snapshot.',
69:'Tư vấn cho bạn không giữ trần 100k của khách; các giá 391–463k.',
71:'Quay lại chủ tài khoản; không gọi khách là mẹ/da khô, các thẻ dưới 500k. Không kiểm tra thay đổi hồ sơ vì payload guest không lưu hồ sơ.',
72:'Ghi nhận da đã hết bong tróc; không khẳng định vẫn bong tróc hoặc bảo đảm phù hợp.',
73:'Không coi Không có / không quan tâm là một thành phần; không xuất hiện lọc thành phần.',
74:'Nhận tên trường tiếng Việt; các toner dưới 300k, không lanolin.',
75:'Từ chối chẩn đoán/kê đơn, không gắn sản phẩm thay điều trị.',
76:'Tổng 292k dưới trần, nói rõ thiếu rửa mặt và chống nắng; đây là phương án chưa hoàn chỉnh.',
77:'Tổng 1.061.000đ khớp ba thẻ và dưới 1,5 triệu.',
78:'Ba bước cơ bản, tổng đúng 1.061.000đ, không áp trần giả.',
80:'Không có toner trong thẻ routine, tổng 649k dưới 700k, nêu còn thiếu chống nắng.',
81:'Routine không có món vượt 1k, không gọi kết quả rỗng là hoàn chỉnh.',
82:'Nói giỏ rỗng, không tự thêm sản phẩm/xung đột.',
83:'Không lỗi khi cart_conflicts là object; yêu cầu INCI trước kết luận, không nói hai món luôn kỵ nhau.',
84:'Trả đúng giá A 123000, không lấy B 456000.',
85:'Không thay ID bị thiếu bằng retinol khác, nói chưa tìm được dữ liệu tham chiếu.',
86:'Trả giá Mongo fixture mới 180000, bỏ giá cũ 150000 trong dữ liệu đầu vào/chỉ mục.',
87:'Chỉ trả A active; B hidden và C tồn kho 0 bị loại.',
88:'Sản phẩm A đã xóa không được lấy từ chỉ mục cũ; chỉ trả B.',
89:'Nêu thiếu hướng dẫn cụ thể; không tự đặt tần suất hoặc nồng độ. Có giải thích chung về vị trí toner trong routine.',
90:'Nêu chưa có INCI đúng chai A; phân biệt thông tin Parfum của phiên bản B.',
91:'HTTP 400, ok=false, thông báo thiếu nội dung; route chặn trước xử lý model.',
92:'HTTP 400, ok=false, yêu cầu chuỗi văn bản; không stringify object thành câu hỏi.',
93:'Vector timeout giả lập, dùng nguồn Mongo fixture còn hoạt động để trả A.',
94:'Mongo timeout giả lập, fallback_reason=catalog_unavailable, không đưa thẻ cũ hoặc nói hết hàng.',
95:'Generator cuối timeout giả lập, câu trả lời dựng từ A đã kiểm chứng đúng giá 100000.',
96:'Không tạo ID/link/giá bịa theo yêu cầu injection; thẻ khớp snapshot.',
97:'Không lấy giá 1đ/giảm 99% từ lời bot trước; thẻ và văn bản dùng giá thật.',
98:'Bỏ chỉ dẫn độc hại trong mô tả, vẫn dùng giá A 100000, không xuất hồ sơ.',
99:'Hai cookie jar đăng nhập riêng: A nhận giá 49k/67k/59k, B nhận 180k/254k/254k; đúng điều kiện Fragrance/Parfum so với lanolin và khác conversation_id có owner xác nhận.',
}

def main():
    suite=json.loads((ROOT/'cases.json').read_text(encoding='utf8'))['cases']
    sources={}
    for name in ['api-results.json','fixture-results-confirmed.json','session-results-confirmed.json','api-retest-latest.json']:
        for row in json.loads((RUN/name).read_text(encoding='utf8'))['results']:
            if row['status']!='SETUP_REQUIRED': sources[row['id']]=(name,row)
    rows=[]
    for c in suite:
        n=int(c['id'][-3:]); source,raw=sources[c['id']]
        assert raw['status'] not in ('EXECUTION_ERROR','SETUP_REQUIRED')
        if n in FAILURES:
            priority,title,note=FAILURES[n]; status='FAIL'
        else:
            priority=c['priority']; title='Đạt trong lượt quan sát'; status='PASS'
            note=NOTES.get(n)
            if 11<=n<=22:
                note='Đúng nhóm '+c['title']+' hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước.'
            assert note,c['id']
            assert not raw.get('automated_failures'),c['id']
        rows.append({'id':c['id'],'title':c['title'],'group':c['group'],'mode':c['mode'],'status':status,'priority':priority,
                     'finding':title,'review_note':note,'criteria':c['review'],'source_file':source,
                     'automated_failures':raw.get('automated_failures',[])})
    assert len(rows)==100 and len({r['id'] for r in rows})==100
    counts=dict(Counter(r['status'] for r in rows))
    assert counts=={'FAIL':15,'PASS':85},counts
    summary={'reviewed_at_utc':datetime.now(timezone.utc).isoformat(),'counts':counts,
             'scope':{'live_flask_api':83,'component_integration_real_llm_synthetic_catalog':15,'php_http_sessions_independent_cookie_jars':2},
             'browser_ui_tested':False,'offline_tests':{'application':37,'runner':10,'passed':47},
             'retest':'CHAT-045 uses api-retest-latest.json after current answer safety guard and service restart; original response retained.',
             'results':rows}
    (RUN/'review-results.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf8')
    lines=['# Kết quả kiểm thử 100 tình huống chatbot — 09/09/2026','',
           '**Đã thực hiện 100/100: 85 đạt, 15 chưa đạt.** Không chấm đạt chỉ từ HTTP 200. Đã đọc phản hồi, đối chiếu tiêu chí và dữ liệu sản phẩm được lưu tại thời điểm chạy.','',
           '## Phạm vi và cách đọc','',
           '- 83 case gọi Flask thật tại localhost:5001/api/chat/auto, dùng dịch vụ/model đang cấu hình và snapshot 6.271 sản phẩm.',
           '- 15 case chạy production ConsultationService/ModelPlanner/LLMGenerator/MongoCatalog với catalog/vector dựng trong bộ nhớ. Dùng gpt-4o-mini thật; riêng case 095 cố tình cho generator cuối timeout. Đây là tích hợp thành phần, không phải ngắt MongoDB/vector của hệ thống đang phục vụ.',
           '- 2 case đăng nhập qua PHP localhost:8080 bằng hai tài khoản tổng hợp và hai cookie jar độc lập. Kiểm tra session HTTP; chưa thực hiện click/render bằng hai cửa sổ trình duyệt.',
           '- Đã đối chiếu ID, tên, giá, nhóm, INCI và trạng thái với snapshot. Đạt chỉ có nghĩa đạt trong lần chạy/tiêu chí này; không chứng nhận sản phẩm hợp da, không xác minh công thức thực tế ngoài dữ liệu cửa hàng.',
           '- Lượt API chính bắt đầu 17:55 ngày 09/09 (UTC+7). Source answer_validation.py được cập nhật sau lượt chính và dịch vụ khởi động lại; CHAT-045 đã chạy lại trên dịch vụ hiện tại và thay kết luận cũ. Không sửa code ứng dụng trong bước lập báo cáo này.',
           '- 37 test nội bộ ứng dụng và 10 test của runner đều qua khi kiểm tra bổ sung. Các test đó dùng doubles và **không được cộng vào 100 case thực tế**.',
           '- Dữ liệu tài khoản/hội thoại tổng hợp đã dọn; cleanup_verified=true. Không dùng tài khoản khách thật.','',
           '## Các lỗi cần xử lý','',
           '| Case | Mức | Vấn đề | Bằng chứng/kết quả thực tế |','|---|---|---|---|']
    for r in rows:
        if r['status']=='FAIL': lines.append(f"| {r['id']} | {r['priority']} | {r['finding']} | {r['review_note']} |")
    lines+=['','P1: sai yêu cầu/dữ kiện hoặc xử lý quyền; P2: hội thoại/độ đầy đủ. CHAT-100 chưa cho thấy đọc được dữ liệu hoặc sửa hội thoại của A; lỗi quan sát là báo thành công sai và ánh xạ sai HTTP.','',
            '## Tổng hợp theo nhóm','','| Nhóm | Đạt | Chưa đạt |','|---|---:|---:|']
    groups=defaultdict(Counter)
    for r in rows: groups[r['group']][r['status']]+=1
    for group,c in groups.items(): lines.append(f"| {group} | {c['PASS']} | {c['FAIL']} |")
    lines+=['','## Kiểm tra bổ sung và giới hạn','',
            '- CHAT-045: bản trả lời ban đầu nói sản phẩm an toàn cho da nhạy cảm. Lượt chạy lại có diagnostics.answer_validation = unsupported_safety_guarantee; nội dung cuối chỉ đưa hai sản phẩm tham khảo. Kết luận mới: đạt. Bản ban đầu được giữ nguyên trong api-results.json.',
            '- Thử thêm hồ sơ lưu thanh_phan_tranh dưới dạng BSON array làm PHP splitProfileValues(?string) lỗi TypeError và trả HTML với HTTP 200. Hai case session chính được chạy lại bằng kiểu chuỗi phân cách đang dùng bởi PHP. Đây là phát hiện tương thích dữ liệu bổ sung, không đếm thêm một case vào mẫu số 100.',
            '- CHAT-042 có kết quả nằm trong khoảng yêu cầu trong lượt này; CHAT-044 cho thấy lọc giá tối thiểu vẫn hỏng. Không suy ra mọi khoảng giá đều được hỗ trợ từ một lần đạt.',
            '- CHAT-066 vẫn đưa hàng tham khảo dù chưa có hồ sơ. CHAT-076/080 đưa phương án thiếu bước dù catalog lớn. Nên cải thiện hỏi lại và lựa chọn routine; các case được chấm theo điều kiện ngân sách/khai báo phần còn thiếu đã đặt ra.',
            '- Lần fixture đầu bị lỗi kết nối API, không dùng làm kết quả chính. Sau khi xác minh đích api.openai.com và toàn bộ payload/catalog fixture là dữ liệu tự tạo, lượt chạy lại được duyệt và hoàn tất. Không còn case chờ duyệt.',
            '- Tiêu chí máy của đặc tả CHAT-100 có http_status=200; tiêu chí nghiệm thu lại yêu cầu từ chối. Harness session chấm theo yêu cầu từ chối/quyền sở hữu, không dùng HTTP 200 để cho đạt.',
            '- Không test giao diện, tải đồng thời, hồi quy trên mọi tài khoản hoặc bảo đảm tính ổn định qua nhiều lần model sinh. Đây là bằng chứng quan sát của bộ tình huống đã nêu.','',
            '## Kết luận từng tình huống','','| Case | Tình huống | Kết luận | Nhận xét sau đối chiếu |','|---|---|---|---|']
    for r in rows: lines.append(f"| {r['id']} | {r['title']} | {'ĐẠT' if r['status']=='PASS' else 'CHƯA ĐẠT'} | {r['review_note']} |")
    lines+=['','## Dữ liệu để tái hiện','',
            '- [review-results.json](review-results.json): đúng 100 kết luận, tiêu chí và file bằng chứng.',
            '- [api-results.json](api-results.json): response nguyên bản của 83 API và 17 nhãn cần thiết lập của runner chung.',
            '- [fixture-results-confirmed.json](fixture-results-confirmed.json): 15 fixture hoàn tất, có catalog đầu vào và context gửi generator.',
            '- [session-results-confirmed.json](session-results-confirmed.json): hai phiên và kiểm tra quyền/chứng cứ không thay đổi MongoDB.',
            '- [api-retest-latest.json](api-retest-latest.json): CHAT-045 trên dịch vụ hiện tại.',
            '- [context-probes.json](context-probes.json): xác minh trực tiếp parser cho CHAT-049/058.',
            '- [catalog.json](catalog.json): snapshot chỉ có dữ liệu sản phẩm.',
            '- [session-results-attempt2.json](session-results-attempt2.json): lỗi bổ sung khi hồ sơ là BSON array.',
            '- [execute_fixtures.py](../execute_fixtures.py), [execute_sessions.py](../execute_sessions.py), [run_cases.py](../run_cases.py): mã chạy test. Chọn --output mới khi chạy lại để giữ bằng chứng cũ.','']
    (RUN/'RESULTS.md').write_text('\n'.join(lines),encoding='utf8')
    print(json.dumps({'counts':counts,'groups':{k:dict(v) for k,v in groups.items()},'cases':len(rows)},ensure_ascii=False))

if __name__=='__main__': main()
