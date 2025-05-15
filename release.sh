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
sed -i '' "s/^[[:space:]]*\**[[:space:]]*Version:[[:space:]]*.*/ * Version: $VERSION/" webhookarm.php
sed -i '' "s/^\(.*BONO_ARM_WEBHOOK_VERSION', '\)[^']*\('.*\)$/\1$VERSION\2/" webhookarm.php

# Git add and commit
git add readme.txt webhookarm.php
git commit -m "🔖 Bump version to $VERSION"
git push origin main

echo "✅ Version updated to $VERSION and pushed to main."
echo "⏳ Waiting for GitHub Action to auto-tag version $TAG..."
echo "👉 Monitor progress at: https://github.com/renatobo/WebHookARM/actions"

# Create GitHub release using gh CLI
if command -v gh &> /dev/null; then
  CHANGELOG=$(git log "$(git describe --tags --abbrev=0)..HEAD" --pretty=format:"- %s" --no-merges)
  gh release create "$TAG" --title "WebHookARM $VERSION" --notes "$CHANGELOG" || echo "⚠️ GitHub release creation failed or already exists."
else
  echo "⚠️ GitHub CLI (gh) not found. Skipping release creation."
fi