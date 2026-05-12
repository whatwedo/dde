---
title: "Release Process"
---


dde follows semantic versioning and uses GitHub Actions for automated releases.

## Versioning

dde uses [Semantic Versioning](https://semver.org/):

- **Major** (v3.0.0): breaking changes
- **Minor** (v2.1.0): new features, backward-compatible
- **Patch** (v2.0.1): bug fixes, backward-compatible

## Creating a Release

A release bundles three artifacts that must stay in lockstep: a new section at the top of `CHANGELOG.md`, the `APP_VERSION` constant in `src/Application.php`, and a GPG-signed annotated tag `v<version>`.

### Automated (Claude Code skill)

The repo ships a `releasing` skill (`.claude/skills/releasing/SKILL.md`) that walks Claude Code through the whole sequence — categorising commits, drafting the changelog entry, bumping `APP_VERSION`, creating the signed commit + tag, and pausing before the push for explicit approval. To trigger it inside Claude Code, ask Claude to "create a release" (or invoke the skill directly via `/releasing` if the slash binding is available); the model loads the skill and follows it step by step.

### Manual

If you prefer to drive the release by hand:

1. Ensure all changes are merged to the release branch and the QA pipeline passes
2. Add a `## [<version>] - YYYY-MM-DD` section at the top of `CHANGELOG.md` with the user-visible Added/Changed/Fixed bullets
3. Update `APP_VERSION` in `src/Application.php` (single source of truth for `dde --version`)
4. Commit signed + signed-off:

```bash
git add CHANGELOG.md src/Application.php
git commit -S --signoff -m "chore(release): bump version to v2.1.0"
```

5. Create the annotated, signed tag and push:

```bash
git tag -s v2.1.0 -m "v2.1.0"
git push origin <branch> v2.1.0
```

The tag name must start with `v` (e.g. `v2.0.0`, `v2.1.0`).

## CI Pipeline

Pushing a `v*` tag triggers the release workflow (`.github/workflows/release.yml`). The pipeline:

1. **Reads the PHP version** from `composer.json` (single source of truth)
2. **Runs quality checks**: ECS, PHPStan, Rector, and tests
3. **Builds the PHAR** using humbug/box
4. **Combines PHAR with micro.sfx** from static-php-cli to produce standalone binaries
5. **Uploads binaries** to a GitHub Release

### Target Platforms

The release pipeline produces binaries for 4 platforms:

| Platform | Architecture | Binary Name |
|----------|-------------|-------------|
| macOS | x86_64 | `dde-darwin-amd64` |
| macOS | arm64 (Apple Silicon) | `dde-darwin-arm64` |
| Linux | x86_64 | `dde-linux-amd64` |
| Linux | arm64 | `dde-linux-arm64` |

### Build Process

The `scripts/build.sh` script automates the binary creation:

1. Reads the PHP version from `composer.json`
2. Downloads the matching `micro.sfx` from static-php-cli
3. Combines `micro.sfx` + `bin/dde.phar` into a standalone executable

## Installation

Users install or update via the package managers listed in the [installation documentation](../getting-started/installation.md) (Homebrew, APT, Alpine, Arch Linux, RPM).
