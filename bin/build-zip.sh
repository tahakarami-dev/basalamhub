#!/usr/bin/env bash
# Builds an installable plugin zip: dist/salamhub-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "define( 'SLH_VERSION'" salamhub.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
OUT="dist/salamhub-${VERSION}.zip"
TMP=$(mktemp -d)
mkdir -p dist "$TMP/salamhub"
cp -r salamhub.php uninstall.php readme.txt includes assets languages "$TMP/salamhub/"
rm -f "$OUT"
(cd "$TMP" && zip -qr - salamhub) > "$OUT"
rm -rf "$TMP"
echo "$OUT"
