import pandas as pd
import psycopg2
from psycopg2 import Error

# ==============================================================================
# CẤU HÌNH DATABASE
# ==============================================================================
DB_CONFIG = {
    "host": "localhost",
    "database": "skinsyntax",
    "user": "postgres",
    "password": "123456" 
}
INPUT_FILE = 'data_clean_final.csv'

def load_data_to_postgres():
    print(f"🚀 [DB LOAD] Bắt đầu nhập liệu vào PostgreSQL...")
    conn = None 
    
    try:
        # Đọc file CSV, biến NaN thành None để PostgreSQL hiểu là NULL
        df = pd.read_csv(INPUT_FILE)
        df = df.where(pd.notnull(df), None)
    except FileNotFoundError:
        print(f"❌ Lỗi: Không tìm thấy file '{INPUT_FILE}'")
        return

    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        # 1. TẠO CẤU TRÚC BẢNG (SCHEMA MỚI NHẤT)
        print("   -> Đang thiết lập cấu trúc bảng (Schema)...")
        # Xóa bảng cũ để tạo lại cho sạch
        cursor.execute("""
            DROP TABLE IF EXISTS san_pham CASCADE;
            DROP TABLE IF EXISTS loai_da CASCADE;
            DROP TABLE IF EXISTS danh_muc CASCADE;
            DROP TABLE IF EXISTS thuong_hieu CASCADE;
            DROP TABLE IF EXISTS xuat_xu CASCADE;
            DROP TABLE IF EXISTS noi_san_xuat CASCADE;
            
            -- BẢNG VỆ TINH (DIMENSIONS)
            CREATE TABLE IF NOT EXISTS danh_muc (ma_danh_muc SERIAL PRIMARY KEY, ten_danh_muc VARCHAR(255) UNIQUE NOT NULL);
            CREATE TABLE IF NOT EXISTS thuong_hieu (ma_thuong_hieu SERIAL PRIMARY KEY, ten_thuong_hieu VARCHAR(255) UNIQUE NOT NULL);
            CREATE TABLE IF NOT EXISTS xuat_xu (ma_xuat_xu SERIAL PRIMARY KEY, ten_xuat_xu VARCHAR(255) UNIQUE NOT NULL);
            CREATE TABLE IF NOT EXISTS noi_san_xuat (ma_nsx SERIAL PRIMARY KEY, ten_nsx VARCHAR(255) UNIQUE NOT NULL);
            CREATE TABLE IF NOT EXISTS loai_da (ma_loai_da SERIAL PRIMARY KEY, ten_loai_da VARCHAR(255) UNIQUE NOT NULL);
            
            -- BẢNG CHÍNH (SAN_PHAM)
            CREATE TABLE IF NOT EXISTS san_pham (
                -- 1. ĐỊNH DANH (ID tự tăng 1, 2, 3...)
                ma_san_pham BIGINT PRIMARY KEY, 
                ten_san_pham VARCHAR(500) NOT NULL UNIQUE, -- Tên không được trùng
                
                -- 2. GIÁ & KHUYẾN MÃI
                gia_ban INT DEFAULT 0,
                gia_thi_truong INT DEFAULT 0,
                tien_tiet_kiem INT DEFAULT 0,
                phan_tram_giam INT DEFAULT 0,
                
                -- 3. THÔNG SỐ SẢN PHẨM
                dung_tich VARCHAR(255),
                loai_da VARCHAR(255),
                danh_muc_day_du TEXT,
                
                -- 4. PHÂN LOẠI (KHÓA NGOẠI)
                ma_thuong_hieu INT REFERENCES thuong_hieu(ma_thuong_hieu),
                ma_danh_muc INT REFERENCES danh_muc(ma_danh_muc),
                ma_xuat_xu INT REFERENCES xuat_xu(ma_xuat_xu),
                ma_noi_san_xuat INT REFERENCES noi_san_xuat(ma_nsx),
                ma_loai_da INT REFERENCES loai_da(ma_loai_da),
                
                -- 5. ĐÁNH GIÁ
                diem_danh_gia FLOAT DEFAULT 0,
                so_luong_danh_gia INT DEFAULT 0,
                
                -- 6. NỘI DUNG VĂN BẢN
                hdsd TEXT,
                thanh_phan TEXT,            -- Thành phần hiển thị (đẹp)
                thanh_phan_full TEXT,       -- Thành phần đầy đủ
                thanh_phan_sach TEXT,       -- Thành phần sạch (cho AI)
                mo_ta TEXT,
                
                -- 7. HÌNH ẢNH & THỜI GIAN
                link_hinh_anh TEXT,
                ngay_tao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        """)
        conn.commit()

        # 2. NHẬP DỮ LIỆU CHO BẢNG VỆ TINH
        print("   -> Đang nhập Danh mục, Thương hiệu, Xuất xứ...")
        dimensions = {
            'danh_muc': ('ten_danh_muc', df['loai_san_pham']),
            'thuong_hieu': ('ten_thuong_hieu', df['thuong_hieu']),
            'xuat_xu': ('ten_xuat_xu', df['xuat_xu_thuong_hieu']),
            'noi_san_xuat': ('ten_nsx', df['noi_san_xuat']),
            'loai_da': ('ten_loai_da', df['loai_da'])
        }
        for table, (col_name, data_series) in dimensions.items():
            unique_vals = data_series.dropna().unique()
            for val in unique_vals:
                val = str(val).strip()
                if val:
                    # Insert nếu chưa có, bỏ qua nếu đã có (ON CONFLICT DO NOTHING)
                    cursor.execute(f"INSERT INTO {table} ({col_name}) VALUES (%s) ON CONFLICT ({col_name}) DO NOTHING;", (val,))
            conn.commit()

        # 3. LẤY MAP ID (Để chuyển tên thành số ID)
        def get_map(table, name_col, id_col):
            cursor.execute(f"SELECT {name_col}, {id_col} FROM {table};")
            return dict(cursor.fetchall())
            
        map_dm = get_map('danh_muc', 'ten_danh_muc', 'ma_danh_muc')
        map_th = get_map('thuong_hieu', 'ten_thuong_hieu', 'ma_thuong_hieu')
        map_xx = get_map('xuat_xu', 'ten_xuat_xu', 'ma_xuat_xu')
        map_nsx = get_map('noi_san_xuat', 'ten_nsx', 'ma_nsx')
        map_ld = get_map('loai_da', 'ten_loai_da', 'ma_loai_da')

        # 4. NHẬP SẢN PHẨM CHÍNH
        print("   -> Đang nhập sản phẩm (Quá trình này mất vài giây)...")
        inserted_count = 0
        for _, row in df.iterrows():
            # Tìm ID tương ứng từ các bảng vệ tinh
            id_dm = map_dm.get(str(row['loai_san_pham']).strip())
            id_th = map_th.get(str(row['thuong_hieu']).strip())
            id_xx = map_xx.get(str(row['xuat_xu_thuong_hieu']).strip())
            id_nsx = map_nsx.get(str(row['noi_san_xuat']).strip())
            id_ld = map_ld.get(str(row['loai_da']).strip())

            try:
                cursor.execute("""
                    INSERT INTO san_pham 
                    (
                        ma_san_pham, ten_san_pham,
                        gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam,
                        dung_tich, loai_da, danh_muc_day_du,
                        ma_thuong_hieu, ma_danh_muc, ma_xuat_xu, ma_noi_san_xuat, ma_loai_da,
                        diem_danh_gia, so_luong_danh_gia,
                        hdsd, thanh_phan, thanh_phan_full, thanh_phan_sach, mo_ta, link_hinh_anh
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (ma_san_pham) DO NOTHING;
                """, (
                    row['ma_san_pham'], row['ten_san_pham'],
                    row['gia_ban'], row['gia_thi_truong'], row['tien_tiet_kiem'], row['phan_tram_giam'],
                    row['dung_tich'], row['loai_da'], row['danh_muc_day_du'],
                    id_th, id_dm, id_xx, id_nsx, id_ld,
                    row['diem_danh_gia'], row['so_luong_danh_gia'],
                    row['hdsd'], row['thanh_phan_chinh'], row['thanh_phan_day_du'], row.get('thanh_phan_clean', ''), 
                    row['mo_ta'], row['link_hinh_anh']
                ))
                inserted_count += 1
            except Exception as e:
                # Ghi log lỗi nhẹ (nếu có) để biết dòng nào bị fail
                print(f"⚠️ Lỗi tại ID {row['ma_san_pham']}: {e}")
                conn.rollback()

        conn.commit()
        print(f"[HOÀN TẤT] Đã nhập thành công {inserted_count} sản phẩm vào Database!")

    except (Exception, Error) as error:
        print(f"❌ Lỗi kết nối Database: {error}")
    finally:
        if conn:
            cursor.close()
            conn.close()
            print("🔌 Đã đóng kết nối.")

if __name__ == "__main__":
    load_data_to_postgres()