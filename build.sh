#!/usr/bin/env bash
# Build a distributable zip of the plugin for WordPress.org / GitHub releases.
# Output: dist/trondealer-payments-<version>.zip
#
# The zip's top-level directory is `trondealer-payments/` so the file can be
# uploaded as-is via WordPress Plugins → Add New → Upload.

set -euo pipefail

PLUGIN_SLUG="trondealer-payments"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' trondealer-payments.php | head -1 | awk -F': ' '{print $2}' | tr -d '[:space:]')"
if [[ -z "$VERSION" ]]; then
	echo "Could not parse plugin version from trondealer-payments.php" >&2
	exit 1
fi

OUT_DIR="dist"
STAGE_DIR="$OUT_DIR/$PLUGIN_SLUG"
ZIP_FILE="$OUT_DIR/${PLUGIN_SLUG}-${VERSION}.zip"

rm -rf "$OUT_DIR"
mkdir -p "$STAGE_DIR"

# Files and directories that ship with the plugin (everything else is excluded).
SHIP=(
	"trondealer-payments.php"
	"readme.txt"
	"LICENSE"
	"NOTICE.md"
	"includes"
	"templates"
	"assets/css"
	"assets/js"
	"assets/images"
	"languages"
)

for path in "${SHIP[@]}"; do
	if [[ -e "$path" ]]; then
		mkdir -p "$STAGE_DIR/$(dirname "$path")"
		cp -R "$path" "$STAGE_DIR/$(dirname "$path")/"
	fi
done

# Strip macOS metadata, IDE leftovers, and dev-only assets defensively.
find "$STAGE_DIR" \( \
	-name ".DS_Store" -o \
	-name "Thumbs.db" -o \
	-name "*.map" -o \
	-name "*.log" -o \
	-name ".git*" \
\) -delete

(cd "$OUT_DIR" && zip -rq "${PLUGIN_SLUG}-${VERSION}.zip" "$PLUGIN_SLUG")

rm -rf "$STAGE_DIR"

echo
echo "Built: $ZIP_FILE"
unzip -l "$ZIP_FILE" | tail -1
