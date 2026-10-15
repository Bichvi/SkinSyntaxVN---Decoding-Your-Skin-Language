# Nhạc nền AI Idol

Thả file nhạc **miễn phí bản quyền** (`.mp3`, `.wav`, `.m4a`, `.aac`, `.ogg`, `.flac`) vào thư mục này.
Mỗi video tự chọn một bài cố định theo `job_id` (hoặc theo `configuration.music_track` nếu có).
Nhạc tự hạ khi Syna nói và lớn lên ở khoảng nghỉ, có đoạn mở/kết ngắn.

- Thư mục trống → video chỉ được chuẩn hóa âm lượng giọng nói (không có nhạc).
- Tắt nhạc cho một video: `configuration.music_enabled = false`.
- Docker mount thư mục này chỉ đọc vào `/app/assets/music`, thêm nhạc không cần build lại image.
- File nhạc không được commit (xem `.gitignore`).

Nguồn gợi ý: YouTube Audio Library, Pixabay Music (kiểm tra điều kiện giấy phép từng bài).

## Thư viện hiện tại (Pixabay Content License, tải 2026-10-14)

Được tự chọn:
- `databend-uplifting-house-uk-garage-instrumental-522866.mp3` — Databend, https://pixabay.com/music/upbeat-uplifting-house-uk-garage-instrumental-version-522866/
- `john_sib-fashion-3678.mp3` — John_Sib, https://pixabay.com/music/soft-house-fashion-3678/
- `20142310-the-fashion-house-2883.mp3` — 20142310, https://pixabay.com/music/soft-house-the-fashion-house-2883/
- `prettyjohn1-fashion-507165.mp3` — prettyjohn1, https://pixabay.com/music/soft-house-fashion-507165/

Tạm cất trong `content-id/` (Pixabay ghi "Content ID Registered" — có thể bị claim khi đăng/livestream YouTube).
Mixer chỉ đọc file ở thư mục gốc; chuyển file ra ngoài nếu muốn dùng:
- `tunetank-fashion-house-music-347881.mp3` — Tunetank, https://pixabay.com/music/house-fashion-house-music-347881/
- `bransboynd-fashion-dance-house-482070.mp3` — Bransboynd, https://pixabay.com/music/soft-house-fashion-dance-house-482070/
