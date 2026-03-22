import pandas as pd
import matplotlib.pyplot as plt
import seaborn as sns
import numpy as np
from wordcloud import WordCloud
import os

# ==============================================================================
# 1. CẤU HÌNH HỆ THỐNG
# ==============================================================================
INPUT_FILE = 'data_clean_final.csv'
FOLDER_IMG = 'eda_final_report' # Lưu tất cả vào thư mục này

if not os.path.exists(FOLDER_IMG):
    os.makedirs(FOLDER_IMG)

print("🚀 Đang tải dữ liệu và chuẩn bị phân tích toàn diện...")
try:
    df = pd.read_csv(INPUT_FILE)
except FileNotFoundError:
    print(f"❌ Lỗi: Không tìm thấy file '{INPUT_FILE}'")
    exit()

# Cấu hình giao diện chuẩn báo cáo
sns.set_theme(style="whitegrid")
plt.rcParams['figure.figsize'] = (12, 6)

# ==============================================================================
# PHẦN 1: TỔNG QUAN THỊ TRƯỜNG (MARKET OVERVIEW)
# ==============================================================================
print("\n📊 PHẦN 1: TỔNG QUAN THỊ TRƯỜNG")

# --- 1.1 Top 10 Thương hiệu ---
print("   -> Vẽ biểu đồ Top 10 Thương hiệu...")
plt.figure(figsize=(12, 6))
top_brands = df['thuong_hieu'].value_counts().head(10)
sns.barplot(x=top_brands.values, y=top_brands.index, hue=top_brands.index, legend=False, palette="viridis")
plt.title('Top 10 Thương Hiệu Phổ Biến Nhất', fontsize=14)
plt.xlabel('Số lượng sản phẩm')
plt.savefig(f"{FOLDER_IMG}/1_top_brands.png", bbox_inches='tight')
plt.close()

# --- 1.2 Tỷ trọng Xuất xứ ---
print("   -> Vẽ biểu đồ Tỷ trọng Xuất xứ...")
plt.figure(figsize=(8, 8))
top_origins = df['xuat_xu_thuong_hieu'].value_counts().head(8)
colors = sns.color_palette("pastel")[0:8]
plt.pie(top_origins.values, labels=top_origins.index, autopct='%1.1f%%', startangle=140, colors=colors)
plt.title('Cơ Cấu Xuất Xứ Sản Phẩm', fontsize=14)
plt.savefig(f"{FOLDER_IMG}/2_origin_pie.png", bbox_inches='tight')
plt.close()

# ==============================================================================
# PHẦN 2: PHÂN TÍCH CHIẾN LƯỢC GIÁ (PRICE STRATEGY)
# ==============================================================================
print("\n📊 PHẦN 2: PHÂN TÍCH CHIẾN LƯỢC GIÁ")

# --- 2.1 Phân bố giá & Độ lệch ---
print("   -> Vẽ Histogram và tính Skewness...")
plt.figure(figsize=(12, 6))
# Lọc giá ảo/quá cao để biểu đồ đẹp (dưới 3 triệu)
df_price = df[df['gia_ban'] <= 3000000] 
sns.histplot(df_price['gia_ban'], kde=True, color="salmon", bins=40)
plt.title('Phân Bố Giá Bán (Price Distribution)', fontsize=14)
plt.xlabel('Giá bán (VNĐ)')
plt.savefig(f"{FOLDER_IMG}/3_price_distribution.png", bbox_inches='tight')
plt.close()

# Tính toán thống kê để đưa vào báo cáo
price_skew = df['gia_ban'].skew()
median_price = df['gia_ban'].median()
print(f"   [INSIGHT] Giá trung vị: {median_price:,.0f} VNĐ")
print(f"   [INSIGHT] Độ lệch chuẩn giá (Skewness): {price_skew:.2f}")
if price_skew > 1:
    print("      => Lệch PHẢI: Thị trường tập trung vào phân khúc bình dân.")

# --- 2.2 Phân khúc giá theo Quốc gia (Boxplot) ---
print("   -> Vẽ Boxplot so sánh giá các nước...")
top_countries = df['xuat_xu_thuong_hieu'].value_counts().head(5).index
df_top_countries = df[df['xuat_xu_thuong_hieu'].isin(top_countries)]
df_top_countries = df_top_countries[df_top_countries['gia_ban'] < 3000000] # Bỏ outlier

plt.figure(figsize=(12, 6))
sns.boxplot(data=df_top_countries, x='xuat_xu_thuong_hieu', y='gia_ban', palette="Set2", hue='xuat_xu_thuong_hieu', legend=False)
plt.title('Phân Khúc Giá Theo Quốc Gia (Boxplot)', fontsize=14)
plt.ylabel('Giá bán (VNĐ)')
plt.savefig(f"{FOLDER_IMG}/4_price_by_origin_boxplot.png", bbox_inches='tight')
plt.close()

# ==============================================================================
# PHẦN 3: PHÂN TÍCH TƯƠNG QUAN & CHẤT LƯỢNG (CORRELATION)
# ==============================================================================
print("\n📊 PHẦN 3: TƯƠNG QUAN GIÁ & CHẤT LƯỢNG")

# --- 3.1 Scatter Plot (Giá vs Rating) ---
print("   -> Vẽ Scatter Plot...")
plt.figure(figsize=(12, 6))
# Lấy mẫu ngẫu nhiên 1000 sp để vẽ cho thoáng
sample_df = df.sample(n=min(1000, len(df)))
sns.scatterplot(data=sample_df, x='gia_ban', y='diem_danh_gia', hue='diem_danh_gia', palette='coolwarm')
plt.title('Tương Quan: Giá Tiền vs Điểm Đánh Giá', fontsize=14)
plt.xlabel('Giá bán (VNĐ)')
plt.savefig(f"{FOLDER_IMG}/5_price_vs_rating_scatter.png", bbox_inches='tight')
plt.close()

# --- 3.2 Heatmap (Ma trận tương quan) ---
print("   -> Vẽ Heatmap...")
cols_corr = ['gia_ban', 'diem_danh_gia', 'so_luong_danh_gia', 'phan_tram_giam', 'tien_tiet_kiem']
df_corr = df[cols_corr].corr()

plt.figure(figsize=(10, 8))
sns.heatmap(df_corr, annot=True, cmap='coolwarm', fmt=".2f", linewidths=0.5)
plt.title('Ma Trận Tương Quan (Correlation Matrix)', fontsize=14)
plt.savefig(f"{FOLDER_IMG}/6_correlation_heatmap.png", bbox_inches='tight')
plt.close()

correlation_val = df_corr.loc['gia_ban', 'diem_danh_gia']
print(f"   [INSIGHT] Hệ số tương quan Giá - Rating: {correlation_val:.2f}")
if abs(correlation_val) < 0.2:
    print("      => Rất thấp. Kết luận: Giá đắt chưa chắc đã tốt (theo rating).")

# ==============================================================================
# PHẦN 4: PHÂN TÍCH NỘI DUNG SẢN PHẨM (CONTENT)
# ==============================================================================
print("\n📊 PHẦN 4: PHÂN TÍCH THÀNH PHẦN (WORDCLOUD)")

print("   -> Vẽ WordCloud...")
text = " ".join(str(i) for i in df['thanh_phan_clean'].dropna())
if len(text) > 0:
    wordcloud = WordCloud(width=1600, height=800, background_color='white', colormap='ocean').generate(text)
    plt.figure(figsize=(15, 8))
    plt.imshow(wordcloud, interpolation='bilinear')
    plt.axis('off')
    plt.title('Các Hoạt Chất Phổ Biến Nhất Trong Mỹ Phẩm', fontsize=20)
    plt.savefig(f"{FOLDER_IMG}/7_ingredient_wordcloud.png", bbox_inches='tight')
    plt.close()
else:
    print("   ⚠️ Không đủ dữ liệu text để vẽ WordCloud.")

print(f"\n✅ [HOÀN TẤT] Đã tạo xong 7 biểu đồ phân tích sâu trong thư mục '{FOLDER_IMG}'.")