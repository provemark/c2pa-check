#!/bin/sh
# Builds build/provemark-c2pa-check.zip from the committed HEAD (SPEC-006).
#
# 1. git archive HEAD; export-ignore in .gitattributes leaves out what does
#    not ship (read from the working tree, so a new .gitattributes counts
#    before it is committed); uncommitted files are not in a release.
# 2. composer install --no-dev with the committed lock, in the build folder.
# 3. The verifier trimmed to what runs: src/, LICENSE, composer.json; and
#    vendor/bin/, Composer's proxy for the verifier's CLI, which is not
#    shipped.
# 4. composer.lock removed; composer.json stays, as Plugin Check requires
#    it next to a vendor/ directory (SPEC-006 amendment 1). Then zipped.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
out="$root/build"
dir="$out/provemark-c2pa-check"

rm -rf "$dir" "$out/provemark-c2pa-check.zip"
mkdir -p "$dir"

git -C "$root" archive --worktree-attributes HEAD | tar -x -C "$dir"
cp "$root/composer.lock" "$dir/"

composer install --working-dir="$dir" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

verifier="$dir/vendor/provemark/c2pa-verifier"
find "$verifier" -mindepth 1 -maxdepth 1 ! -name src ! -name LICENSE ! -name composer.json -exec rm -rf {} +
rm -rf "$dir/vendor/bin"

rm "$dir/composer.lock"

(cd "$out" && zip -qr -X provemark-c2pa-check.zip provemark-c2pa-check)
echo "Built $out/provemark-c2pa-check.zip from $(git -C "$root" rev-parse --short HEAD)"
