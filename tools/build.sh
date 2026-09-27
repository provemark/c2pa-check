#!/bin/sh
# Builds build/provemark-c2pa-check.zip from the committed HEAD (SPEC-006,
# SPEC-009).
#
# 1. git archive HEAD; export-ignore in .gitattributes leaves out what does
#    not ship (read from the working tree, so a new .gitattributes counts
#    before it is committed); uncommitted files are not in a release.
# 2. composer install --no-dev with the committed lock, in the build folder.
# 3. Strauss (pinned by version and SHA-256) moves the verifier under the
#    plugin's namespace, Provemark\C2paCheck\Vendor\, into vendor-prefixed/,
#    and rewrites the call sites in the build's copy of src/ (SPEC-009).
# 4. The verifier trimmed to what runs: src/, LICENSE, composer.json; and
#    vendor/bin/, Composer's proxy for the verifier's CLI, which is not
#    shipped.
# 5. composer.lock removed; composer.json stays, as Plugin Check requires
#    it next to a vendor/ directory (SPEC-006 amendment 1). Then zipped.
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
out="$root/build"
dir="$out/provemark-c2pa-check"

strauss_version=0.30.0
strauss_sha256=08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96
strauss="$out/.tools/strauss-$strauss_version.phar"

rm -rf "$dir" "$out/provemark-c2pa-check.zip"
mkdir -p "$dir" "$out/.tools"

if [ ! -f "$strauss" ]; then
    curl -sSfL -o "$strauss.part" "https://github.com/BrianHenryIE/strauss/releases/download/$strauss_version/strauss.phar"
    mv "$strauss.part" "$strauss"
fi
actual=$(php -r 'echo hash_file("sha256", $argv[1]);' "$strauss")
if [ "$actual" != "$strauss_sha256" ]; then
    echo "Error: $strauss has SHA-256 $actual, expected $strauss_sha256. Delete it to download it again." >&2
    exit 1
fi

git -C "$root" archive --worktree-attributes HEAD | tar -x -C "$dir"
cp "$root/composer.lock" "$dir/"

composer install --working-dir="$dir" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
# The verifier's command-line tool is not used by the plugin (SPEC-006
# amendment 4). Removed before the autoloader is regenerated and Strauss
# runs, so no classmap or alias refers to it.
rm -rf "$dir/vendor/provemark/c2pa-verifier/src/Cli"
composer dump-autoload --working-dir="$dir" --no-dev --optimize --no-interaction --quiet
(cd "$dir" && php "$strauss" --no-interaction >/dev/null)
# Strauss's alias autoloader writes PHP into the plugin folder and includes
# it; nothing loads it, and it would alias the names prefixed away
# (SPEC-016).
rm -f "$dir/vendor/composer/autoload_aliases.php"

verifier="$dir/vendor-prefixed/provemark/c2pa-verifier"
find "$verifier" -mindepth 1 -maxdepth 1 ! -name src ! -name LICENSE ! -name composer.json -exec rm -rf {} +
rm -rf "$dir/vendor/bin"

rm "$dir/composer.lock"

(cd "$out" && zip -qr -X provemark-c2pa-check.zip provemark-c2pa-check)
echo "Built $out/provemark-c2pa-check.zip from $(git -C "$root" rev-parse --short HEAD)"
