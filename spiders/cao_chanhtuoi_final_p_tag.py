import time #ok, cào ok từ 1-187
import csv
import os
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager
from bs4 import BeautifulSoup

# --- CẤU HÌNH ---
FILE_NAME = 'data_chanhtuoi_full_final.csv'
START_PAGE = 1      # Trang bắt đầu
END_PAGE = 500      # Trang kết thúc

def setup_driver():
    print(">>> Đang khởi động Chrome...")
    options = webdriver.ChromeOptions()
    options.add_argument('--disable-gpu')
    options.add_argument('--start-maximized')
    options.add_argument("--disable-notifications")
    
    # Chặn ảnh để load nhanh
    prefs = {"profile.managed_default_content_settings.images": 2}
    options.add_experimental_option("prefs", prefs)
    
    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=options)
    return driver

# --- HÀM LẤY CHI TIẾT ---
def get_product_detail(driver, item_url):
    # Mở tab mới
    driver.execute_script("window.open('');")
    driver.switch_to.window(driver.window_handles[1])
    
    data = {}
    try:
        driver.get(item_url)
        # Cuộn để kích hoạt nội dung
        driver.execute_script("window.scrollTo(0, 1000);")
        time.sleep(1) 
        
        # Click "Xem thêm" nếu có
        try:
            btns = driver.find_elements(By.CSS_SELECTOR, ".ingredient__display-all")
            if btns: driver.execute_script("arguments[0].click();", btns[0])
        except: pass

        soup = BeautifulSoup(driver.page_source, 'lxml')
        
        # Bóc tách
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
    
    # Đóng tab và quay về
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

# --- CHƯƠNG TRÌNH CHÍNH (ĐÃ SỬA NÚT P) ---
def main():
    driver = setup_driver()
    
    if START_PAGE == 1:
        base_url = 'https://beauty.chanhtuoi.com/san-pham'
    else:
        # Nếu start > 1, ta thử vào thẳng trang đó bằng URL query (để tiết kiệm thời gian click)
        # Dựa trên debug của bạn: data-href="...san-pham?page=2" -> Web này dùng query param ?page= cũng được!
        base_url = f'https://beauty.chanhtuoi.com/san-pham?page={START_PAGE}'

    print(f"--- Đang vào: {base_url} ---")
    driver.get(base_url)
    time.sleep(5)

    current_page_num = START_PAGE
    
    while current_page_num <= END_PAGE:
        print(f"\n>>> ĐANG XỬ LÝ TRANG {current_page_num} <<<")
        
        # 1. Cuộn chuột
        driver.execute_script("window.scrollTo(0, document.body.scrollHeight);")
        time.sleep(3)
        
        # 2. Lấy danh sách sản phẩm
        soup = BeautifulSoup(driver.page_source, 'lxml')
        list_data = soup.select_one('#listdata')
        
        products_basic = []
        if list_data:
            items = list_data.find_all('div', recursive=False)
            print(f"    -> Tìm thấy {len(items)} sản phẩm.")
            
            for p in items:
                try:
                    item = {}
                    item['page'] = current_page_num
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
            print("!!! Danh sách rỗng. Thử Refresh...")
            driver.refresh()
            time.sleep(5)
            soup = BeautifulSoup(driver.page_source, 'lxml')
            if not soup.select_one('#listdata > div'):
                print("!!! Vẫn rỗng. Dừng cào.")
                break

        # 3. Vào chi tiết từng sản phẩm
        full_data_page = []
        for i, prod in enumerate(products_basic):
            print(f"    [{i+1}/{len(products_basic)}] Chi tiết: {prod['title'][:30]}...")
            details = get_product_detail(driver, prod['url'])
            prod.update(details)
            full_data_page.append(prod)
        
        # 4. Lưu
        append_to_csv(full_data_page)
        print(f"    [OK] Đã lưu trang {current_page_num}.")

        # 5. CLICK SANG TRANG TIẾP THEO (ĐÃ SỬA ĐỂ TÌM THẺ P)
        next_page_num = current_page_num + 1
        print(f"    -> Đang tìm nút số '{next_page_num}' (Thẻ P)...")
        
        try:
            # --- ĐÂY LÀ CHỖ SỬA QUAN TRỌNG NHẤT ---
            # Tìm thẻ <p> có class 'paginate_item' và chứa số trang tiếp theo
            xpath_p = f"//p[contains(@class, 'paginate_item') and normalize-space()='{next_page_num}']"
            
            next_btn = WebDriverWait(driver, 10).until(
                EC.presence_of_element_located((By.XPATH, xpath_p))
            )
            
            # Cuộn tới nút
            driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", next_btn)
            time.sleep(1)
            
            # Click
            driver.execute_script("arguments[0].click();", next_btn)
            
            print(f"    -> [Thành công] Đã bấm vào nút P số {next_page_num}")
            time.sleep(5) # Đợi trang mới tải
            current_page_num += 1
            
        except Exception as e:
            print(f"!!! Không tìm thấy nút số {next_page_num}. Có thể đã hết trang.")
            # print(f"Lỗi: {e}")
            break

    driver.quit()
    print(f"\n=== HOÀN TẤT! File: {FILE_NAME} ===")

if __name__ == "__main__":
    main()