-- ============================================
-- Schema SkinSyntax - Phù hợp Domain Model
-- ============================================

-- 1. Bảng VAI_TRO (Role)
DROP TABLE IF EXISTS vai_tro CASCADE;
CREATE TABLE vai_tro (
    ma_vai_tro SERIAL PRIMARY KEY,
    ten_vai_tro VARCHAR(100) NOT NULL UNIQUE,
    mo_ta TEXT
);

-- 2. Bảng LOAI_DA (Skin Type)
DROP TABLE IF EXISTS loai_da CASCADE;
CREATE TABLE loai_da (
    ma_loai_da SERIAL PRIMARY KEY,
    ten_loai_da VARCHAR(100) NOT NULL UNIQUE,
    mo_ta TEXT
);

-- 3. Bảng THUONG_HIEU (Brand/Trademark)
DROP TABLE IF EXISTS thuong_hieu CASCADE;
CREATE TABLE thuong_hieu (
    ma_thuong_hieu SERIAL PRIMARY KEY,
    ten_thuong_hieu VARCHAR(255) NOT NULL UNIQUE,
    mo_ta TEXT,
    status VARCHAR(20) DEFAULT 'active'
);

-- 4. Bảng NOI_SAN_XUAT (Manufacturing Location)
DROP TABLE IF EXISTS noi_san_xuat CASCADE;
CREATE TABLE noi_san_xuat (
    ma_nsx SERIAL PRIMARY KEY,
    ten_nsx VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'active'
);

-- 5. Bảng XUAT_XU_THUONG_HIEU (Brand Origin)
DROP TABLE IF EXISTS xuat_xu_thuong_hieu CASCADE;
CREATE TABLE xuat_xu_thuong_hieu (
    ma_xuat_xu SERIAL PRIMARY KEY,
    ten_xuat_xu VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'active'
);

-- 6. Bảng LOAI_SAN_PHAM (Product Type)
DROP TABLE IF EXISTS loai_san_pham CASCADE;
CREATE TABLE loai_san_pham (
    ma_loai SERIAL PRIMARY KEY,
    ten_loai VARCHAR(255) NOT NULL UNIQUE,
    status VARCHAR(20) DEFAULT 'active'
);

-- 7. Bảng DANH_MUC (Category)
DROP TABLE IF EXISTS danh_muc CASCADE;
CREATE TABLE danh_muc (
    ma_danh_muc SERIAL PRIMARY KEY,
    ten_danh_muc VARCHAR(255) NOT NULL UNIQUE,
    mo_ta TEXT,
    status VARCHAR(20) DEFAULT 'active'
);

-- 8. Bảng SAN_PHAM (Product)
DROP TABLE IF EXISTS san_pham CASCADE;
CREATE TABLE san_pham (
    ma_san_pham VARCHAR(100) PRIMARY KEY,
    ten_san_pham TEXT NOT NULL,
    ma_loai INTEGER REFERENCES loai_san_pham(ma_loai),
    ma_thuong_hieu INTEGER REFERENCES thuong_hieu(ma_thuong_hieu),
    ma_nsx INTEGER REFERENCES noi_san_xuat(ma_nsx),
    ma_xuat_xu INTEGER REFERENCES xuat_xu_thuong_hieu(ma_xuat_xu),
    ma_danh_muc INTEGER REFERENCES danh_muc(ma_danh_muc),
    gia_ban BIGINT,
    gia_thi_truong BIGINT,
    tien_tiet_kiem BIGINT,
    phan_tram_giam INTEGER,
    diem_danh_gia NUMERIC(3,1),
    so_luong_danh_gia INTEGER,
    dung_tich VARCHAR(100),
    loai_da VARCHAR(100),
    link_hinh_anh TEXT,
    mo_ta TEXT,
    thanh_phan_chinh TEXT,
    thanh_phan_day_du TEXT,
    hdsd TEXT,
    attribute TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. Bảng KHACH_HANG (Customer)
DROP TABLE IF EXISTS khach_hang CASCADE;
CREATE TABLE khach_hang (
    ma_kh SERIAL PRIMARY KEY,
    ho_ten VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    so_dien_thoai VARCHAR(20),
    gioi_tinh VARCHAR(10),
    nam_sinh INTEGER,
    dia_chi TEXT,
    muc_do_nhay_cam VARCHAR(100),
    van_de_da TEXT,
    muc_do_mun VARCHAR(100),
    muc_tieu_cham_soc TEXT,
    tieu_chi_uu_tien TEXT,
    tinh_trang_dac_biet TEXT,
    kinh_nghiem_skincare TEXT,
    so_buoc_skincare VARCHAR(100),
    thanh_phan_tranh TEXT,
    ngan_sach BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9.1 Bảng NGUOIDUNG (Website Account)
DROP TABLE IF EXISTS nguoidung CASCADE;
CREATE TABLE nguoidung (
    id BIGSERIAL PRIMARY KEY,
    ho_ten VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    mat_khau VARCHAR(255) NOT NULL,
    ngay_tao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 10. Bảng NHAN_VIEN (Staff/Employee)
DROP TABLE IF EXISTS nhan_vien CASCADE;
CREATE TABLE nhan_vien (
    ma_nv SERIAL PRIMARY KEY,
    ho_ten VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    so_dien_thoai VARCHAR(20),
    mat_khau VARCHAR(255),
    ma_vai_tro INTEGER REFERENCES vai_tro(ma_vai_tro),
    trang_thai VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP
);

-- 11. Bảng GIO_HANG (Shopping Cart)
DROP TABLE IF EXISTS gio_hang CASCADE;
CREATE TABLE gio_hang (
    id BIGSERIAL PRIMARY KEY,
    ma_kh INTEGER REFERENCES khach_hang(ma_kh) ON DELETE CASCADE,
    ma_san_pham VARCHAR(100) REFERENCES san_pham(ma_san_pham) ON DELETE CASCADE,
    so_luong INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(ma_kh, ma_san_pham)
);

-- 12. Bảng HOA_DON (Order/Invoice)
DROP TABLE IF EXISTS hoa_don CASCADE;
CREATE TABLE hoa_don (
    ma_hoa_don BIGSERIAL PRIMARY KEY,
    ma_kh INTEGER REFERENCES khach_hang(ma_kh),
    ngay_dat TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    tong_tien BIGINT DEFAULT 0,
    trang_thai VARCHAR(50) DEFAULT 'moi',
    dia_chi_giao_hang TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 13. Bảng CHI_TIET_HOA_DON (Order Detail)
DROP TABLE IF EXISTS chi_tiet_hoa_don CASCADE;
CREATE TABLE chi_tiet_hoa_don (
    id BIGSERIAL PRIMARY KEY,
    ma_hoa_don BIGINT REFERENCES hoa_don(ma_hoa_don) ON DELETE CASCADE,
    ma_san_pham VARCHAR(100) REFERENCES san_pham(ma_san_pham),
    so_luong INTEGER NOT NULL,
    don_gia BIGINT NOT NULL DEFAULT 0,
    status_thanh_toan VARCHAR(50) DEFAULT 'chua_thanh_toan',
    hinh_thuc_thanh_toan VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. Bảng DANH_GIA (Rating/Review)
DROP TABLE IF EXISTS danh_gia CASCADE;
CREATE TABLE danh_gia (
    ma_danh_gia BIGSERIAL PRIMARY KEY,
    ma_san_pham VARCHAR(100) REFERENCES san_pham(ma_san_pham),
    ma_kh INTEGER REFERENCES khach_hang(ma_kh),
    so_sao INTEGER CHECK (so_sao >= 1 AND so_sao <= 5),
    noi_dung TEXT,
    ngay_danh_gia TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 15. Bảng LICH_SU_CHAT (Chat History)
DROP TABLE IF EXISTS lich_su_chat CASCADE;
CREATE TABLE lich_su_chat (
    ma_chat BIGSERIAL PRIMARY KEY,
    ma_kh INTEGER REFERENCES khach_hang(ma_kh),
    ma_nv INTEGER REFERENCES nhan_vien(ma_nv),
    noi_dung TEXT NOT NULL,
    thoi_gian TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- INSERT MẶC ĐỊNH
-- ============================================

INSERT INTO vai_tro (ten_vai_tro, mo_ta) VALUES 
('Admin', 'Quản trị viên hệ thống'),
('NhanVien', 'Nhân viên bán hàng'),
('KhachHang', 'Khách hàng')
ON CONFLICT DO NOTHING;

-- ============================================
-- TẠO INDEX
-- ============================================

CREATE INDEX idx_san_pham_loai ON san_pham(ma_loai);
CREATE INDEX idx_san_pham_thuong_hieu ON san_pham(ma_thuong_hieu);
CREATE INDEX idx_san_pham_danh_muc ON san_pham(ma_danh_muc);
CREATE INDEX idx_gio_hang_khach_hang ON gio_hang(ma_kh);
CREATE INDEX idx_gio_hang_san_pham ON gio_hang(ma_san_pham);
CREATE INDEX idx_hoa_don_khach_hang ON hoa_don(ma_kh);
CREATE INDEX idx_chi_tiet_hoa_don ON chi_tiet_hoa_don(ma_hoa_don);
CREATE INDEX idx_danh_gia_san_pham ON danh_gia(ma_san_pham);
CREATE INDEX idx_danh_gia_khach_hang ON danh_gia(ma_kh);
CREATE INDEX idx_lich_su_chat_khach_hang ON lich_su_chat(ma_kh);

-- ============================================
-- HOÀN THÀNH
-- ============================================
SELECT 'Schema created successfully' AS status;
