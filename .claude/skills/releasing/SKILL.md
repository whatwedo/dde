---
name: releasing
description: Use when the user asks to create a release
---

# Release Workflow

The dde release process: version bump, changelog entry, signed commit, signed tag. Never push automatically.

## Overview

A dde release is three artifacts that must stay in lockstep:
- `CHANGELOG.md` — new section at the top
- `src/Application.php` — `APP_VERSION` constant
- Annotated, GPG-signed git tag `v<version>`

All three land in a single commit `chore(release): bump version to v<version>`, followed by the tag pointing at that commit.

## Step 1: Determine the next version

```bash
git tag --sort=-v:refname | head -5
git log "$(git tag --sort=-v:refname | head -1)..HEAD" --oneline
```

Versioning scheme in use (as of v2):
- `v2.0.0-alpha.N` — pre-release, bump `N`
- `v2.0.0-beta.N`, `v2.0.0-rc.N` — later pre-release tracks
- `v2.0.0`, `v2.0.1`, `v2.1.0` — stable semver

**Ask the user to confirm** the target version before writing anything — the answer depends on whether recent changes are breaking, user-visible, or internal.

## Step 2: Categorize commits

Use conventional-commit prefixes to decide changelog sections. Match the strict AGENTS.md interpretation of `feat`:

| Commit type | Changelog section | Include? |
|-------------|-------------------|----------|
| `feat(...)` user-visible | **Added** | yes |
| `fix(...)` user-visible | **Fixed** | yes |
| user-visible behaviour change (e.g. removed flag) | **Changed** / **Removed** | yes |
| `refactor`, `chore`, `test`, `docs`, `style` | — | no, unless end-users notice |
| `fix(release): ...` touching build infra | **Fixed** | yes, terse |

Rule of thumb from AGENTS.md: if a user would not notice it, it does not belong in the changelog.

Write entries in past-tense-free, user-facing English. Describe the *effect on the user*, not the implementation (no class names, no "refactor X to Y").

## Step 3: Update files

CHANGELOG.md — insert a new section directly after the intro paragraph:

```markdown
## [2.0.0-alpha.4] - YYYY-MM-DD

### Added
- ...

### Changed
- ...

### Fixed
- ...
```

Use today's date in ISO format. Omit empty subsections.

`src/Application.php`:

```php
public const string APP_VERSION = 'v<new-version>';
```

This is the **single source of truth** for the binary version. `--version` reads from it; CI does not inject it.

## Step 4: Commit and tag

Both commit and tag must be GPG-signed. Sign-off is required (AGENTS.md: "Never use `--no-verify`, `--no-gpg-sign`").

```bash
git add CHANGELOG.md src/Application.php
git commit -S --signoff -m "chore(release): bump version to v<new-version>"
git tag -s v<new-version> -m "v<new-version>"
```

Verify:

```bash
git log -1 --show-signature --stat
git show v<new-version> --stat --no-patch
```

Commit signature must show `Good signature`; tag must start with `tag v<new-version>` and carry a PGP block.

## Step 5: Ask before pushing

Never push on your own. The tag triggers the release workflow (`.github/workflows/release.yml`), which publishes binaries to 4 platforms, Homebrew, APT, RPM, and AUR — not something to kick off by accident.

Present:

> Tag `v<version>` is local. Push with `git push origin <branch> v<version>`?

and wait for explicit confirmation.

## Quick checklist

- [ ] User confirmed target version
- [ ] CHANGELOG entry lists only user-visible changes
- [ ] Today's date, ISO format
- [ ] `APP_VERSION` bumped
- [ ] Commit signed + signed-off, subject `chore(release): bump version to v<version>`
- [ ] Annotated tag signed, subject exactly `v<version>`
- [ ] No push without explicit user approval

## Common mistakes

| Mistake | Fix |
|---------|-----|
| Listing `refactor`/`chore` commits in CHANGELOG | Drop them; they are not user-visible. |
| Treating every `feat(...)` as **Added** | Only if user-visible; internal-only `feat` commits are rare but happen — check the diff. |
| Amending an earlier release commit | Always a new commit; never rewrite a published tag. |
| Missing tag signature (`git tag v…` instead of `git tag -s v…`) | Re-create with `-s`. |
| Pushing the tag without asking | The release workflow is expensive and public; always confirm. |
