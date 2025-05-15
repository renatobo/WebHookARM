#!/bin/bash

# Prompt for the new version
read -p "Enter new version (e.g. 1.1.0): " VERSION

# Validate version format: must be X.Y.Z
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Invalid version format. Use semantic versioning: X.Y.Z (e.g. 1.2.3)"
  exit 1
fi

TAG="v$VERSION"

# Update version in readme.txt
sed -i '' "s/^Stable tag: .*/Stable tag: $VERSION/" readme.txt

# Update version in main plugin file
sed -i '' "s/^\(\*\s*Version:\s*\).*/\1$VERSION/" webhookarm.php

# Git add and commit
git add readme.txt webhookarm.php
git commit -m "🔖 Bump version to $VERSION"
git push origin main

echo "✅ Version updated to $VERSION and pushed to main."
echo "⏳ Waiting for GitHub Action to auto-tag version $TAG..."
echo "👉 Monitor progress at: https://github.com/renatobo/WebHookARM/actions"