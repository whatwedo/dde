---
title: "Reproducible Builds"
---


Two builds of `bin/dde.phar` (and therefore the `bin/dde` binary) from the same
commit are byte-identical, regardless of when or where they run. This lets
anyone re-build a published release and verify the artifact by comparing
checksums.

Reproducibility is driven entirely by the committer date of `HEAD`
(`SOURCE_DATE_EPOCH`), resolved once in `scripts/compile-phar.sh` and reused for
everything below. An externally provided `SOURCE_DATE_EPOCH` wins; when git is
unavailable (e.g. a source tarball) it falls back to the current time.

## Two sources of non-determinism

A PHAR built naively differs on every run for two unrelated reasons. Both have
to be pinned to the same instant, or the artifact is not reproducible.

### 1. The Symfony container timestamp

The compiled production container ships inside the PHAR
(`var/cache/prod/App_KernelProdContainer.php`). Symfony bakes
`container.build_time` (a wall-clock `time()`) and a `container.build_id`
derived from it into that file.

Symfony reads `SOURCE_DATE_EPOCH` during `cache:warmup`, so exporting it before
warming the cache makes both values deterministic. This is why the warmup step
lives inside `compile-phar.sh` (which exports the epoch first) rather than as a
separate build step.

### 2. The box PHAR timestamps

**humbug/box does not read `SOURCE_DATE_EPOCH`.** It stamps every PHAR entry
with the wall-clock build time and — without `--sort-compiled-files` — writes
entries in filesystem-iteration order. Setting `SOURCE_DATE_EPOCH` in the
environment has no effect on box output at all (verified against box 4.7.0).

box's own `timestamp` config key is the only knob that forces entry timestamps.
Since it is a static string in `box.json` and we want it to track the commit,
`compile-phar.sh` generates a throwaway `box.compile.json` (a copy of `box.json`
with `"timestamp": "@<epoch>"`) and compiles from that with
`--sort-compiled-files`. The generated config is git-ignored and removed on exit.

## Where it runs

`scripts/compile-phar.sh <box.phar>` is the single source of truth. It is
invoked identically by:

- `scripts/build.sh` (local `make build-binary`)
- the `build` target in the `Makefile` (local `make build`)
- the `Build PHAR (reproducible)` step in `.github/workflows/build.yml` (CI, for
  both the stable and nightly channels)

## Package metadata

Downstream packaging reuses the same committer date so the packages wrapping the
binary are deterministic too: `scripts/publish-apt.sh` stamps the APT `Release`
`Date:` and `scripts/publish-arch.sh` the `.PKGINFO` `builddate` from
`SOURCE_DATE_EPOCH`, propagated by `nightly.yml` / `release.yml`.

## Verifying

```bash
make build && sha256sum bin/dde.phar   # note the hash
make build && sha256sum bin/dde.phar   # must match
```

The compiled container inside the PHAR is the file that most easily
reintroduces non-determinism; if a build stops being reproducible, diff the two
PHARs entry-by-entry and check `App_KernelProdContainer.php` first.
