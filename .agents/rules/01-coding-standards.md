# Rule: Coding Standards

> Điền/chỉnh theo stack thực tế của dự án bạn — đây là bộ mặc định hợp lý.

## Nguyên tắc chung
- Ưu tiên đọc được (readable) hơn ngắn gọn (clever).
- Đặt tên biến/hàm mô tả ý nghĩa, tránh viết tắt khó hiểu.
- Hàm nên làm một việc; nếu hàm > ~40-50 dòng, cân nhắc tách nhỏ.
- Không để code chết (dead code), comment thừa, hoặc `console.log`/`print` debug sót lại.
- Xử lý lỗi rõ ràng — không nuốt exception âm thầm (`except: pass`, `catch {}` rỗng).

## Style & format
- Tuân theo formatter/linter đã cấu hình sẵn trong repo (prettier, black, eslint, ruff...).
  Không tự đặt ra style riêng nếu repo đã có config.
- Giữ nhất quán với style code xung quanh, kể cả khi không phải style bạn thích nhất.

## Test
- Mọi tính năng mới hoặc bugfix cần đi kèm test tương ứng.
- Test phải chạy độc lập, không phụ thuộc thứ tự chạy hay dữ liệu ngoài.
- Không sửa test chỉ để test pass mà không sửa nguyên nhân gốc — trừ khi test sai.

## Dependencies
- Không thêm thư viện mới nếu có thể giải quyết bằng code ngắn gọn hoặc lib đã có sẵn.
- Khi thêm dependency: ghi rõ lý do trong PR description.

## Bảo mật cơ bản
- Không hardcode secret/API key/token trong code.
- Validate input ở boundary (API endpoint, form, CLI arg).
- Không dùng string concatenation để build SQL query — dùng parameterized query.
