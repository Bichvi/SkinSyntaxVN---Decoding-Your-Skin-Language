# ROUTES

## PHP (Frontend & Admin)

Endpoints được điều phối tại `backend/public/index.php` bằng query param `r`.

| Route (r=) | HTTP method | Controller | Function | View render | Middleware | Auth |
|---|---:|---|---|---|---|---|
| home | GET | HomeController | index | views/home.php | none | no |
| tatca | GET | SanPhamController | tatca | views/tatca.php | none | no |
| chitiet | GET/POST | SanPhamController | chitiet | views/chitiet.php | none | no |
| live_search | GET | SanPhamController | liveSearch | JSON | none | no |
| api_smart_search | GET | SanPhamController | apiSmartSearch | JSON | none | no |
| giohang | GET | HomeController | giohang | views/giohang.php | none | no |
| goiy | GET | HomeController | goiy | views/goiy.php | none | no |
| ai_chat_assistant | GET/POST | HomeController | aiChatAssistant | partial/widget | none | no |
| huong_dan_nhan_otp | GET | HomeController | otpGuide | views/info/otp-guide.php | none | no |
| dieu_kien_giao_dich | GET | HomeController | termsReference | views/info/... | none | no |
| chinh_sach_bao_mat | GET | HomeController | privacyReference | views/info/... | none | no |
| chinh_sach_xu_ly_du_lieu | GET | HomeController | personalDataReference | views/info/... | none | no |
| he_thong_cua_hang | GET | HomeController | storeNetwork | views/info/store-network.php | none | no |
| bao_hanh | GET | HomeController | warrantyCenter | views/info/service-hub.php | none | no |
| ho_tro_khach_hang | GET | HomeController | customerSupport | views/info/... | none | no |
| xulygoiy | POST | HomeController | xulygoiy | JSON / redirect | none | no |
| thanhtoan / xulydathang | POST | HomeController | thanhtoan / xulydathang | redirect / JSON | none | no |
| payment_autocheck | POST | HomeController | paymentAutoCheck | JSON | none | no |
| payment_webhook | POST | HomeController | paymentWebhook | JSON | none | no (public webhook)
| apdung_voucher | POST | HomeController | apDungVoucher | JSON | none | yes (cart owner)
| bo_voucher | POST | HomeController | boVoucher | redirect | none | yes
| apdung_diem | POST | HomeController | apDungDiem | JSON | none | yes
| bo_diem | POST | HomeController | boDiem | redirect | none | yes
| camon | GET | HomeController | camon | views/thankyou.php | none | no |

### Account / Auth routes

| Route (auth=) | HTTP method | Controller | Function | View render | Middleware | Auth |
|---|---:|---|---|---|---|---|
| dangnhap | GET | AuthController | dangnhap | redirects to auth/login page | none | no |
| xulydangnhap | POST | AuthController | xulydangnhap | redirect | none | no |
| dangky | GET | AuthController | dangky | views/auth/register.php | none | no |
| xulydangky | POST | AuthController | xulydangky | redirect | none | no |
| quen_mat_khau | GET | AuthController | quenMatKhau | views/auth/forgot.php | none | no |
| gui_lien_ket_dat_lai | POST | AuthController | guiLienKetDatLai | redirect / email | none | no |
| dat_lai_mat_khau | GET/POST | AuthController | datLaiMatKhau | views/auth/quenmatkhau.php | none | no |
| auth_social | GET | AuthController | authSocial | external redirect to provider | none | no |
| auth_social_callback | GET | AuthController | authSocialCallback | redirect | none | no |
| dangxuat | GET | AuthController | dangxuat | redirect | none | yes (logs out)

### Account profile

| Route | HTTP method | Controller | Function | View render | Auth |
| hoso | GET | TaiKhoanController | hoso | views/account/hoso.php | yes |
| capnhathosoda | POST | TaiKhoanController | capNhatHoSoDa | redirect | yes |
| capnhatthongtin | POST | TaiKhoanController | capNhatThongTin | redirect | yes |
| doimatkhau | POST | TaiKhoanController | doiMatKhau | redirect | yes |

### Admin / Staff (QuanTriController)

| Route | HTTP method | Controller | Function | View render | Middleware | Auth |
|---|---:|---|---|---|---|---|
| admin_dashboard | GET | QuanTriController | adminDashboard | views/admin/dashboard.php | requireRole(['admin']) | admin |
| admin_sp | GET | QuanTriController | adminProducts | views/admin/danhsachSP.php | requireRole(['admin']) | admin |
| admin_sp_create | GET/POST | QuanTriController | adminProductCreate | views/admin/form.php | requireRole(['admin']) | admin |
| admin_sp_edit | GET/POST | QuanTriController | adminProductEdit | views/admin/form.php | requireRole(['admin']) | admin |
| admin_sp_delete | POST | QuanTriController | adminProductDelete | redirect | requireRole(['admin']) | admin |
| admin_categories | GET/POST | QuanTriController | adminCategories | views/admin/categories.php | requireRole(['admin']) | admin |
| admin_vouchers | GET/POST | QuanTriController | adminVouchers | views/admin/vouchers.php | requireRole(['admin']) | admin |
| admin_users | GET/POST | QuanTriController | adminUsers | views/admin/users.php | requireRole(['admin']) | admin |

### Staff area (staff_* routes)

| Route | HTTP method | Controller | Function | Auth |
| staff_dashboard | GET | QuanTriController | staffDashboard | staff |
| staff_orders | GET/POST | QuanTriController | staffOrders / staffOrderStatus | staff |
| staff_products | GET | QuanTriController | staffProducts | staff |
| staff_product_create | GET/POST | QuanTriController | staffProductCreate | staff |
| staff_product_edit | GET/POST | QuanTriController | staffProductEdit | staff |

### Chats & reviews

| Route | HTTP method | Controller | Function | Auth |
| lichsuchat | GET | QuanTriController | customerChat | yes |
| chat_send | POST | QuanTriController | customerChatSend | yes |
| mark_chat_read | POST | QuanTriController | markChatRead | staff |
| guidanhgia | POST | QuanTriController | customerReviewSave | yes |

Refer to controller implementations for exact method handling (some handlers accept both GET/POST). Middleware is implemented via `requireRole()` and `is_logged_in()` checks inside controllers.

## Flask AI service (ai-service-flask)

| Route | Method | Handler (file) | Purpose | Auth |
|---|---:|---|---|---|
| /api/health | GET | `ai-service-flask/app.py::health` | Health check | none |
| /api/config | GET | `ai-service-flask/app.py::get_config` | Return service config | none |
| /api/query | POST | `ai-service-flask/app.py::query` | LlamaIndex query | none (requires setup)
| /api/load-documents | POST | `ai-service-flask/app.py::load_documents` | Load docs into index | none (admin only in practice)
| /api/recommend/explain | POST | `ai-service-flask/app.py::recommend_explain` | LLM explanation for recommendations | none (but should be protected)
| /api/recommend/hybrid | POST | `ai-service-flask/app.py::recommend_hybrid` | Hybrid retrieval + RAG | none (should use API key)
| /api/recommend/langchain-rag | POST | `ai-service-flask/app.py::recommend_hybrid` (alias) | Same as hybrid | none |
| /api/recommend/hybrid-search | POST | `ai-service-flask/api/langchain_endpoints.py` | Retrieval-only hybrid | none |
| /api/chat | POST | `ai-service-flask/app.py::chat` | Chat endpoint (RAG fallback to LlamaIndex) | none (should be rate-limited)
| /api/cache/stats | GET | `ai-service-flask/api/langchain_endpoints.py` | Cache stats (redis) | none (admin)
| /api/cache/clear | POST | `ai-service-flask/api/langchain_endpoints.py` | Clear cache | none (admin)

---

Notes:
- PHP app uses query parameter routing (legacy simple router). To call programmatically: `GET /index.php?r=tatca&q=serum`.
- Flask endpoints return JSON and are intended to be called from PHP or client-side JS.
