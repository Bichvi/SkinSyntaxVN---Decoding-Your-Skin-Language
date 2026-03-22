import pandas as pd
import numpy as np
import re

# ==============================================================================
# CẤU HÌNH INPUT/OUTPUT
# ==============================================================================
INPUT_FILE = 'data_hasaki_v9_complete.csv'
OUTPUT_FILE = 'data_clean_final.csv'

TARGET_COLUMNS = [
    'ten_san_pham', 'danh_muc_day_du', 'loai_san_pham', 
    'gia_ban', 'gia_thi_truong', 
    'tien_tiet_kiem', 'phan_tram_giam', 
    'diem_danh_gia', 'so_luong_danh_gia', 
    'thuong_hieu', 'xuat_xu_thuong_hieu', 'noi_san_xuat', 
    'dung_tich', 'loai_da', 
    'link_hinh_anh', 'mo_ta', 
    'thanh_phan_chinh', 'thanh_phan_day_du', 'hdsd'
]

def clean_data_pipeline():
    print(f"🚀 [ETL] Bắt đầu Chuẩn hóa dữ liệu (Fix lỗi Giá x10 & Trùng thành phần)...")
    
    try:
        df = pd.read_csv(INPUT_FILE)
    except FileNotFoundError:
        print(f"❌ Lỗi: Không tìm thấy file '{INPUT_FILE}'")
        return

    # 1. LỌC CỘT & XÓA DÒNG RÁC
    cols_to_keep = [c for c in TARGET_COLUMNS if c in df.columns]
    df = df[cols_to_keep]
    
    df.drop_duplicates(subset=['ten_san_pham'], inplace=True)
    df.dropna(subset=['ten_san_pham', 'gia_ban'], inplace=True)
    
    # 2. TẠO MÃ SẢN PHẨM MỚI
    print("   -> Đang tạo hệ thống ID mới...")
    df['ma_san_pham'] = range(1, len(df) + 1)

    # 3. FIX LỖI GIÁ TIỀN (QUAN TRỌNG)
    print("   -> Đang chuẩn hóa giá tiền (Fix lỗi nhân 10)...")

    def clean_price_strict(val):
        if pd.isna(val) or val == '': return 0
        
        # Chuyển về string
        s = str(val).strip()
        
        # [FIX QUAN TRỌNG]: Nếu chuỗi có đuôi .0 (do pandas đọc float), phải cắt bỏ trước
        # Ví dụ: '150000.0' -> '150000' (Nếu không cắt, xóa dấu chấm sẽ thành 1500000 -> Sai x10)
        if s.endswith('.0'):
            s = s[:-2]
            
        # Chỉ giữ lại số
        digits = re.sub(r'\D', '', s)
        
        if not digits: return 0
        return int(digits)

    for col in ['gia_ban', 'gia_thi_truong']:
        if col in df.columns:
            df[col] = df[col].apply(clean_price_strict).astype(int)

    # Tính lại các cột tính toán sau khi fix giá
    df['tien_tiet_kiem'] = df['gia_thi_truong'] - df['gia_ban']
    df.loc[df['tien_tiet_kiem'] < 0, 'tien_tiet_kiem'] = 0
    
    df['phan_tram_giam'] = 0
    mask = (df['gia_thi_truong'] > 0) & (df['gia_thi_truong'] > df['gia_ban'])
    cal = (df.loc[mask, 'gia_thi_truong'] - df.loc[mask, 'gia_ban']) / df.loc[mask, 'gia_thi_truong'] * 100
    df.loc[mask, 'phan_tram_giam'] = cal.fillna(0).round().astype(int)

    # 4. ĐIỀN DỮ LIỆU THIẾU
    text_missing_cols = ['thuong_hieu', 'xuat_xu_thuong_hieu', 'noi_san_xuat', 'loai_da', 'danh_muc_day_du', 'loai_san_pham', 'dung_tich']
    for col in text_missing_cols: 
        if col in df.columns: df[col] = df[col].fillna("Unknown")
        else: df[col] = "Unknown"

    # 5. FIX LỖI THÀNH PHẦN BỊ DÍNH (QUAN TRỌNG)
    print("   -> Đang tách lọc Thành phần chi tiết...")
    
    def remove_duplicate_ingredients(row):
        main = str(row['thanh_phan_chinh']).strip()
        full = str(row['thanh_phan_day_du']).strip()
        
        if not main or main.lower() == 'nan': return full
        if not full or full.lower() == 'nan': return ""
        
        # Nếu phần 'Chi tiết' bắt đầu bằng y chang phần 'Chính', thì cắt bỏ
        # Dùng lower() để so sánh không phân biệt hoa thường
        if full.lower().startswith(main.lower()):
            # Cắt bỏ phần trùng (lấy độ dài của main)
            clean_full = full[len(main):].strip()
            # Xóa các ký tự thừa ở đầu sau khi cắt (dấu chấm, phẩy, gạch ngang)
            clean_full = re.sub(r'^[\.\,\-\s]+', '', clean_full).strip()
            return clean_full
        
        return full

    if 'thanh_phan_chinh' in df.columns and 'thanh_phan_day_du' in df.columns:
        # Thay thế cột day_du bằng cột đã làm sạch
        df['thanh_phan_day_du'] = df.apply(remove_duplicate_ingredients, axis=1)

    # 6. FORMAT VĂN BẢN (LÀM ĐẸP UI)
    print("   -> Đang làm đẹp văn bản...")
    def make_text_beautiful(text):
        if not isinstance(text, str): return ""
        text = re.sub(r'([•\-])\s*[\n\r]+\s*', r'\1 ', text)
        text = text.replace('•', '\n•').replace('-', '\n-')
        text = re.sub(r'\s+', ' ', text).strip()
        text = text.replace(' •', '\n•').replace(' -', '\n-')
        return text

    content_cols = ['mo_ta', 'hdsd', 'thanh_phan_chinh', 'thanh_phan_day_du']
    for col in content_cols:
        if col in df.columns: df[col] = df[col].fillna("").apply(make_text_beautiful)

    # 7. CHUẨN HÓA DỮ LIỆU CHO AI
    print("   -> Đang trích xuất thành phần sạch cho AI...")
    def clean_for_ai(text):
        if not isinstance(text, str): return ""
        text = text.lower()
        parts = re.split(r'[•\-\n]+', text)
        clean_parts = []
        for p in parts:
            if ':' in p: p = p.split(':')[0]
            p = p.strip()
            if p: clean_parts.append(p)
        return ", ".join(clean_parts)

    if 'thanh_phan_chinh' in df.columns:
        df['thanh_phan_clean'] = df['thanh_phan_chinh'].apply(clean_for_ai)

    # LƯU FILE
    df.to_csv(OUTPUT_FILE, index=False, encoding='utf-8-sig')
    print(f"✅ [SUCCESS] Xong! Dữ liệu sạch đã lưu tại: {OUTPUT_FILE}")
    print(f"   - Tổng số sản phẩm: {len(df)}")
    print(f"   - Giá mẫu (Check xem còn bị x10 không): {df['gia_ban'].head(3).tolist()}")

if __name__ == "__main__":
    clean_data_pipeline()