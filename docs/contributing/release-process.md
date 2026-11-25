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

A release bundles two artifacts that must stay in lockstep: a new section at the top of `CHANGELOG.md` and a GPG-signed annotated tag `v<version>`. The version string the binary reports is **not** edited by hand — see [Version Injection](#version-injection) below.

### Automated (Claude Code skill)

The repo ships a `releasing` skill (`.claude/skills/releasing/SKILL.md`) that walks Claude Code through the whole sequence — categorising commits, drafting the changelog entry, creating the signed commit + tag, and pausing before the push for explicit approval. To trigger it inside Claude Code, ask Claude to "create a release" (or invoke the skill directly via `/releasing` if the slash binding is available); the model loads the skill and follows it step by step.

### Manual

If you prefer to drive the release by hand:

1. Ensure all changes are merged to the release branch and the QA pipeline passes
2. Add a `## [<version>] - YYYY-MM-DD` section at the top of `CHANGELOG.md` with the user-visible Added/Changed/Fixed bullets
3. Append the output of `scripts/changelog-dependencies.sh <previous-tag>` to `### Changed` — one entry for every dependency shipped in the binary whose version changed
4. Add `[<version>]: https://github.com/whatwedo/dde/releases/tag/v<version>` at the top of the link list at the end of `CHANGELOG.md`
5. Commit signed + signed-off (do **not** touch `src/Application.php` — CI injects the version):

```bash
git add CHANGELOG.md
git commit -S --signoff -m "docs(changelog): document the v2.1.0 release"
```

6. Create the annotated, signed tag and push:

```bash
git tag -s v2.1.0 -m "v2.1.0"
git push origin <branch> v2.1.0
```

The tag name must start with `v` (e.g. `v2.0.0`, `v2.1.0`).

### Scheduled dependency release

Dependency updates merged by Renovate reach users without a manual release: the `Dependency Release` workflow (`.github/workflows/dependency-release.yml`) runs every Monday at 06:00 UTC and publishes a patch release when both hold:

- **every** commit since the latest tag is a `chore(deps):` or `fix(deps):` commit — a `docs:` or `refactor:` commit already hands the next release back to a human,
- a dependency that ships in the binary changed: the PHP runtime (`require.php` in `composer.json`) or a non-dev Composer package in `composer.lock`. Dev dependencies never reach the binary, and the website's npm dependencies deploy from `main` on their own.

It also skips the release when the latest tag is a pre-release.

It fails instead of releasing when the nightly workflow for the `main` head is not green.

The workflow adds a `### Changed` section and the release link to `CHANGELOG.md` — the entries `scripts/changelog-dependencies.sh` prints, one per updated shipped dependency with its old and new version — commits and tags it with the release bot's GPG key, pushes both to `main`, and dispatches the release workflow on the new tag. The dispatch is explicit because a tag pushed with the workflow's `GITHUB_TOKEN` triggers no other workflow.

Run it by hand from the Actions tab to release ahead of schedule. If the tag was pushed but the release did not start or failed, run the `Release` workflow on that tag from the Actions tab.

## CI Pipeline

Pushing a `v*` tag triggers the release workflow (`.github/workflows/release.yml`); a manual dispatch runs it only when started on a `v*` tag. The pipeline:

1. **Reads the PHP version** from `composer.json` (single source of truth)
2. **Runs quality checks**: ECS, PHPStan, Rector, and tests
3. **Builds the PHAR** using humbug/box
4. **Combines PHAR with micro.sfx** from static-php-cli to produce standalone binaries
5. **Uploads binaries** to a GitHub Release whose description is the version's `CHANGELOG.md` section; a tag containing a hyphen is published as a pre-release, and a tag without a changelog section fails the release
6. **Publishes the package repos** (APT, Alpine, Arch, RPM) and the Homebrew binaries to the `packages.dde.sh` S3 bucket
7. **Invalidates the CloudFront cache** so the freshly published repo indexes are served immediately instead of stale cached copies

The same publish + invalidate flow runs on every push to `main` via the nightly workflow (`.github/workflows/nightly.yml`), targeting the `*-nightly` repo paths in the same bucket and the same CloudFront distribution. There is no GitHub Release for nightlies; the package version is a UTC `YYYYMMDD.HHMM` stamp and the package name is `dde-nightly` with `Conflicts: dde`.

Both channels call the reusable multi-platform build workflow (`.github/workflows/build.yml`, `workflow_call`), passing an `app-version-string` input: the release workflow passes the git tag, the nightly workflow the short commit SHA.

### Version Injection

`Application::APP_VERSION` is committed as the literal placeholder `@APP_VERSION@`; `build.yml` substitutes it before `box compile`. A local `bin/console` keeps the placeholder and a constructor fallback reports `dev`. Never hand-edit the constant.

### Required secrets

The publishing jobs read these repository secrets:

| Secret                                        | Purpose                                                                                                   |
| --------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | IAM credentials for the `packages.dde.sh` bucket and the CloudFront invalidation                          |
| `CLOUDFRONT_DISTRIBUTION_ID`                  | The distribution that fronts `packages.dde.sh`; used by `aws cloudfront create-invalidation --paths "/*"` |
| `GPG_PRIVATE_KEY` / `GPG_PASSPHRASE`          | Sign the APT/Arch/RPM repo metadata                                                                       |
| `ALPINE_RSA_PRIVATE_KEY`                      | Sign the Alpine repo index                                                                                |
| `HOMEBREW_SSH_KEY`                            | Push the updated formula to the Homebrew tap repo                                                         |
| `RELEASE_BOT_GPG_PRIVATE_KEY`                 | Sign the commit and tag of a [scheduled dependency release](#scheduled-dependency-release)                |

The IAM principal behind `AWS_ACCESS_KEY_ID` needs `cloudfront:CreateInvalidation` (and `cloudfront:GetInvalidation`) on the distribution in addition to its existing `s3:*` access to the bucket. A whole-distribution `/*` invalidation counts as a single path for billing.

`RELEASE_BOT_GPG_PRIVATE_KEY` is an ASCII-armored private key without a passphrase whose UID is `whatwedo Bot <github@whatwedo.ch>`, the identity the workflow commits with. Its public key is registered on the [whatwedo-bot](https://github.com/whatwedo-bot) account, so GitHub shows the release commit and tag as verified.

### Target Platforms

The release pipeline produces binaries for 4 platforms:

| Platform | Architecture          | Binary Name        |
| -------- | --------------------- | ------------------ |
| macOS    | x86_64                | `dde-darwin-amd64` |
| macOS    | arm64 (Apple Silicon) | `dde-darwin-arm64` |
| Linux    | x86_64                | `dde-linux-amd64`  |
| Linux    | arm64                 | `dde-linux-arm64`  |

### Build Process

The `scripts/build.sh` script automates the binary creation:

1. Reads the PHP version from `composer.json`
2. Downloads the matching `micro.sfx` from static-php-cli
3. Combines `micro.sfx` + `bin/dde.phar` into a standalone executable

## Installation

Users install or update via the package managers listed in the [installation documentation](../getting-started/installation.md) (Homebrew, APT, Alpine, Arch Linux, RPM).
