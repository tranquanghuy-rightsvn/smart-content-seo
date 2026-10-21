#!/usr/bin/env bash
# Đóng gói bản phát hành: cập nhật bản dịch, chép file cần thiết vào dist/smart-content-seo, nén zip, chạy Plugin Check.
# Cần wp-env đang chạy (npx @wordpress/env start) và: pip install polib
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=smart-content-seo
VERSION=$(sed -n "s/^ \* Version: *//p" $SLUG.php)
SRC=wp-content/plugins/$(basename "$PWD")

npx -y @wordpress/env run cli wp i18n make-pot "$SRC" "$SRC/languages/$SLUG.pot" --domain=$SLUG --exclude=languages,bin,dist,node_modules
python3 bin/i18n.py

rm -rf dist/$SLUG "dist/$SLUG-$VERSION.zip"
mkdir -p dist/$SLUG
rsync -a --exclude '.*' --exclude 'bin' --exclude 'dist' --exclude 'node_modules' ./ dist/$SLUG/
(cd dist && zip -qr "$SLUG-$VERSION.zip" $SLUG)
echo "Built dist/$SLUG-$VERSION.zip"

# Plugin Check trên đúng bản phát hành (thư mục tên theo slug).
C=$(docker ps --format '{{.Names}}' | grep -E 'wordpress-1$' | grep -v tests | head -1)
docker exec "$C" rm -rf /var/www/html/wp-content/plugins/$SLUG
docker cp dist/$SLUG "$C:/var/www/html/wp-content/plugins/$SLUG"
npx -y @wordpress/env run cli wp plugin check $SLUG || true
docker exec "$C" rm -rf /var/www/html/wp-content/plugins/$SLUG
