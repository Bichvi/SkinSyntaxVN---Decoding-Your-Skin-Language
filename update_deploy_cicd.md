# Deploy SkinSyntaxVN lên VPS kèm CI/CD tự động (GitHub Actions)

Cập nhật 2026-11-09. Chưa commit. Chưa deploy thật lần nào: các file đã được kiểm tra trên máy dev (xem mục 8), còn bước chạy trên VPS và GitHub Actions thật thì chưa.

## 0. Toàn cảnh

```
Khách ──HTTPS──> Caddy (80/443, tự xin chứng chỉ) ──> nginx ──> PHP-FPM ──> Flask AI / AI Idol / Redis / Neo4j / Chroma
                                                                     └──> MongoDB Atlas (ngoài VPS)

git push main ──> GitHub Actions: kiểm tra (php -l, compileall, compose config)
                               └─ đạt ──> SSH vào VPS ──> scripts/deploy.sh (git pull → build → restart → kiểm tra trang chủ)
```

| File | Vai trò |
| --- | --- |
| `docker-compose.prod.yml` | Ghép thêm vào `docker-compose.yml` khi chạy trên VPS: đóng các cổng nội bộ, thêm Caddy, cho phép tắt chế độ mail demo |
| `Caddyfile` | HTTPS cho `DOMAIN`, chuyển `www.` về tên miền chính, chat stream không bị gom bộ đệm |
| `nginx/proxy.d/real-ip.conf` | Chỉ nạp ở production: nginx lấy IP thật của khách từ Caddy |
| `nginx/nginx.conf` | Thêm báo `HTTPS=on` cho PHP khi đi qua Caddy. Local không đổi hành vi |
| `scripts/deploy.sh` | Chạy trên VPS: kéo code, build, restart phần đổi, chờ trang chủ lên |
| `.github/workflows/deploy.yml` | CI cho mọi push/PR, CD khi push lên `main` |

## 1. Thuê VPS và trỏ tên miền

- **Cấu hình:** Ubuntu 24.04, **từ 8 GB RAM (nên dùng 16 GB)**, 4 vCPU, ổ đĩa ≥ 80 GB. Đo trên máy dev: stack dùng ~6,3 GB RAM; các image cộng lại > 15 GB.
- **Tên miền:** ở trang quản lý DNS, tạo bản ghi `A` cho `@` → IP của VPS, và `A` cho `www` → cùng IP. Nếu không dùng `www`, xóa khối `www.{$DOMAIN}` trong `Caddyfile`.

## 2. Chuẩn bị VPS (làm một lần)

SSH vào VPS bằng tài khoản root mà nhà cung cấp đưa:

```bash
ssh root@<IP_VPS>
```

Tạo user `deploy`, cài Docker, bật tường lửa:

```bash
adduser --disabled-password --gecos "" deploy
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw allow 443/udp && ufw --force enable
```

Tạo swap 4 GB (build image AI tốn RAM, swap giúp không bị kill giữa chừng):

```bash
fallocate -l 4G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

Cho phép đăng nhập user `deploy` bằng key SSH của máy bạn (để copy file ở bước 3):

```bash
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
```

Chuyển sang user `deploy` và clone repo (repo public nên không cần key GitHub):

```bash
su - deploy
git clone https://github.com/Bichvi/SkinSyntaxVN---Decoding-Your-Skin-Language.git ~/skinsyntax
```

## 3. Đưa dữ liệu không có trong git lên VPS

Chạy trên **máy Windows của bạn** (Git Bash), trong thư mục dự án:

```bash
scp .env deploy@<IP_VPS>:~/skinsyntax/.env
scp -r database/recommendation_index deploy@<IP_VPS>:~/skinsyntax/database/
```

- `.env` chứa khóa bí mật: chỉ copy qua `scp`, **không bao giờ commit**.
- `database/recommendation_index` (8 MB) bị gitignore nhưng Flask cần. `database/chroma_db` thì **không** cần: trong Docker, Chroma chạy thành service riêng và được nạp lại ở bước 6.

## 4. Sửa `.env` trên VPS cho production

```bash
nano ~/skinsyntax/.env
```

| Biến | Giá trị production |
| --- | --- |
| `DOMAIN` | `skinsyntax.vn` (tên miền của bạn, không có `https://`) |
| `APP_URL` | `https://skinsyntax.vn` |
| `NEO4J_PASSWORD` | mật khẩu mạnh mới |
| `GOOGLE_OAUTH_REDIRECT_URI` | `https://skinsyntax.vn/index.php?r=auth_social_callback...` (đúng theo giá trị đang dùng, đổi phần đầu thành tên miền) |
| `FACEBOOK_OAUTH_REDIRECT_URI` | tương tự |
| `VNPAY_RETURN_URL` | đổi sang tên miền mới |
| `MAIL_DEMO_MODE` | `0` nếu muốn gửi mail thật (cần `SMTP_*` đúng), để `1` thì chỉ ghi log |
| `CHAT_AGENT_TOKEN_SECRET`, `CHAT_AGENT_INTERNAL_SECRET`, `SEARCH_INTERNAL_TOKEN`, `AI_IDOL_INTERNAL_TOKEN` | tạo giá trị mới cho production: `openssl rand -hex 32` |

Ngoài VPS, cần làm thêm:
- **MongoDB Atlas → Network Access:** thêm IP của VPS.
- **Google Cloud Console / Facebook Developers:** thêm redirect URI mới.
- **VNPay:** cập nhật URL trả về nếu dùng thanh toán thật.

## 5. Chạy lần đầu

```bash
cd ~/skinsyntax
bash scripts/deploy.sh
```

Lần đầu phải build mọi image (tải torch và model), mất khoảng **20–60 phút** tùy VPS. Những lần sau chỉ build phần thay đổi. Khi thấy `[deploy] Xong`, mở `https://<tên miền>` để kiểm tra; Caddy tự xin chứng chỉ trong vài giây đầu.

## 6. Nạp dữ liệu tìm kiếm (một lần sau lần chạy đầu)

Volume Chroma và Neo4j trên VPS đang trống. Nạp từ Atlas:

```bash
docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --run-now chroma_reindex
docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --run-now graph_sync_full
```

Sau đó `catalog-worker` tự đồng bộ theo lịch (xem `docs/ops/search-ops.md`).

## 7. Bật CI/CD trên GitHub

**7.1. Tạo key SSH riêng cho GitHub Actions** (chạy trên VPS, user `deploy`):

```bash
ssh-keygen -t ed25519 -N "" -C "github-actions-deploy" -f ~/deploy_key
cat ~/deploy_key.pub >> ~/.ssh/authorized_keys
cat ~/deploy_key
```

Copy toàn bộ nội dung vừa in ra (gồm cả dòng `-----BEGIN` và `-----END`), rồi xóa file: `rm ~/deploy_key ~/deploy_key.pub`.

**7.2. Lấy dấu vân tay của VPS** (chạy trên máy bạn):

```bash
ssh-keyscan -H <IP_VPS>
```

**7.3. Thêm Secrets:** GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Giá trị |
| --- | --- |
| `VPS_HOST` | IP của VPS |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | private key ở 7.1 |
| `VPS_KNOWN_HOSTS` | kết quả ở 7.2 |

**7.4. Đưa code lên `main`.** Workflow chỉ deploy từ `main`. Khi gộp nhánh `syna` vào `main` và push, GitHub tự chạy: tab **Actions** → workflow **CI/CD** → job **Kiểm tra** rồi **Deploy lên VPS**. Muốn deploy lại mà không có commit mới: tab Actions → CI/CD → **Run workflow**.

## 8. Đã kiểm tra trên máy dev

| Kiểm tra | Kết quả |
| --- | --- |
| `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` | Hợp lệ; chỉ Caddy mở cổng ra ngoài (80/443), Neo4j và ai-idol chỉ nghe `127.0.0.1` |
| `docker compose config` (local, không có prod) | Vẫn hợp lệ, không đổi |
| `nginx -t` local (không có `proxy.d`) và production (có `proxy.d`) | Cả hai thành công |
| `caddy validate` | Hợp lệ |
| Chuỗi thật Caddy → nginx → PHP-FPM (container tạm, image `skinsyntax-php-backend`) | Qua Caddy: PHP thấy `HTTPS=on`, `REMOTE_ADDR` là IP khách (không phải IP Caddy); IP giả trong `X-Forwarded-For` bị Caddy bỏ. Vào thẳng nginx (như local): `HTTPS` không đặt, giống trước |
| Bước kiểm tra của CI chạy local | `php -l` 352 file: 0 lỗi; biên dịch 431 file Python: 0 lỗi |
| `bash -n scripts/deploy.sh` | Đạt |

**Chưa kiểm tra:** chạy `deploy.sh` trên VPS thật, GitHub Actions thật, chứng chỉ Let's Encrypt thật (cần tên miền đã trỏ về VPS).

## 9. Giới hạn và lưu ý

- **CI chỉ kiểm tra cú pháp,** chưa chạy `pytest`: test Flask/AI Idol cần cài torch (vài GB) và 53 test ai-idol gọi `get_db()` thật. Có thể thêm sau với `MONGO_URI` để trống.
- **Video avatar AI Idol** (SadTalker/Wav2Lip, cổng 7861/7862) chạy ngoài Docker và cần GPU, nên trên VPS phần render video avatar sẽ không chạy. Các phần còn lại của AI Idol vẫn chạy.
- **Container `mongodb`** vẫn chạy (để thỏa `depends_on`) nhưng không dùng vì dữ liệu ở Atlas; tốn khoảng 300 MB RAM. Có thể bỏ sau.
- **Lúc deploy,** service nào có code đổi sẽ được restart, nên gián đoạn vài giây. Riêng `ai-service` cần khoảng 30–60 giây để nạp model, trong lúc đó chat Syna tạm lỗi.
- **Rollback:** `git revert <commit lỗi>` trên `main` rồi push; GitHub Actions tự deploy bản đã hoàn tác.
- **Log:** `docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f --tail 100 php-backend nginx caddy`.

---

## Phụ lục A. Tạo VPS miễn phí trên Oracle Cloud + tên miền DuckDNS

Thay cho mục 1 và mục 2 ở trên. Từ mục 3 trở đi làm như cũ, chỉ thay user `deploy` bằng **`ubuntu`**.

Giao diện Oracle thay đổi theo thời gian nên tên nút có thể hơi khác; làm theo ý nghĩa của từng bước.

### A1. Đăng ký tài khoản

1. Vào https://www.oracle.com/cloud/free/ → **Start for free**.
2. Chọn quốc gia **Vietnam**, điền tên và email, xác minh email, đặt mật khẩu.
3. **Home Region:** chọn kỹ vì **không đổi được về sau**, và máy miễn phí chỉ tạo được ở vùng này. Vùng gần Việt Nam là Singapore, Tokyo, Osaka, Seoul, Chuncheon. Vùng đông người dùng (như Singapore) hay hết máy ARM miễn phí; vùng ít người thường dễ tạo hơn, nhưng không có gì đảm bảo.
4. Nhập thẻ Visa/Mastercard để xác minh. Oracle giữ tạm một khoản nhỏ rồi hoàn lại. Tài khoản Free **không bị trừ tiền** trừ khi bạn tự nâng cấp lên trả phí và dùng vượt hạn mức miễn phí. Thẻ ảo hoặc thẻ trả trước hay bị từ chối.

### A2. Tạo SSH key trên máy bạn

Mở Git Bash trên Windows. Nếu đã có file `~/.ssh/id_ed25519.pub` thì bỏ qua lệnh đầu.

```bash
ssh-keygen -t ed25519 -C "oracle-vps"
cat ~/.ssh/id_ed25519.pub
```

Copy dòng bắt đầu bằng `ssh-ed25519 ...` (đây là **public key**, đưa cho người khác được). File `id_ed25519` không có `.pub` là **private key**: không bao giờ gửi đi.

### A3. Tạo máy ảo

Menu ☰ → **Compute → Instances → Create instance**:

| Mục | Chọn |
| --- | --- |
| Name | `skinsyntax` |
| Placement | AD-1; nếu báo hết máy thì thử AD-2 hoặc AD-3 |
| Image | **Change image → Ubuntu → Canonical Ubuntu 24.04** (bản thường, không phải Minimal) |
| Shape | **Change shape → Ampere → VM.Standard.A1.Flex**, kéo **OCPU = 4**, **Memory = 24 GB**. Phải có nhãn *Always Free-eligible* |
| Networking | Create new virtual cloud network, create new **public** subnet, **Assign a public IPv4 address** |
| Add SSH keys | **Paste public keys**, dán dòng ở bước A2 |
| Boot volume | Tick **Specify a custom boot volume size** → **150 GB** (gói miễn phí cho tổng 200 GB) |

Bấm **Create**. Khi máy chuyển sang **RUNNING**, ghi lại **Public IP address**.

**Nếu báo `Out of capacity`:** thử AD khác; hoặc thử lại sau vài giờ (sáng sớm thường dễ hơn); hoặc tạm giảm còn 2 OCPU / 12 GB (vẫn chạy được, nhưng sát RAM khi build). Theo kinh nghiệm cộng đồng, nâng tài khoản lên *Pay As You Go* giúp dễ tạo máy hơn. Các tài nguyên Always Free vẫn miễn phí, nhưng **dùng vượt hạn mức sẽ bị tính tiền**, nên chỉ làm khi bạn chắc chắn.

### A4. Mở cổng 80/443 (Oracle chặn ở 2 lớp)

**Lớp 1, tường lửa mạng của Oracle:** trang instance → mục **Primary VNIC** → bấm vào tên **Subnet** → **Security Lists** → **Default Security List** → **Add Ingress Rules**:

| Source CIDR | IP Protocol | Destination Port Range |
| --- | --- | --- |
| `0.0.0.0/0` | TCP | `80,443` |
| `0.0.0.0/0` | UDP | `443` |

**Lớp 2, iptables bên trong Ubuntu.** Ảnh Ubuntu của Oracle chặn sẵn mọi cổng trừ 22. Làm bước này **trước khi cài Docker**: nếu lưu iptables sau khi Docker chạy thì luật của Docker cũng bị lưu theo.

```bash
ssh ubuntu@<IP_VPS>
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p udp --dport 443 -j ACCEPT
sudo netfilter-persistent save
```

**Không bật `ufw` trên Oracle** (bỏ dòng `ufw` ở mục 2): nó xung đột với bộ iptables Oracle cài sẵn.

### A5. Cài Docker và clone repo

Vẫn trong phiên SSH:

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
exit
```

Đăng nhập lại để quyền Docker có hiệu lực, rồi clone repo:

```bash
ssh ubuntu@<IP_VPS>
docker ps
git clone https://github.com/Bichvi/SkinSyntaxVN---Decoding-Your-Skin-Language.git ~/skinsyntax
```

`docker ps` chạy không báo lỗi quyền là được. Với 24 GB RAM thì không cần tạo swap.

### A6. Tên miền miễn phí DuckDNS

1. Vào https://www.duckdns.org → đăng nhập bằng GitHub hoặc Google.
2. Ô **sub domain** gõ tên, ví dụ `skinsyntax` → **add domain** (nếu tên đã có người dùng thì chọn tên khác).
3. Ô **current ip** dán Public IP của Oracle → **update ip**.
4. Kiểm tra trên máy bạn: `nslookup skinsyntax.duckdns.org` phải trả về đúng IP đó.

Trong `.env` trên VPS (mục 4):

```
DOMAIN=skinsyntax.duckdns.org
APP_URL=https://skinsyntax.duckdns.org
```

DuckDNS tự trỏ mọi tên con (vd. `www.skinsyntax.duckdns.org`) về cùng IP, nên không cần sửa `Caddyfile`. Public IP của Oracle giữ nguyên chừng nào máy còn; nếu xóa rồi tạo lại máy thì cập nhật IP mới trên DuckDNS.

### A7. Tiếp theo

Làm tiếp mục 3 → 7 ở trên, thay `deploy@<IP_VPS>` bằng `ubuntu@<IP_VPS>`, và ở GitHub đặt `VPS_USER` = `ubuntu`.

**Lưu ý riêng cho Oracle:**
- Oracle có thể thu hồi máy Always Free bị coi là nhàn rỗi trong 7 ngày (CPU, mạng và với máy A1 là cả RAM đều dưới 20%). Stack này dùng khoảng 6 GB trên 24 GB (khoảng 26%) nên thường không bị coi là nhàn rỗi, nhưng đừng tắt bớt service lâu ngày.
- Máy là chip ARM: lần build đầu tải bản ARM của các thư viện (xem mục A8).

### A8. Chạy được trên chip ARM không (đã kiểm tra 2026-11-10)

| Thành phần | Kết quả |
| --- | --- |
| Image nền: `chromadb/chroma:0.5.20`, `neo4j:5.26-community`, `mongo:7`, `redis:7-alpine`, `composer:2` | Có bản `arm64` (đọc manifest) |
| `nginx`, `caddy`, `python:3.11-slim`, `php:8.2-fpm-alpine` | Image chính thức đa kiến trúc. Lần kiểm tra manifest bị lỗi mạng, chưa đọc lại được |
| Thư viện Python của `ai-service-flask`, `ai-idol-service`, `ai-idol-agent-service`, `voice-service` | Cả 4 bộ giải được **chỉ bằng wheel `aarch64` có sẵn** (`pip install --dry-run --only-binary=:all: --platform manylinux…aarch64`, chạy trong container Linux Python 3.11). Gồm torch 2.15 CPU, onnxruntime, tokenizers, numpy, piper-tts: không phải biên dịch |

**Chưa kiểm tra:** build và chạy thật trên máy ARM. `requirements.txt` không ghim phiên bản, nên trên VPS pip sẽ cài bản mới nhất, có thể khác bản đang chạy trên máy dev.

---

## Phụ lục B. Link public miễn phí bằng Cloudflare Tunnel (đang dùng, 2026-11-10)

Dùng khi chưa có VPS: web vẫn chạy bằng Docker trên máy bạn, Cloudflare tạo link HTTPS public trỏ vào đó. Không cần tài khoản, thẻ hay tên miền. Đăng ký Oracle Free bị từ chối nhiều lần ("error occurred while creating your account") nên dùng cách này.

Dịch vụ `tunnel` nằm trong `docker-compose.yml`, thuộc profile `tunnel`: chạy `docker compose up -d` như bình thường thì nó **không** bật.

**Bật** (stack chính phải đang chạy):

```bash
docker compose --profile tunnel up -d --no-deps tunnel
```

**Xem link public:**

```bash
docker logs skinsyntax-tunnel 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com'
```

**Tắt:**

```bash
docker compose stop tunnel
```

**Giới hạn:**
- Link dạng `https://<4 từ ngẫu nhiên>.trycloudflare.com`, **đổi mỗi lần** container `tunnel` khởi động lại (kể cả khi tắt/bật máy hoặc Docker). Gửi link mới cho người xem sau mỗi lần đổi.
- Máy bạn tắt, ngủ hoặc mất mạng thì web cũng tắt.
- Cloudflare Quick Tunnel dành cho thử nghiệm: không cam kết ổn định, giới hạn khoảng 200 request đồng thời. Đủ cho demo/bảo vệ đồ án, không dùng cho khách thật.
- Đăng nhập Google/Facebook sẽ lỗi qua link này, vì redirect URI đã đăng ký là `localhost`. Đăng nhập bằng email/mật khẩu vẫn chạy.
- Link cố định thì cần tài khoản Cloudflare + tên miền (Named Tunnel), hoặc VPS (mục 1–7).

**Đã kiểm tra thật qua link public (2026-11-10):**

| Kiểm tra | Kết quả |
| --- | --- |
| Trang chủ `/`, tìm kiếm `r=tatca` | 200, đủ CSS/JS/ảnh, không lỗi JavaScript |
| Chat Syna (`POST r=ai_chat_agent_stream`) | 200, trả lời dạng stream, có thẻ sản phẩm kèm giá và nút thêm giỏ |
| Trang chi tiết `r=chitiet&id=1000` | 200 |
| PHP nhận HTTPS | Có: cookie `ssv_sid` có cờ `secure` (nhờ `fastcgi_param HTTPS` trong `nginx.conf`) |
| Console | 1 lỗi 404 tải một tài nguyên ở trang chủ, chưa xác định file nào (danh sách request không ghi lại) |

---

## Phụ lục C. Link public cố định bằng ngrok (miễn phí)

Khác Phụ lục B: link **không đổi** sau khi khởi động lại. Bản free của ngrok cho 1 tên miền tĩnh dạng `<tên>.ngrok-free.app`.

**C1. Lấy authtoken và tên miền** (làm một lần):
1. Đăng ký tại https://dashboard.ngrok.com/signup (miễn phí, không cần thẻ).
2. Mục **Your Authtoken**: copy token. Đây là **khóa bí mật**: không gửi ai, không commit.
3. Mục **Domains**: bấm tạo domain (bản free được 1 domain tĩnh), copy tên dạng `abc-xyz.ngrok-free.app`.

**C2. Điền vào `.env`** ở thư mục dự án (đã có sẵn 2 dòng trống ở cuối file):

```
NGROK_AUTHTOKEN=<token vừa copy>
NGROK_DOMAIN=abc-xyz.ngrok-free.app
```

**C3. Bật** (stack chính phải đang chạy). Nếu đang dùng Cloudflare Tunnel (Phụ lục B) thì có thể tắt nó đi trước:

```bash
docker compose stop tunnel
```

```bash
docker compose --profile ngrok up -d --no-deps ngrok
```

```bash
docker logs skinsyntax-ngrok 2>&1 | grep -E "started tunnel|ERR"
```

Thấy `started tunnel ... url=https://abc-xyz.ngrok-free.app` là xong.

**C4. Tự chạy khi mở máy:** Docker Desktop → Settings → General → tick **Start Docker Desktop when you sign in to your computer** → Apply. Container `ngrok` có `restart: unless-stopped` nên tự chạy lại cùng Docker, link giữ nguyên.

**Giới hạn bản free:**
- Lần đầu mở link, trình duyệt hiện trang cảnh báo của ngrok, người xem bấm **Visit Site** để vào.
- Giới hạn băng thông và số request mỗi tháng của gói free; đủ cho demo.
- Máy tắt hoặc ngủ thì web tắt (giống Phụ lục B).
- Đăng nhập Google/Facebook cần thêm redirect URI `https://<NGROK_DOMAIN>/index.php?r=auth_social_callback...` ở Google/Facebook console và đổi `GOOGLE_OAUTH_REDIRECT_URI`/`FACEBOOK_OAUTH_REDIRECT_URI` trong `.env` (chưa làm, chưa thử).

**Đã kiểm tra:** cấu hình compose hợp lệ (cả local và production), ngrok 3.39 dùng tham số `--url`. **Chưa chạy thật** vì cần authtoken của bạn.
