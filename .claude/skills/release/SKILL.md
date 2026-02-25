---
name: release
description: Create a new plugin release with version bump and changelog
disable-model-invocation: true
arguments:
  - name: version
    description: The semantic version number (e.g., 1.2.0)
    required: true
---

# Release Skill

Create a new WebHookARM plugin release with proper version bumping and changelog generation.

## Steps

1. **Validate version format**: Ensure the version follows semantic versioning (X.Y.Z)

2. **Update version in files**:
   - Update `Version:` header in `webhookarm.php`
   - Update `BONO_ARM_WEBHOOK_VERSION` constant in `webhookarm.php`
   - Update `Stable tag:` in `readme.txt`

3. **Generate changelog**: Use `git log` to generate changelog from commits since last tag

4. **Create commit**: Commit the version changes with message "Bump version to {version}"

5. **Push to main**: Push the commit to trigger the GitHub Action that auto-tags

6. **Create GitHub release**: Use `gh release create` with the generated changelog

## Validation Rules

- Version must match pattern: `^[0-9]+\.[0-9]+\.[0-9]+$`
- New version must be greater than current version
- Working directory must be clean before starting (no uncommitted changes)

## Example Usage

```
/release 1.2.0
```

## Files Modified

- `webhookarm.php` - Plugin header and version constant
- `readme.txt` - Stable tag field
