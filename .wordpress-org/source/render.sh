#!/bin/sh
# Renders the wordpress.org icon and banner PNGs from icon.svg and
# banner.html with headless Chrome (macOS), then halves them with sips.
# Chrome writes the screenshot but may not exit; the script waits for the
# file and then stops it.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
out=$(dirname "$here")
chrome="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
tmp=$(mktemp -d)

shot() { # page width height output
    rm -f "$4"
    "$chrome" --headless --disable-gpu --no-first-run --no-default-browser-check --use-mock-keychain \
        --hide-scrollbars --force-device-scale-factor=1 --default-background-color=00000000 \
        --user-data-dir="$tmp/profile" --window-size="$2,$3" --screenshot="$4" "file://$1" >/dev/null 2>&1 &
    pid=$!
    i=0
    while [ ! -s "$4" ] && [ $i -lt 30 ]; do sleep 1; i=$((i + 1)); done
    sleep 1
    kill $pid 2>/dev/null || true
    [ -s "$4" ] || { echo "no screenshot: $4" >&2; exit 1; }
}

printf '<!doctype html><html><body style="margin:0;background:transparent"><img src="file://%s" style="display:block;width:256px;height:256px"></body></html>' "$here/icon.svg" > "$tmp/icon.html"
shot "$tmp/icon.html" 256 256 "$out/icon-256x256.png"
sips -z 128 128 "$out/icon-256x256.png" --out "$out/icon-128x128.png" >/dev/null

shot "$here/banner.html" 1544 500 "$out/banner-1544x500.png"
sips -z 250 772 "$out/banner-1544x500.png" --out "$out/banner-772x250.png" >/dev/null

rm -rf "$tmp"
for f in icon-128x128 icon-256x256 banner-772x250 banner-1544x500; do
    echo "$f.png $(sips -g pixelWidth -g pixelHeight "$out/$f.png" | awk '/pixel/{printf "%s ", $2}')"
done
