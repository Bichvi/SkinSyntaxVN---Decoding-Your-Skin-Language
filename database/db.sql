-- XÓA TOÀN BỘ BẢNG CŨ
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN (
        SELECT tablename
        FROM pg_tables
        WHERE schemaname = 'public'
    )
    LOOP
        EXECUTE 'DROP TABLE IF EXISTS public.' || quote_ident(r.tablename) || ' CASCADE';
    END LOOP;
END $$;


-- =========================
-- chi_tiet_hoa_don
-- =========================
CREATE TABLE chi_tiet_hoa_don (
    id BIGINT,
    ma_hoa_don BIGINT,
    ma_san_pham BIGINT,
    so_luong INTEGER,
    don_gia BIGINT,
    status_thanh_toan VARCHAR,
    hinh_thuc_thanh_toan VARCHAR,
    created_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- danh_gia
-- =========================
CREATE TABLE danh_gia (
    ma_danh_gia BIGINT,
    ma_san_pham BIGINT,
    ma_kh INTEGER,
    so_sao INTEGER,
    noi_dung TEXT,
    ngay_danh_gia TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- danh_muc
-- =========================
CREATE TABLE danh_muc (
    ma_danh_muc INTEGER,
    ten_danh_muc VARCHAR
);

-- =========================
-- gio_hang
-- =========================
CREATE TABLE gio_hang (
    id BIGINT,
    ma_kh INTEGER,
    ma_san_pham BIGINT,
    so_luong INTEGER,
    created_at TIMESTAMP WITHOUT TIME ZONE,
    updated_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- ho_so_da
-- =========================
CREATE TABLE ho_so_da (
    id BIGINT,
    nguoidung_id INTEGER,
    loai_da VARCHAR,
    van_de_da TEXT,
    ngan_sach INTEGER,
    created_at TIMESTAMP WITHOUT TIME ZONE,
    updated_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- hoa_don
-- =========================
CREATE TABLE hoa_don (
    ma_hoa_don BIGINT,
    ma_kh INTEGER,
    ngay_dat TIMESTAMP WITHOUT TIME ZONE,
    tong_tien BIGINT,
    trang_thai VARCHAR,
    dia_chi_giao_hang TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE,
    updated_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- khach_hang
-- =========================
CREATE TABLE khach_hang (
    ma_kh INTEGER,
    ho_ten VARCHAR,
    email VARCHAR,
    so_dien_thoai VARCHAR,
    gioi_tinh VARCHAR,
    nam_sinh INTEGER,
    dia_chi TEXT,
    muc_do_nhay_cam VARCHAR,
    van_de_da TEXT,
    muc_do_mun VARCHAR,
    muc_tieu_cham_soc TEXT,
    tieu_chi_uu_tien TEXT,
    tinh_trang_dac_biet TEXT,
    kinh_nghiem_skincare TEXT,
    so_buoc_skincare VARCHAR,
    thanh_phan_tranh TEXT,
    ngan_sach BIGINT,
    created_at TIMESTAMP WITHOUT TIME ZONE,
    updated_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- lich_su_chat
-- =========================
CREATE TABLE lich_su_chat (
    ma_chat BIGINT,
    ma_kh INTEGER,
    ma_nv INTEGER,
    noi_dung TEXT,
    thoi_gian TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- loai_da
-- =========================
CREATE TABLE loai_da (
    ma_loai_da INTEGER,
    ten_loai_da VARCHAR
);

-- =========================
-- loai_san_pham
-- =========================
CREATE TABLE loai_san_pham (
ma_loai INTEGER,
    ten_loai VARCHAR,
    status VARCHAR
);

-- =========================
-- nguoidung
-- =========================
CREATE TABLE nguoidung (
    id INTEGER,
    ho_ten VARCHAR,
    email VARCHAR,
    mat_khau VARCHAR,
    ngay_tao TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- nhan_vien
-- =========================
CREATE TABLE nhan_vien (
    ma_nv INTEGER,
    ho_ten VARCHAR,
    email VARCHAR,
    so_dien_thoai VARCHAR,
    mat_khau VARCHAR,
    ma_vai_tro INTEGER,
    trang_thai VARCHAR,
    created_at TIMESTAMP WITHOUT TIME ZONE,
    updated_at TIMESTAMP WITHOUT TIME ZONE,
    deleted_at TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- noi_san_xuat
-- =========================
CREATE TABLE noi_san_xuat (
    ma_nsx INTEGER,
    ten_nsx VARCHAR
);

-- =========================
-- san_pham
-- =========================
CREATE TABLE san_pham (
    ma_san_pham BIGINT,
    ten_san_pham VARCHAR,
    gia_ban INTEGER,
    gia_thi_truong INTEGER,
    tien_tiet_kiem INTEGER,
    phan_tram_giam INTEGER,
    dung_tich VARCHAR,
    loai_da VARCHAR,
    danh_muc_day_du TEXT,
    ma_thuong_hieu INTEGER,
    ma_danh_muc INTEGER,
    ma_xuat_xu INTEGER,
    ma_noi_san_xuat INTEGER,
    ma_loai_da INTEGER,
    diem_danh_gia DOUBLE PRECISION,
    so_luong_danh_gia INTEGER,
    hdsd TEXT,
    thanh_phan TEXT,
    thanh_phan_full TEXT,
    thanh_phan_sach TEXT,
    mo_ta TEXT,
    link_hinh_anh TEXT,
    ngay_tao TIMESTAMP WITHOUT TIME ZONE
);

-- =========================
-- thuong_hieu
-- =========================
CREATE TABLE thuong_hieu (
    ma_thuong_hieu INTEGER,
    ten_thuong_hieu VARCHAR
);

-- =========================
-- vai_tro
-- =========================
CREATE TABLE vai_tro (
    ma_vai_tro INTEGER,
    ten_vai_tro VARCHAR,
    mo_ta TEXT
);

-- =========================
-- xuat_xu
-- =========================
CREATE TABLE xuat_xu (
    ma_xuat_xu INTEGER,
    ten_xuat_xu VARCHAR
);

-- =========================
-- xuat_xu_thuong_hieu
-- =========================
CREATE TABLE xuat_xu_thuong_hieu (
    ma_xuat_xu INTEGER,
    ten_xuat_xu VARCHAR,
    status VARCHAR
);