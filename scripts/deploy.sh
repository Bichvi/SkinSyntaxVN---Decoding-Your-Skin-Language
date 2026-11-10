#!/usr/bin/env bash
# Deploy SkinSyntaxVN trên VPS. GitHub Actions gọi script này qua SSH sau mỗi lần push lên main;
# cũng chạy tay được:  bash ~/skinsyntax/scripts/deploy.sh
#
# Biến môi trường (tùy chọn):
#   APP_DIR        thư mục repo trên VPS (mặc định: thư mục cha của scripts/)
#   DEPLOY_BRANCH  nhánh deploy (mặc định: main)
#
# Toàn bộ thân script nằm trong main() để bash đọc hết trước khi chạy: `git pull` bên dưới có thể
# sửa chính file này, đọc dở dang sẽ chạy nhầm lệnh.

main() {
  set -euo pipefail

  local app_dir="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
  local branch="${DEPLOY_BRANCH:-main}"
  cd "$app_dir"

  local compose=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

  if [ ! -f .env ]; then
    echo "[deploy] Thiếu $app_dir/.env — copy .env production lên VPS trước." >&2
    exit 1
  fi

  # Chỉ một lần deploy chạy cùng lúc.
  exec 9>"/tmp/skinsyntax-deploy.lock"
  flock -n 9 || { echo "[deploy] Đang có lần deploy khác chạy, thoát." >&2; exit 1; }

  echo "[deploy] Kéo code mới từ origin/$branch ..."
  local old new
  old="$(git rev-parse HEAD)"
  git fetch --quiet origin "$branch"
  git checkout --quiet "$branch"
  git merge --ff-only "origin/$branch"
  new="$(git rev-parse HEAD)"
  echo "[deploy] $old -> $new"

  # Build lần lượt mỗi image MỘT lần (Docker cache: chỉ đổi code thì vài giây; đổi requirements mới lâu).
  # Không dùng `up --build`: nó build song song cùng một image cho mọi service dùng chung
  # (ai-service x3, ai-idol x5), dễ hết RAM trên VPS. Các service còn lại dùng lại tag vừa build:
  #   ai-service      -> skinsyntax-ai-service:latest (search-worker, catalog-worker)
  #   ai-idol-service -> skinsyntax-ai-idol:local     (ai-idol-worker, -control-, -broadcast-, -beat)
  local svc
  for svc in php-backend ai-service ai-idol-service ai-idol-agent-service voice-service; do
    echo "[deploy] Build $svc ..."
    "${compose[@]}" build "$svc"
  done

  # Tạo lại container có image hoặc cấu hình mới.
  echo "[deploy] Khởi động stack ..."
  "${compose[@]}" up -d --remove-orphans

  # Code PHP và Flask được mount thẳng từ repo (không nằm trong image), nên container cũ vẫn chạy
  # code cũ (gunicorn không tự nạp lại, opcache cache file). Khởi động lại đúng phần bị đổi.
  if [ "$old" != "$new" ]; then
    local changed
    changed="$(git diff --name-only "$old" "$new")"
    local restart=()
    if grep -qE '^(backend/|frontend/|docker/php/)' <<<"$changed"; then
      restart+=(php-backend)
    fi
    if grep -qE '^ai-service-flask/' <<<"$changed"; then
      restart+=(ai-service search-worker catalog-worker)
    fi
    if grep -qE '^nginx/' <<<"$changed"; then
      restart+=(nginx)
    fi
    if grep -qE '^Caddyfile$' <<<"$changed"; then
      restart+=(caddy)
    fi
    if [ "${#restart[@]}" -gt 0 ]; then
      echo "[deploy] Khởi động lại: ${restart[*]}"
      "${compose[@]}" restart "${restart[@]}"
    fi
  fi

  echo "[deploy] Chờ trang chủ phản hồi ..."
  local ok=0
  for _ in $(seq 1 30); do
    if "${compose[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1/; then
      ok=1
      break
    fi
    sleep 5
  done

  # Dọn image cũ không còn dùng sau mỗi lần build để VPS khỏi đầy ổ.
  docker image prune -f >/dev/null

  "${compose[@]}" ps --format 'table {{.Name}}\t{{.Status}}'

  if [ "$ok" -ne 1 ]; then
    echo "[deploy] LỖI: trang chủ không phản hồi sau 150 giây. Xem log: ${compose[*]} logs --tail 100 php-backend nginx" >&2
    exit 1
  fi
  echo "[deploy] Xong: $new"
}

main "$@"
