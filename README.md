"# SkinSyntax-VN---Decoding-Your-Skin-s-Language"

## Cách sử dụng

- **Yêu cầu:**
	- XAMPP (Apache + MySQL) hoặc môi trường PHP tương đương
	- Python 3.8+ (để chạy dịch vụ AI/Flask và scripts)
	- `pip`

- **Cài đặt & cấu hình nhanh:**
	1. Sao chép toàn bộ thư mục vào `htdocs` của XAMPP (hoặc đảm bảo project nằm trong thư mục web server).
	2. Bật Apache và MySQL trong XAMPP.
	3. Import cơ sở dữ liệu: mở phpMyAdmin hoặc MySQL client và import `database/db.sql` vào một database mới (ví dụ: `skinsyntax`).
	4. Cập nhật thông tin kết nối DB trong `backend/app/config/config.php` (host, username, password, dbname).

- **Chạy ứng dụng web (PHP):**
	- Mở trình duyệt vào: `http://localhost/<tên_folder>/public` (thay `<tên_folder>` bằng tên thư mục chứa project trong `htdocs`).

- **Chạy dịch vụ AI/Flask (tùy chọn):**
	1. Mở terminal và chuyển vào thư mục `ai-service-flask`.
	2. Tạo virtual environment và kích hoạt:
		 - Windows:
			 ```
			 python -m venv venv
			 venv\\Scripts\\activate
			 ```
		 - macOS/Linux:
			 ```
			 python3 -m venv venv
			 source venv/bin/activate
			 ```
	3. Cài dependencies:
		 ```
		 pip install -r requirements_hybrid.txt
		 ```
	4. Chạy Flask service:
		 ```
		 python chatbot_flask.py
		 ```
	- Dịch vụ mặc định lắng nghe trên `http://127.0.0.1:5000`.

- **Scripts & dữ liệu:**
	- Các bước tiền xử lý và import dữ liệu nằm trong `spiders/skinSyntaxVN/` (ví dụ: `step1_cleaning.py`, `step2_import_dbPostgresQL.py`, `step3_eda_analysis.py`).
	- Các script thu thập/gộp CSV có trong `spiders/` (ví dụ: `merge_csvs.py`).

- **Tài liệu & ghi chú thêm:**
	- Xem `ai-service-flask/README_HYBRID_SEARCH.md` để biết tích hợp hybrid search.
	- Nếu bạn muốn, mình có thể mở rộng phần này với hướng dẫn cấu hình chi tiết hoặc lược đồ cơ sở dữ liệu.

