# AGENTS.md

See [CLAUDE.md](CLAUDE.md) for project context: commands, architecture, gotchas,
release flow, and non-goals. It is the single source of truth for this repository;
this file exists so agents that look for `AGENTS.md` find their way there.

## GitHub releases after PR merge

The supported release path is `.github/workflows/release.yml`. Ordinary merges do not publish. The old tag/push/manual publishing workflows have been replaced; pushing a tag alone does not publish.

For the next release:

1. Bump `webhookarm.php` header `Version` and `BONO_ARM_WEBHOOK_VERSION` to matching `X.Y.Z`. Also update `readme.txt` Stable tag and any Version field. Update the user-facing changelog/upgrade notes under this repository’s conventions.
2. Add `release-notes/X.Y.Z.md` with exact headings `## New Features`, `## Improvements`, `## Bug Fixes`; use `- None.` if a section has no entries. Older releases without this notes format remain unchanged.
3. Open a PR into `main`, run all required checks, then apply **`release` before merging**. That label explicitly opts the merge into publication. Do not create/push the version tag yourself or use the retired direct-tagging `release.sh` path.
4. Merge. The workflow verifies the canonical merged PR and exact merge commit, runs all existing reusable quality gates plus release/package guards, checks versions/notes and main ancestry, creates annotated `vX.Y.Z`, and publishes the **exact CI-tested ZIP** with SHA-256 checksum. The release is created as a draft and published only after asset upload succeeds.
5. Read back the published release and uploaded checksum; a green PR is not evidence that publication completed. Expected ZIP: `WebHookARM-{version}.zip` under root `WebHookARM/`.

If publication fails, manually dispatch **Release merged PR** from `main` with `pr_number`. The PR must be merged into `main` and still carry `release`. Adding the label after merge does not trigger publication, but manual dispatch can release that eligible PR. Matching existing tags may be reused only when they point to the same merge commit. Existing releases/drafts are never overwritten: inspect and explicitly resolve partial drafts before retrying; never move/delete published tags as a retry strategy. Concurrent runs for the same PR are serialized.

Tags and publication happen in one workflow, avoiding the `GITHUB_TOKEN` tag-event suppression. No PAT or additional secret is required. Only publishing has `contents: write` ; quality jobs retain their own minimum analysis permissions.

Release configuration is `.github/release.json`; guard code is `.github/scripts/release.py`; tests run with `python3 -B -m unittest discover -s .github/tests -v`. Package locally with `bash build.sh`, then run `python3 -B .github/scripts/release.py package`. Release ZIPs exclude this tooling and agent guides. Do not hand-edit the upload/download handoff to rebuild a different artifact in the publishing job.

For workflow-only maintenance, keep the plugin version unchanged and omit the `release` label. Merge setup/automation PRs unlabelled; the next labelled version PR uses the new flow.
