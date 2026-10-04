#!/usr/bin/env bash
# Builds an installable plugin zip: dist/basalamhub-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "define( 'BSH_VERSION'" basalamhub.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
OUT="dist/basalamhub-${VERSION}.zip"
TMP=$(mktemp -d)
mkdir -p dist "$TMP/basalamhub"
cp -r basalamhub.php uninstall.php readme.txt includes assets languages "$TMP/basalamhub/"
rm -f "$OUT"
(cd "$TMP" && zip -qr - basalamhub) > "$OUT"
rm -rf "$TMP"
echo "$OUT"
