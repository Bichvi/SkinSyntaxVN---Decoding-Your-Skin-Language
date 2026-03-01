import time #0k - từ 188-300
import csv
import os
import pandas as pd
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from webdriver_manager.chrome import ChromeDriverManager
from bs4 import BeautifulSoup

# --- CẤU HÌNH ---
FILE_NAME = 'data_chanhtuoi_tu_khoa_da.csv'
START_PAGE = 322    # Bắt đầu từ trang 188
END_PAGE = 1000     # Cào đến 1000 trang (hoặc hết thì tự dừng)

def setup_driver():
    print(">>> Đang khởi động Chrome...")
    options = webdriver.ChromeOptions()
    options.add_argument('--disable-gpu')
    options.add_argument('--start-maximized')
    options.add_argument("--disable-notifications")
    
    # Chặn ảnh để load siêu tốc
    prefs = {"profile.managed_default_content_settings.images": 2}
    options.add_experimental_option("prefs", prefs)
    
    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=options)
    return driver

# --- HÀM LẤY CHI TIẾT ---
def get_product_detail(driver, item_url):
    driver.execute_script("window.open('');")
    driver.switch_to.window(driver.window_handles[1])
    
    data = {}
    try:
        driver.get(item_url)
        driver.execute_script("window.scrollTo(0, 1000);")
        time.sleep(1) 
        
        try:
            btns = driver.find_elements(By.CSS_SELECTOR, ".ingredient__display-all")
            if btns: driver.execute_script("arguments[0].click();", btns[0])
        except: pass

        soup = BeautifulSoup(driver.page_source, 'lxml')
        
        # Bóc tách dữ liệu
        cat_div = soup.select_one(r'div.bg-\[\#D6D5FF\]')
        data['category'] = cat_div.get_text(strip=True) if cat_div else ""

        ing_container = soup.select_one('.ingredient__list')
        if ing_container:
            ings = [a.get_text(strip=True).rstrip(',') for a in ing_container.find_all('a')]
            data['ingredients'] = ", ".join(ings)
        else:
            data['ingredients'] = ""

        analysis_items = soup.find_all(class_="show-modal-info")
        analyses = [item.get('data-title') for item in analysis_items if item.get('data-title')]
        data['quick_analysis'] = " | ".join(analyses)

        data['link_shopee'] = ""
        data['link_lazada'] = ""
        for link in soup.find_all('a', href=True):
            if 'shopee' in link['href']: data['link_shopee'] = link['href']
            elif 'lazada' in link['href']: data['link_lazada'] = link['href']

        ewg_bars = soup.select('.rounded-full .flex.items-center.justify-center')
        ewg_data = []
        for bar in ewg_bars:
            text = bar.get_text(strip=True)
            if "3db34b" in str(bar): ewg_data.append(f"Thấp ({text})")
            elif "fcaf3e" in str(bar): ewg_data.append(f"Vừa ({text})")
            elif "ec1f2b" in str(bar): ewg_data.append(f"Cao ({text})")
        data['safety_score'] = " - ".join(ewg_data)

    except Exception:
        pass
    
    driver.close()
    driver.switch_to.window(driver.window_handles[0])
    return data

def append_to_csv(data_list):
    file_exists = os.path.isfile(FILE_NAME)
    field_names = ['page', 'title', 'brand', 'category', 'url', 'image_url', 
                   'ingredients', 'quick_analysis', 'safety_score', 'link_shopee', 'link_lazada']
    
    with open(FILE_NAME, 'a', newline='', encoding='utf-8-sig') as f:
        writer = csv.DictWriter(f, fieldnames=field_names)
        if not file_exists: writer.writeheader()
        for item in data_list:
            filtered_item = {k: item.get(k, '') for k in field_names}
            writer.writerow(filtered_item)

# --- CHƯƠNG TRÌNH CHÍNH (KEYWORD 'DA' + LANG=VI) ---
def main():
    driver = setup_driver()
    
    try:
        last_first_product = ""

        for page in range(START_PAGE, END_PAGE + 1):
            # --- URL CHUẨN XÁC: keyword=da & lang=vi ---
            # Từ khóa "da" (Skin) bao phủ 99% sản phẩm mỹ phẩm
            url = f'https://beauty.chanhtuoi.com/tim-kiem-san-pham/da?keyword=da&lang=vi&page={page}'
            print(f"\n>>> ĐANG XỬ LÝ TRANG {page}: {url}")
            
            driver.get(url)
            time.sleep(3) 
            driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
            time.sleep(2)
            
            soup = BeautifulSoup(driver.page_source, 'lxml')
            list_data = soup.select_one('#listdata')
            
            products_basic = []
            if list_data:
                items = list_data.find_all('div', recursive=False)
                
                # Check trùng lặp
                if items:
                    current_first_title = items[0].select_one('a[class*="line-clamp-3"]').get_text(strip=True)
                    if current_first_title == last_first_product:
                        print("!!! CẢNH BÁO: Dữ liệu bị trùng (Web chưa nhảy trang). Dừng lại.")
                        break
                    last_first_product = current_first_title

                print(f"    -> Tìm thấy {len(items)} sản phẩm.")
                
                for p in items:
                    try:
                        item = {}
                        item['page'] = page
                        link_tag = p.select_one('a[class*="line-clamp-3"]')
                        if not link_tag: continue
                        
                        item['title'] = link_tag.get_text(strip=True)
                        href = link_tag['href']
                        item['url'] = 'https://beauty.chanhtuoi.com' + href if not href.startswith('http') else href
                        
                        brand_tag = p.select_one('a.uppercase')
                        item['brand'] = brand_tag.get_text(strip=True) if brand_tag else ""
                        
                        img_tag = p.select_one('img')
                        item['image_url'] = img_tag.get('src') or img_tag.get('data-src') if img_tag else ""
                        
                        products_basic.append(item)
                    except: continue
            
            if not products_basic:
                print("!!! Danh sách rỗng. Có thể đã hết trang hoặc từ khóa 'da' không còn kết quả.")
                break

            # Cào chi tiết
            full_data_page = []
            for i, prod in enumerate(products_basic):
                print(f"    [{i+1}/{len(products_basic)}] {prod['title'][:40]}...")
                details = get_product_detail(driver, prod['url'])
                prod.update(details)
                full_data_page.append(prod)
            
            # Lưu
            append_to_csv(full_data_page)
            print(f"    [OK] Đã lưu xong trang {page}.")

    except KeyboardInterrupt:
        print("\n!!! Bạn đã dừng chương trình.")
    except Exception as e:
        print(f"Lỗi: {e}")
    finally:
        driver.quit()
        print(f"\n=== HOÀN TẤT! File: {FILE_NAME} ===")

if __name__ == "__main__":
    main()