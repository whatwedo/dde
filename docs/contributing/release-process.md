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

1. Ensure all changes are merged to the release branch
2. Ensure the QA pipeline passes
3. Create and push a tag:

```bash
git tag v2.1.0
git push origin v2.1.0
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

Users install or update via the package managers listed in the [installation documentation](../getting-started/installation.md) (Homebrew, APT, Alpine).
