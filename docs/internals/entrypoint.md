---
title: "Entrypoint"
---


The dde entrypoint (`resources/entrypoint.sh`) is a POSIX shell script that runs as the container's entrypoint. It prepares the container environment for development and then hands off to the original command.

## Execution Flow

### 1. Ensure dde User Exists

The entrypoint creates a `dde` user and group matching the host UID/GID (passed via `DDE_UID` and `DDE_GID` environment variables, defaulting to 1000).

Because the entrypoint runs on **arbitrary** base images, it first detects which user/group tooling the image ships, then fires exactly one command for that dialect:

1. **shadow** (`useradd`/`groupadd`) -- preferred when present (Debian, and Alpine once shadow is installed); flags are identical on every distro.
2. **busybox** (`adduser -u -G -D` / `addgroup -g`) -- base Alpine.
3. **debian** (`adduser --uid --ingroup --disabled-password` / `addgroup --gid`) -- Debian's Perl `adduser` when `useradd` is absent.
4. **Manual /etc/passwd** -- genuine last resort when no usable tool exists or the tool fails.

The dialect is resolved **once** into a `DIALECT` variable. `adduser`/`addgroup` is the same command name for two incompatible programs (BusyBox's C binary vs Debian's Perl script), and both exit `0` on `--help`, so the only reliable discriminator is `adduser --help 2>&1 | grep -qi busybox`. Feeding BusyBox short flags to Debian's `adduser` makes it abort with an "Option is ambiguous" usage dump and leaves the user uncreated -- the regression that flooded a WordPress container's log and forced the raw `/etc/passwd` fallback on every start.

Group creation is skipped when `DDE_GID` is already taken; the user then joins that existing group (resolved via `getent group "$DDE_GID"`) instead of colliding on the GID. On failure each branch emits a single `dde-entrypoint: warning: ...` line to stderr -- loud enough to diagnose, never fatal, so images that ran fine before dde injected the entrypoint keep starting.

### 2. UID/GID Remapping

If the `dde` user already exists (e.g. from the dev layer) but has a different UID/GID than requested:

1. **usermod/groupmod** -- preferred method for remapping
2. **sed on /etc/passwd** -- fallback if usermod is not available
3. **chown home directory** -- updates ownership of `/home/dde`

### 3. Shell Detection

The user's shell is set based on the following priority:

1. `DDE_SHELL` environment variable (if set, uses `/bin/$DDE_SHELL`)
2. `/bin/zsh` (if available)
3. `/bin/bash` (if available)
4. `/bin/sh` (fallback)

The detected shell is set as the login shell for the `dde` user via `usermod -s` or direct `/etc/passwd` editing.

### 4. Run Built-in Adapters

Scripts in `/dde/adapters/` (mounted from `resources/adapters/`) are sourced and executed:

```sh
for adapter in "$DDE_ADAPTERS_DIR"/*.sh; do
    [ -f "$adapter" ] || continue
    . "$adapter"
    if type detect >/dev/null 2>&1 && detect; then
        configure || true
    fi
    unset -f detect configure 2>/dev/null || true
done
```

Each adapter script must define `detect()` and `configure()` functions. If `detect()` returns 0 (success), `configure()` is called. Functions are unset after each adapter to prevent conflicts.

Built-in adapters: `nginx.sh`, `php-fpm.sh`, `apache.sh`.

### 5. Run Project Adapters

Scripts in `/dde/adapters-project/` (mounted from `.dde/adapters/`) are processed identically to built-in adapters. This allows projects to add custom setup logic.

### 6. Exec Original Command

```sh
exec "$@"
```

The arguments passed to the entrypoint (`$@`) are the original entrypoint and CMD from the Docker image. The compose override sets these as the command arguments, so `exec "$@"` chains to the original startup sequence.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DDE_UID` | 1000 | User ID for the dde user |
| `DDE_GID` | 1000 | Group ID for the dde user |
| `DDE_SHELL` | (unset) | Override shell detection (e.g. `bash`, `zsh`) |
| `DDE_ADAPTERS_DIR` | `/dde/adapters` | Path to built-in adapter scripts |

## Key Design Decisions

- **POSIX sh**: The script uses `/bin/sh` (not bash) for maximum compatibility across Alpine, Debian, and other base images.
- **Idempotent**: Safe to run multiple times (uses `|| true` for operations that may already be done).
- **Non-destructive**: If user creation fails for any reason, the container still starts with the original CMD.
- **exec replaces shell**: Using `exec "$@"` ensures the original process becomes PID 1, receiving signals correctly.
