# -*- coding: utf-8 -*-
# ---------------- TÀI LIỆU THAM KHẢO ----------------
H1("TÀI LIỆU THAM KHẢO")
def REF(t):
    p = doc.add_paragraph(style="Normal")
    p.paragraph_format.left_indent = Cm(1); p.paragraph_format.first_line_indent = Cm(-1)
    p.paragraph_format.space_before = Pt(4); p.paragraph_format.line_spacing = 1.15
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    parts = re.split(r"(__.*?__)", t)
    for part in parts:
        if part.startswith("__"):
            r = p.add_run(part[2:-2]); r.italic = True
        else:
            runs(p, part)
P("**Tiếng Việt**")
REF("[1]. Phòng Thương mại và Công nghiệp Việt Nam (11/2026), __Thương mại điện tử vượt mốc 25 tỷ USD__, https://vcci.com.vn/tin-tuc/thuong-mai-dien-tu-vuot-moc-25-ty-usd")
P("**Tiếng Anh**")
for t in [
 "[2]. G. Adomavicius, A. Tuzhilin (2005), \"Toward the next generation of recommender systems: A survey of the state-of-the-art and possible extensions\", __IEEE Transactions on Knowledge and Data Engineering__ 17 (6), pp. 734-749.",
 "[3]. R. Burke (2002), \"Hybrid recommender systems: Survey and experiments\", __User Modeling and User-Adapted Interaction__ 12 (4), pp. 331-370.",
 "[4]. G. V. Cormack, C. L. A. Clarke, S. Büttcher (2009), \"Reciprocal rank fusion outperforms Condorcet and individual rank learning methods\", __Proceedings of the 32nd ACM SIGIR Conference__, pp. 758-759.",
 "[5]. S. Es, J. James, L. Espinosa-Anke, S. Schockaert (2024), \"RAGAS: Automated evaluation of retrieval augmented generation\", __Proceedings of EACL 2024: System Demonstrations__, pp. 150-158.",
 "[6]. J. L. Herlocker, J. A. Konstan, L. G. Terveen, J. T. Riedl (2004), \"Evaluating collaborative filtering recommender systems\", __ACM Transactions on Information Systems__ 22 (1), pp. 5-53.",
 "[7]. A. Hogan et al. (2021), \"Knowledge graphs\", __ACM Computing Surveys__ 54 (4), pp. 1-37.",
 "[8]. K. Järvelin, J. Kekäläinen (2002), \"Cumulated gain-based evaluation of IR techniques\", __ACM Transactions on Information Systems__ 20 (4), pp. 422-446.",
 "[9]. Y. Koren, R. Bell, C. Volinsky (2009), \"Matrix factorization techniques for recommender systems\", __Computer__ 42 (8), pp. 30-37.",
 "[10]. G. E. Krasner, S. T. Pope (1988), \"A cookbook for using the model-view-controller user interface paradigm in Smalltalk-80\", __Journal of Object-Oriented Programming__ 1 (3), pp. 26-49.",
 "[11]. P. Lewis et al. (2020), \"Retrieval-augmented generation for knowledge-intensive NLP tasks\", __Advances in Neural Information Processing Systems__ 33, pp. 9459-9474.",
 "[12]. R. Likert (1932), \"A technique for the measurement of attitudes\", __Archives of Psychology__ 22 (140), pp. 1-55.",
 "[13]. C. D. Manning, P. Raghavan, H. Schütze (2008), __Introduction to Information Retrieval__, Cambridge University Press, Cambridge, UK.",
 "[14]. D. Merkel (2014), \"Docker: Lightweight Linux containers for consistent development and deployment\", __Linux Journal__ 2014 (239), Article 2.",
 "[15]. N. Reimers, I. Gurevych (2019), \"Sentence-BERT: Sentence embeddings using Siamese BERT-networks\", __Proceedings of EMNLP-IJCNLP 2019__, pp. 3982-3992.",
 "[16]. F. Ricci, L. Rokach, B. Shapira (2022), __Recommender Systems Handbook__, 3rd ed., Springer, New York, USA.",
 "[17]. S. Robertson, H. Zaragoza (2009), \"The probabilistic relevance framework: BM25 and beyond\", __Foundations and Trends in Information Retrieval__ 3 (4), pp. 333-389.",
 "[18]. I. Robinson, J. Webber, E. Eifrem (2015), __Graph Databases__, 2nd ed., O'Reilly Media, Sebastopol, USA.",
 "[19]. A. Vaswani et al. (2017), \"Attention is all you need\", __Advances in Neural Information Processing Systems__ 30, pp. 5998-6008.",
 "[20]. Chroma (11/2026), https://docs.trychroma.com",
 "[21]. Docker Inc. (11/2026), https://docs.docker.com",
 "[22]. Flask – Pallets Projects (11/2026), https://flask.palletsprojects.com",
 "[23]. LangChain (11/2026), https://python.langchain.com/docs",
 "[24]. LangGraph (11/2026), https://langchain-ai.github.io/langgraph",
 "[25]. MongoDB Inc. (11/2026), https://www.mongodb.com/docs",
 "[26]. Neo4j Inc. (11/2026), https://neo4j.com/docs/cypher-manual",
 "[27]. Nginx (11/2026), https://nginx.org/en/docs",
 "[28]. The PHP Group (11/2026), https://www.php.net/manual",
 "[29]. Redis Ltd. (11/2026), https://redis.io/docs",
]:
    REF(t)

# ===== PHỤ LỤC A (tự sinh từ Google Form) =====

PB()
H1("PHỤ LỤC A. KẾT QUẢ KHẢO SÁT TRÊN GOOGLE FORM")
P("Phụ lục này là ảnh chụp trang Câu trả lời (Bản tóm tắt) của phiếu Google Form \"Khảo sát nhu cầu tư vấn và mua mỹ phẩm trực tuyến\" tại thời điểm có 141 phản hồi, gồm 106 khách hàng, 19 nhân viên và 16 chủ cửa hàng. "
  "Các biểu đồ được giữ nguyên như Google Form hiển thị, chỉ mở rộng khung với các biểu đồ có nhiều câu để không bị cắt.")
F("Hình A.1 Câu phân nhóm: Bạn là ai trong các đối tượng sau?", "")
F("Hình A.2 Phiếu khách hàng: Giới tính của bạn", "")
F("Hình A.3 Phiếu khách hàng: Độ tuổi của bạn", "")
F("Hình A.4 Phiếu khách hàng: Loại da của bạn (theo bạn tự đánh giá)", "")
F("Hình A.5 Phiếu khách hàng: Vấn đề da bạn đang gặp hoặc quan tâm nhất là gì? (Có thể chọn nhiều đáp án)", "")
F("Hình A.6 Phiếu khách hàng: Bạn mua mỹ phẩm trực tuyến (Shopee, Hasaki, TikTok Shop...) bao lâu một lần?", "")
F("Hình A.7 Phiếu khách hàng: Phần A. Khó khăn khi chọn sản phẩm – Mức độ đồng ý của bạn với các phát biểu sau", "")
F("Hình A.8 Phiếu khách hàng: Phần B. Rủi ro về thành phần – Mức độ đồng ý của bạn với các phát biểu sau", "")
F("Hình A.9 Phiếu khách hàng: Phần C. Trải nghiệm với các nền tảng hiện tại – Mức độ đồng ý của bạn với các phát…", "")
F("Hình A.10 Phiếu khách hàng: 8. Bạn đã từng bị kích ứng, nổi mụn hoặc đỏ rát sau khi dùng mỹ phẩm mua trực tuyến…", "")
F("Hình A.11 Phiếu khách hàng: 9. Bạn thường mất bao lâu tìm hiểu trước khi mua một sản phẩm mỹ phẩm mới?", "")
F("Hình A.12 Phiếu khách hàng: Phần E. Mong muốn của bạn – Mức độ đồng ý của bạn với các phát biểu sau", "")
F("Hình A.13 Phiếu nhân viên: Vị trí công việc của anh/chị", "")
F("Hình A.14 Phiếu nhân viên: Thời gian anh/chị làm việc trong ngành mỹ phẩm", "")
F("Hình A.15 Phiếu nhân viên: Phần A. Khó khăn khi tư vấn thủ công – Mức độ đồng ý của anh/chị với các phát biểu…", "")
F("Hình A.16 Phiếu nhân viên: Phần B. Áp lực công việc và mất khách – Mức độ đồng ý của anh/chị với các phát biểu…", "")
F("Hình A.17 Phiếu nhân viên: Phần C. Giải pháp – Mức độ đồng ý của anh/chị với các phát biểu sau", "")
F("Hình A.18 Phiếu nhân viên: 11. Mỗi ngày anh/chị trả lời khoảng bao nhiêu câu hỏi tư vấn?", "")
F("Hình A.19 Phiếu nhân viên: 12. Khách thường phải chờ bao lâu mới được trả lời?", "")
F("Hình A.20 Phiếu nhân viên: 13. Mỗi tháng có khoảng bao nhiêu trường hợp khiếu nại hoặc đổi trả vì sản phẩm…", "")
F("Hình A.21 Phiếu nhân viên: 14. Khoảng bao nhiêu phần trăm khách hỏi xong rồi không mua vì phải chờ hoặc chưa…", "")
F("Hình A.22 Phiếu chủ cửa hàng: Hình thức kinh doanh của cửa hàng", "")
F("Hình A.23 Phiếu chủ cửa hàng: Số nhân viên tư vấn của cửa hàng", "")
F("Hình A.24 Phiếu chủ cửa hàng: Số lượng sản phẩm cửa hàng đang bán", "")
F("Hình A.25 Phiếu chủ cửa hàng: Phần A. Chi phí và nhân sự tư vấn – Mức độ đồng ý của anh/chị với các phát biểu sau", "")
F("Hình A.26 Phiếu chủ cửa hàng: Phần B. Rủi ro uy tín và dữ liệu – Mức độ đồng ý của anh/chị với các phát biểu sau", "")
F("Hình A.27 Phiếu chủ cửa hàng: Phần C. Nội dung tiếp thị – Mức độ đồng ý của anh/chị với các phát biểu sau", "")
F("Hình A.28 Phiếu chủ cửa hàng: Phần D. Giải pháp – Mức độ đồng ý của anh/chị với các phát biểu sau", "")
F("Hình A.29 Phiếu chủ cửa hàng: 13. Một nhân viên mới cần bao lâu để tự tư vấn thành phần sản phẩm cho khách?", "")
F("Hình A.30 Phiếu chủ cửa hàng: 14. Mỗi tháng cửa hàng chi khoảng bao nhiêu cho việc làm video và livestream?", "")
F("Hình A.31 Phiếu chủ cửa hàng: 15. Cửa hàng hiện lưu thông tin thành phần sản phẩm bằng cách nào?", "")
