#!/bin/bash

set -euo pipefail

read -r -p "Enter new version (e.g. 1.2.0): " VERSION

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Invalid version format. Use semantic versioning: X.Y.Z"
  exit 1
fi

sed -i '' "s/^Stable tag: .*/Stable tag: $VERSION/" readme.txt
sed -i '' "s/^[[:space:]]*\\*[[:space:]]*Version:[[:space:]]*.*/ * Version:           $VERSION/" webhookarm.php
sed -i '' "s/^\(define('BONO_ARM_WEBHOOK_VERSION', '\)[^']*\(');\)$/\1$VERSION\2/" webhookarm.php

echo "Updated plugin version metadata to $VERSION."
echo "Next:"
echo "1. Update readme and README release notes for $VERSION."
echo "2. Commit and push to main."
echo "3. GitHub Actions will tag v$VERSION, build the release zip, and publish the GitHub release."
