# BƯỚC 01 — SỬA TÌM KIẾM THEO TỪNG TỪ (MongoDB, PHP)

## Vai trò
Kỹ sư backend PHP. Sửa lỗi tìm kiếm trả 0 kết quả với truy vấn nhiều ý, **thay đổi nhỏ nhất, an toàn nhất**, không phá trang admin.

## Đọc trước (theo thứ tự, không đọc thêm lịch sử chat)
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (bối cảnh + kết quả Bước 00)
3. `docs/plans/2026-10-21-neo4j-search-audit.md` mục 1, 2 (tên trường thật, nơi gọi `paginate`)
4. Code: `backend/app/models/SanPham.php` (`paginate`, `paginateWithProjection`, `buildSearchRegex`, `availableProductFilter`), `backend/app/controllers/SanPhamController.php` (hàm xử lý route `tatca`).

## Bối cảnh
- Hiện tại `paginateWithProjection` (~dòng 846) làm:
  ```php
  $regex = $this->buildSearchRegex($q);          // nguyên cụm
  $filter['$or'] = [ ['ten_san_pham'=>$regex], ['ma_san_pham'=>$regex], ['thuong_hieu'=>$regex],
                     ['danh_muc_day_du'=>$regex], ['loai_san_pham'=>$regex], ['barcode'=>$regex] ];
  if (is_numeric(trim($q))) $filter['$or'][] = ['ma_san_pham' => (int)trim($q)];
  ```
- Tên SP thường dạng "Sữa Rửa Mặt Torriden …", hãng ở `thuong_hieu` → nguyên cụm "torriden sữa rửa mặt" không khớp trường nào.
- `paginate` dùng chung cho admin (tìm theo mã SP, barcode). MongoDB là Atlas.

## Yêu cầu chức năng
1. **Tách token**: chuẩn hóa `q` (trim, gộp khoảng trắng, chữ thường), tách theo khoảng trắng; bỏ stopword tiếng Việt ngắn (`cho, và, của, các, loại, dành, the, for` — đặt trong hằng số); bỏ token rỗng; **tối đa 6 token** (giữ 6 token đầu).
2. **Logic khớp**: mỗi token phải khớp **ít nhất một** trường trong danh sách cũ (OR giữa trường), **mọi** token phải khớp (AND giữa token):
   ```php
   $filter['$and'][] = ['$or' => [ [field1 => rx(tok)], [field2 => rx(tok)], ... ]];   // lặp cho từng token
   ```
3. **Không dấu**: `sua rua mat torriden` phải ra cùng kết quả. Cách làm (chọn theo câu trả lời của người dùng trong handoff; nếu chưa có câu trả lời thì dùng **cách A** và ghi vào câu hỏi):
   - **A (không đổi dữ liệu)**: sinh regex lớp ký tự cho mỗi nguyên âm/`d` (vd `a` → `[aàáạảãâầấậẩẫăằắặẳẵ]`, `d` → `[dđ]`), áp dụng sau khi bỏ dấu token, flag `i`. Viết thành hàm private `buildAccentInsensitiveRegex(string $token): MongoDB\BSON\Regex`, dùng `preg_quote` cho ký tự đặc biệt.
   - **B (cần người dùng đồng ý)**: trường `search_text` đã bỏ dấu + index. KHÔNG tự làm.
4. **Giữ nguyên**: nhánh `is_numeric` (tìm mã SP chính xác) vẫn phải hoạt động — khi `q` là số, giữ hành vi cũ (OR nguyên cụm + mã số). Khi chỉ có 1 token, kết quả phải **giống hoặc rộng hơn** hành vi cũ.
5. **Xếp hạng (chỉ khi `sort=default` và có `q`)**: ưu tiên SP mà `ten_san_pham` khớp **tất cả** token. Cách an toàn: nếu hiện đang dùng `find` + sort, giữ nguyên; chỉ thêm ưu tiên nếu làm được không đổi phân trang (vd `aggregate` với `$addFields` điểm rồi `$sort`). Nếu phức tạp/rủi ro → bỏ qua và ghi vào handoff.
6. Không đổi chữ ký public của `paginate`. Không đổi dữ liệu truyền sang view.

## Quy trình bắt buộc
1. `git status` để biết thay đổi có sẵn của người dùng — không đụng vào.
2. **GitNexus impact** trước khi sửa: `node .gitnexus/run.cjs impact "paginateWithProjection" --direction upstream --repo .`, tương tự `buildSearchRegex`, `paginate`. Báo callers + risk. HIGH/CRITICAL → **dừng, báo người dùng**. UNKNOWN → xác nhận bằng `rg`.
3. TDD: viết test trước cho hàm tách token và hàm regex không dấu (test thuần PHP, **không kết nối DB**). Đặt ở vị trí test phù hợp với repo (xem audit mục 7); nếu không có phpunit, viết script test độc lập trong `backend/tests/unit/` chỉ gọi hàm thuần, không include config DB. **Không chạy các script cũ trong `backend/tests/`.**
4. Implement.
5. Kiểm chứng.

## Kiểm chứng (phải chạy thật, dán kết quả)
- `php -l` cho mọi file đã sửa.
- Test unit mới: pass.
- Truy vấn thật trên trang (chỉ đọc) bằng trình duyệt/Playwright tại `http://localhost:8080`:
  | Truy vấn | Kỳ vọng |
  |---|---|
  | `torriden sữa rửa mặt` | ≥ 1 SP, đúng sữa rửa mặt Torriden |
  | `sua rua mat torriden` | giống dòng trên |
  | `Torriden` | số lượng ≥ hành vi cũ |
  | mã SP dạng số (lấy 1 mã thật) | ra đúng SP đó |
  | `xyzabc` | 0 kết quả, không lỗi |
  | `serum vitamin c` | có kết quả hợp lý |
- Trang admin danh sách SP: tìm theo tên và mã vẫn chạy.
- Chụp ảnh trước/sau vào `report/search-token-e2e/`.
- `git diff --check`.

## Đầu ra
- Code đã sửa + test.
- Thêm mục "Bước 01" vào `docs/plans/smart-search-handoff.md`: cách khớp không dấu đã chọn, tên hàm mới, danh sách stopword, kết quả bảng kiểm chứng, việc bỏ qua.
- **Không commit** trừ khi người dùng yêu cầu; nếu được yêu cầu commit thì chạy `node .gitnexus/run.cjs detect-changes --scope all --repo .` trước.

## Tiêu chí hoàn thành
Bảng kiểm chứng đạt hết; admin không hỏng; `php -l` sạch; handoff đã cập nhật.
