#!/usr/bin/env bash
# Licensed edition (RTL Theme / راست‌چین).
#
#   bin/build-rtl.sh                         → dist/rtl/encode-me.zip  (the one file to encode)
#                                              dist/rtl/basalamhub-<ver>-rtl-UNENCODED.zip (for testing only)
#   bin/build-rtl.sh path/to/bsh-license.php → dist/rtl/basalamhub-<ver>-rtl.zip (release, with the encoded gate)
#
# The encoded file is the one rtl-theme.com returns after uploading encode-me.zip.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "define( 'BSH_VERSION'" basalamhub.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
LIC=RTL_License_420efec66a1fed09.php
EXPECTED=eba12f5015d2e31586fbad55678f469c056ae8a1
[ "$(sha1sum "$LIC" | cut -d' ' -f1)" = "$EXPECTED" ] || { echo "✗ $LIC has changed (sha1 mismatch); the license check would fail." >&2; exit 1; }
mkdir -p dist/rtl
ENCODED="${1:-}"
if [ -n "$ENCODED" ]; then
  [ -f "$ENCODED" ] || { echo "✗ not found: $ENCODED" >&2; exit 1; }
  head -n 5 "$ENCODED" | grep -qE '^<\?php //ICB0|Encrypted by : ionCube' || { echo "✗ $ENCODED does not look encoded (no ionCube header at the top)." >&2; exit 1; }
  OUT="dist/rtl/basalamhub-${VERSION}-rtl.zip"
else
  OUT="dist/rtl/basalamhub-${VERSION}-rtl-UNENCODED.zip"
  rm -f dist/rtl/encode-me.zip
  zip -q dist/rtl/encode-me.zip bsh-license.php
  echo "dist/rtl/encode-me.zip"
fi
TMP=$(mktemp -d)
mkdir -p "$TMP/basalamhub"
cp -r basalamhub.php bsh-license.php RTL_License_420efec66a1fed09.php uninstall.php readme.txt includes assets languages "$TMP/basalamhub/"
[ -n "$ENCODED" ] && cp "$ENCODED" "$TMP/basalamhub/bsh-license.php"
rm -f "$OUT"
(cd "$TMP" && zip -qr - basalamhub) > "$OUT"
rm -rf "$TMP"
echo "$OUT"
