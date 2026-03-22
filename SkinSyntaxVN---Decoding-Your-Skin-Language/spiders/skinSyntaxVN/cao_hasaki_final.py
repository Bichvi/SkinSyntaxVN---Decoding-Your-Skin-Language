import time #ok lấy cái này 
import csv
import os
import re
import json
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from webdriver_manager.chrome import ChromeDriverManager
from bs4 import BeautifulSoup

# --- CẤU HÌNH ---
FILE_NAME = 'data_hasaki_v9_complete.csv'
START_PAGE = 1
END_PAGE = 112 
BASE_URL = "https://hasaki.vn/danh-muc/suc-khoe-lam-dep-c3.html?limit=60&p={}"



def setup_driver():
    print(">>> Đang khởi động Chrome...")
    options = webdriver.ChromeOptions()
    options.add_argument('--disable-gpu')
    options.add_argument('--start-maximized')
    options.add_argument("--disable-notifications")
    options.add_argument("user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
    prefs = {"profile.managed_default_content_settings.images": 2}
    options.add_experimental_option("prefs", prefs)
    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=options)
    return driver

# --- XỬ LÝ VĂN BẢN ---
def clean_text_content(html_element):
    if not html_element: return ""
    for br in html_element.find_all("br"): br.replace_with("\n")
    for p in html_element.find_all("p"): p.insert_after("\n")
    for li in html_element.find_all("li"): 
        li.insert_before("• ") 
        li.insert_after("\n")
    return "\n".join([line.strip() for line in html_element.get_text().split('\n') if line.strip()])

def split_ingredients(text):
    if not text: return "", ""
    lower_text = text.lower()
    idx_main = lower_text.find("thành phần chính")
    idx_full = lower_text.find("thành phần đầy đủ")
    if idx_full == -1: idx_full = lower_text.find("thành phần chi tiết")

    main_ing, full_ing = "", ""
    if idx_main != -1 and idx_full != -1:
        if idx_main < idx_full:
            main_ing = text[idx_main:idx_full]
            full_ing = text[idx_full:]
        else:
            full_ing = text[idx_full:idx_main]
            main_ing = text[idx_main:]
    elif idx_main != -1: main_ing = text[idx_main:]
    elif idx_full != -1: full_ing = text[idx_full:]
    else: full_ing = text

    main_ing = re.sub(r'^(thành phần chính)[:\s]*', '', main_ing.strip(), flags=re.IGNORECASE).strip()
    full_ing = re.sub(r'^(thành phần (đầy đủ|chi tiết))[:\s]*', '', full_ing.strip(), flags=re.IGNORECASE).strip()
    return main_ing, full_ing

def extract_numbers(text):
    if not text: return ""
    return re.sub(r'\D', '', text) 

# --- HÀM TRÍCH XUẤT DANH MỤC (V9 - MỚI) ---
def extract_breadcrumb_v9(soup):
    full_path = ""
    last_category = ""
    
    try:
        # Tìm thẻ <ol> chứa các thẻ <li>
        # Vì class tailwind hay đổi, ta tìm thẻ ol có chứa thẻ a có text 'Trang chủ'
        target_ol = None
        all_ols = soup.find_all('ol')
        for ol in all_ols:
            if "Trang chủ" in ol.get_text():
                target_ol = ol
                break
        
        if target_ol:
            # Lấy tất cả thẻ a trong ol này
            links = target_ol.find_all('a')
            path_items = [l.get_text(strip=True) for l in links if l.get_text(strip=True)]
            
            # Xử lý list:
            # 1. Bỏ "Trang chủ" (thường là phần tử đầu tiên)
            if path_items and path_items[0] == "Trang chủ":
                path_items.pop(0)
            
            # 2. Bỏ "Tên sản phẩm" (thường là phần tử cuối cùng)
            # Tên sản phẩm thường dài > 50 ký tự hoặc trùng với H1
            if path_items:
                path_items.pop() 

            if path_items:
                # 3. Lấy kết quả
                full_path = " -> ".join(path_items) # Chuỗi: Sức Khỏe -> Chăm Sóc Da -> ...
                last_category = path_items[-1]      # Cái cuối cùng: Tẩy Trang Mặt
                
    except Exception as e:
        # print(f"Breadcrumb Error: {e}")
        pass
        
    return full_path, last_category

# --- HÀM BÓC TÁCH CHI TIẾT ---
def extract_hasaki_detail(driver, url):
    data = {}
    data['url_san_pham'] = url
    
    try:
        driver.get(url)
        driver.execute_script("window.scrollTo(0, 500);")
        time.sleep(0.3)
        driver.execute_script("window.scrollTo(0, 1500);")
        time.sleep(0.3)
        driver.execute_script("window.scrollTo(0, document.body.scrollHeight - 1200);")
        time.sleep(0.5)

        soup = BeautifulSoup(driver.page_source, 'lxml')

        # 1. DANH MỤC & PHÂN LOẠI (V9)
        danh_muc, loai_sp = extract_breadcrumb_v9(soup)
        data['danh_muc_day_du'] = danh_muc
        data['loai_san_pham'] = loai_sp

        # 2. CƠ BẢN
        h1 = soup.find('h1')
        data['ten_san_pham'] = h1.get_text(strip=True) if h1 else ""
        sku_div = soup.find(string=lambda t: t and "Mã sản phẩm:" in t)
        data['ma_san_pham'] = sku_div.replace("Mã sản phẩm:", "").strip().strip("|") if sku_div else ""

        # 3. GIÁ
        price_sale = soup.select_one('.text-orange.text-base.font-bold') or soup.select_one('#product_price')
        data['gia_ban'] = extract_numbers(price_sale.get_text()) if price_sale else ""

        data['gia_thi_truong'] = ""
        data['tien_tiet_kiem'] = ""
        data['phan_tram_giam'] = ""
        market_info = soup.select_one('.text-sm.mb-2')
        if market_info:
            raw_text = market_info.get_text(strip=True)
            if "Giá thị trường:" in raw_text:
                matches = re.findall(r'([\d\.]+)', raw_text)
                if len(matches) >= 1: data['gia_thi_truong'] = extract_numbers(matches[0])
                if len(matches) >= 2: data['tien_tiet_kiem'] = extract_numbers(matches[1])
                if len(matches) >= 3: data['phan_tram_giam'] = matches[-1] + "%"

        # 4. THÔNG SỐ
        for k in ['barcode', 'thuong_hieu', 'xuat_xu_thuong_hieu', 'noi_san_xuat', 'dung_tich', 'loai_da']:
            data[k] = ""
        spec_table = soup.select_one('#SpecificationInfo table')
        if spec_table:
            for row in spec_table.find_all('tr'):
                cols = row.find_all('td')
                if len(cols) == 2:
                    key = cols[0].get_text(strip=True).lower()
                    val = cols[1].get_text(strip=True)
                    if 'barcode' in key: data['barcode'] = val.replace("'", "").replace("&#39;", "").strip()
                    elif 'xuất xứ' in key: data['xuat_xu_thuong_hieu'] = val
                    elif 'sản xuất' in key: data['noi_san_xuat'] = val
                    elif 'thương hiệu' in key: data['thuong_hieu'] = val
                    elif 'dung tích' in key or 'trọng lượng' in key: data['dung_tich'] = val
                    elif 'loại da' in key: data['loai_da'] = val

        # 5. NỘI DUNG
        data['mo_ta'] = clean_text_content(soup.select_one('#DescriptionInfo'))
        data['hdsd'] = clean_text_content(soup.select_one('#GuideInfo'))
        raw_ing = clean_text_content(soup.select_one('#IngredientInfo'))
        main_ing, full_ing = split_ingredients(raw_ing)
        data['thanh_phan_chinh'] = main_ing
        data['thanh_phan_day_du'] = full_ing

        # 6. HÌNH ẢNH
        imgs = []
        main_img = soup.select_one(f'img[alt="{data["ten_san_pham"]}"]')
        if main_img and "http" in main_img.get('src', ''): imgs.append(main_img['src'])
        for img in soup.find_all('img'):
            src = img.get('src')
            if src and "http" in src and "media.hcdn.vn" in src:
                if "data:image" not in src and "icon" not in src and "rating" not in src:
                    if src not in imgs: imgs.append(src)
        data['link_hinh_anh'] = " | ".join(imgs)

        # 7. ĐÁNH GIÁ & SỐ LƯỢNG
        data['diem_danh_gia'] = "0"
        data['so_luong_danh_gia'] = "0"
        rating_box = soup.find(id="ShortRatingInfo")
        if rating_box:
            score_tag = rating_box.find('div', class_=lambda c: c and 'text-[32px]' in c)
            if score_tag: data['diem_danh_gia'] = score_tag.get_text(strip=True)
            count_tags = rating_box.find_all(string=re.compile(r'đánh giá'))
            for txt in count_tags:
                num = extract_numbers(txt)
                if num and num != '0': 
                    data['so_luong_danh_gia'] = num
                    break

        # 8. REVIEW & QA
        reviews = []
        for c in (soup.select('.item_comment') or soup.select('.mb-4.border-b'))[:5]:
            try:
                u = c.select_one('.author_name') or c.select_one('.font-bold.inline-block')
                t = c.select_one('.content_comment') or c.select_one('p.leading-\[1\.4\].mb-1')
                if t: reviews.append(f"[{u.get_text(strip=True) if u else 'Khách'}]: {t.get_text(strip=True)}")
            except: pass
        data['noi_dung_danh_gia'] = "\n".join(reviews)

        qa_list = []
        if soup.find(id="ShortCommentInfo"):
            for qa in soup.select('#ShortCommentInfo .text-\[13px\].pb-2.5'):
                t = qa.find('p', class_=lambda c: c and 'word-break' in c)
                if t: qa_list.append(t.get_text(strip=True))
        data['hoi_dap'] = "\n".join(qa_list)

    except Exception: pass
    return data

# --- GHI FILE ---
def append_to_csv(data_list):
    file_exists = os.path.isfile(FILE_NAME)
    field_names = [
        'ten_san_pham', 
        'danh_muc_day_du',  # Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Tẩy Trang Mặt
        'loai_san_pham',    # Tẩy Trang Mặt
        'gia_ban', 'gia_thi_truong', 'tien_tiet_kiem', 'phan_tram_giam',
        'diem_danh_gia', 'so_luong_danh_gia',
        'thuong_hieu', 'xuat_xu_thuong_hieu', 'noi_san_xuat', 'dung_tich', 'loai_da',
        'barcode', 'ma_san_pham', 'link_hinh_anh', 
        'mo_ta', 'thanh_phan_chinh', 'thanh_phan_day_du', 'hdsd', 
        'noi_dung_danh_gia', 'hoi_dap', 'url_san_pham'
    ]
    with open(FILE_NAME, 'a', newline='', encoding='utf-8-sig') as f:
        writer = csv.DictWriter(f, fieldnames=field_names)
        if not file_exists: writer.writeheader()
        for item in data_list:
            writer.writerow({k: item.get(k, '') for k in field_names})

def main():
    if START_PAGE == 1 and os.path.exists(FILE_NAME): 
        try: os.remove(FILE_NAME)
        except: pass
    driver = setup_driver()
    try:
        for page in range(START_PAGE, END_PAGE + 1):
            driver.get(BASE_URL.format(page))
            print(f"\n>>> ĐANG QUÉT TRANG {page}...")
            time.sleep(3)
            
            links = []
            try:
                for el in driver.find_elements(By.CSS_SELECTOR, "a[href*='/san-pham/']"):
                    h = el.get_attribute('href')
                    if h and '.html' in h and h not in links: links.append(h)
            except: pass
            
            print(f"    -> Tìm thấy {len(links)} sản phẩm.")
            if not links: break

            full_data = []
            for i, link in enumerate(links):
                print(f"    [{i+1}/{len(links)}] Cào: {link.split('/')[-1][:30]}...")
                driver.execute_script("window.open('');")
                driver.switch_to.window(driver.window_handles[1])
                full_data.append(extract_hasaki_detail(driver, link))
                driver.close()
                driver.switch_to.window(driver.window_handles[0])
            
            append_to_csv(full_data)
            print(f"    [OK] Đã lưu xong trang {page}.")
    except Exception as e: print(f"Lỗi: {e}")
    finally: driver.quit()

if __name__ == "__main__":
    main()