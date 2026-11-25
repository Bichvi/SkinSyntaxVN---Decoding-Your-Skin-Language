#!/usr/bin/env bash
# Deploy the approved Recommendation file to an existing production bind mount.
set -euo pipefail

release_dir=${1:?Pass the staged release directory}
expected=${2:?Pass the SHA-256 of the current production file}
[[ $# == 2 && $release_dir =~ ^/tmp/skinsyntax-release\.[A-Za-z0-9]+$ && $expected =~ ^[a-f0-9]{64}$ ]]
[[ -d $release_dir && ! -L $release_dir && -O $release_dir ]]
[[ $(stat -c %a "$release_dir") == 700 ]]

app_dir="$HOME/SkinSyntaxVN---Decoding-Your-Skin-Language"
web_dir="$HOME/.skinsyntax-web-release-490e7c7"
rag_dir="$HOME/.skinsyntax-rag-release-39a548a"
target=ai-service-flask/structured_recommendation.py
candidate="$release_dir/structured_recommendation.py"
backup="$release_dir/previous.py"
[[ -f $candidate && ! -L $candidate && ! -e $backup ]]

cd "$app_dir"
exec 9>"$HOME/.skinsyntax-production-release.lock"
flock -n 9
dc=(docker compose -f docker-compose.yml -f docker-compose.prod.yml -f "$web_dir/web.override.yml")
sha256sum -c "$rag_dir/manual-deployment.sha256"
awk '/^[a-f0-9]+  .*\/(web.override.yml|php-fpm.web.conf|php-session.web.ini)$/ {print}' \
    "$web_dir/deployment-manifest.txt" | sha256sum -c -
(cd "$release_dir" && sha256sum -c candidate.sha256)
printf '%s  %s\n' "$expected" "$target" | sha256sum -c -
cid=$("${dc[@]}" ps -q ai-service)
[[ -n $cid && $(docker inspect -f '{{range .Mounts}}{{if eq .Destination "/app"}}{{.Source}}{{end}}{{end}}' "$cid") == "$app_dir/ai-service-flask" ]]
image=$(docker inspect -f '{{.Image}}' "$cid")

wait_health() {
    for _ in $(seq 1 45); do
        if [[ $(docker inspect -f '{{.State.Health.Status}}' "$cid") == healthy ]]; then
            "${dc[@]}" exec -T ai-service python -c 'import json,urllib.request; h=json.load(urllib.request.urlopen("http://127.0.0.1:5001/api/health",timeout=10)); assert h.get("ok") and h["documents"]>0'
            return
        fi
        sleep 4
    done
    return 1
}

replace_target() {
    local tmp
    tmp=$(mktemp "$(dirname "$target")/.recommendation.XXXXXXXX")
    cp -p -- "$1" "$tmp"
    mv -fT -- "$tmp" "$target"
}

rollback() {
    local code=$?
    trap - EXIT
    if ((code)); then
        echo "Release failed. Restoring $backup." >&2
        set +e
        replace_target "$backup" && "${dc[@]}" restart ai-service && wait_health
        if (($?)); then
            echo "Automatic rollback did not reach healthy status." >&2
        fi
    fi
    exit "$code"
}

wait_health
cp -p -- "$target" "$backup"
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
replace_target "$candidate"
"${dc[@]}" restart ai-service
wait_health
[[ $(docker inspect -f '{{.Image}}' "$cid") == "$image" ]]
"${dc[@]}" exec -T ai-service python - <<'PY'
import json
import urllib.request
request = urllib.request.Request(
    "http://127.0.0.1:5001/api/recommend",
    json.dumps({"user_profile": {"skin_type": "da dầu"}}).encode(),
    {"Content-Type": "application/json"},
)
with urllib.request.urlopen(request, timeout=90) as response:
    data = json.load(response)
    assert response.status == 200 and data["ok"] and data["products"]
    labels = [item["loai_da"].casefold() for item in data["products"]]
    exact = ("dầu", "dau", "nhờn", "nhon", "oily")
    universal = ("mọi loại da", "moi loai da", "tất cả loại da", "tat ca loai da", "all skin types")
    assert any(any(word in label for word in exact) for label in labels)
    assert all(any(word in label for word in (*exact, *universal)) for label in labels)
    print(f"Recommendation smoke: {len(labels)} compatible products")
PY
curl --fail --silent --show-error --max-time 20 --output /dev/null https://skinsyntaxvn.app/
trap - EXIT INT TERM
printf 'Release passed. Rollback file: %s\n' "$backup"
