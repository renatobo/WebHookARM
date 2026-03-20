#!/bin/bash

set -euo pipefail

PLUGIN_DIR_NAME="$(basename "$PWD")"
PLUGIN_FILE="webhookarm.php"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Expected plugin bootstrap file '$PLUGIN_FILE' in $PWD"
  exit 1
fi

VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1
)"

if [[ -z "$VERSION" ]]; then
  echo "Could not determine plugin version from $PLUGIN_FILE"
  exit 1
fi

OUTPUT_NAME="${PLUGIN_DIR_NAME}-${VERSION}.zip"
OUTPUT_PATH="$PWD/$OUTPUT_NAME"
STAGING_DIR="$(mktemp -d)"
PACKAGE_DIR="$STAGING_DIR/$PLUGIN_DIR_NAME"
TEMP_OUTPUT_PATH="$STAGING_DIR/$OUTPUT_NAME"

cleanup() {
  rm -rf "$STAGING_DIR"
}

trap cleanup EXIT

mkdir -p "$PACKAGE_DIR"

rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.agents/' \
  --exclude '.claude/' \
  --exclude '.DS_Store' \
  --exclude '*.zip' \
  --exclude '.gitignore' \
  --include '/README.md' \
  --exclude '*.md' \
  --exclude '*.sh' \
  --exclude 'example.txt' \
  --exclude 'psalm.xml' \
  ./ "$PACKAGE_DIR/"

(
  cd "$STAGING_DIR"
  zip -rq "$TEMP_OUTPUT_PATH" "$PLUGIN_DIR_NAME"
)

cp "$TEMP_OUTPUT_PATH" "$OUTPUT_PATH"

echo "Created $OUTPUT_PATH"
