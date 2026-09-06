---
title: "Adding an Adapter"
---


Adapters are shell scripts that customize the container entrypoint for specific base images. They handle service-specific configuration like user permissions, config file adjustments, and directory ownership.

## How Adapters Work

When a container starts with the dde entrypoint, the entrypoint script sources each adapter and calls two functions:

1. **`detect()`** -- returns 0 (true) if this adapter applies to the current container
2. **`configure()`** -- performs the configuration (runs only if `detect()` returned 0)

After each adapter runs, the functions are unset to avoid conflicts.

## Built-in Adapters

dde ships four built-in adapters in `resources/adapters/`:

| Adapter         | Detects                             | Configures                                                                        |
| --------------- | ----------------------------------- | --------------------------------------------------------------------------------- |
| `nginx.sh`      | `nginx` binary present              | Updates `nginx.conf` user directive to `dde`, fixes cache/log directory ownership |
| `php-fpm.sh`    | `php-fpm` binary present            | Updates PHP-FPM pool user/group to `dde`                                          |
| `apache.sh`     | `apache2` or `httpd` binary present | Updates Apache user/group directives                                              |
| `frankenphp.sh` | `frankenphp` and `chpst` present    | Drops the runit service to `dde` via `chpst`, fixes Caddy state dir ownership     |

### Example: nginx.sh

```bash
detect() {
    command -v nginx >/dev/null 2>&1
}

configure() {
    # Update nginx.conf user directive
    if [ -f /etc/nginx/nginx.conf ]; then
        sed -i 's/^user .*/user dde;/' /etc/nginx/nginx.conf
    fi
    # Fix cache/log directories
    for dir in /var/cache/nginx /var/log/nginx /var/run; do
        [ -d "$dir" ] && chown -R dde:dde "$dir" 2>/dev/null || true
    done
}
```

## Step 1: Create the Adapter Script

Create a new `.sh` file in `resources/adapters/`:

```bash
# resources/adapters/myservice.sh

detect() {
    # Return 0 if this adapter should run
    command -v myservice >/dev/null 2>&1
}

configure() {
    # Perform configuration
    # - Fix file permissions for the dde user
    # - Update config files to run as dde user
    # - Create required directories
    if [ -f /etc/myservice/config.conf ]; then
        sed -i 's/^user .*/user = dde/' /etc/myservice/config.conf
    fi
    chown -R dde:dde /var/lib/myservice 2>/dev/null || true
}
```

### Guidelines

- `detect()` should be fast and non-destructive (just check if a binary or config file exists)
- `configure()` should be idempotent (safe to run multiple times)
- Use `2>/dev/null || true` for operations that might fail in some environments
- Only modify what is necessary for the `dde` user to run the service
- Do not install packages -- adapters run at container start time, not build time

## Step 2: Project-Specific Adapters

Projects can provide their own adapters in `.dde/adapters/`. These are mounted at `/dde/adapters-project/` inside the container and run after built-in adapters.

This is useful for application-specific setup that does not belong in a built-in adapter:

```bash
# .dde/adapters/setup-app.sh

detect() {
    [ -f /var/www/html/composer.json ]
}

configure() {
    chown -R dde:dde /var/www/html/var 2>/dev/null || true
}
```

## Step 3: Test Manually

1. Start a project that uses the target base image
2. Check container logs for adapter execution:

```bash
dde project:logs
```

3. Shell into the container and verify the configuration was applied:

```bash
dde project:shell
```

## Adapter Resolution

The `AdapterRegistry` class manages adapter discovery:

- Built-in adapters are loaded from `resources/adapters/` (or extracted from PHAR to `~/.dde/data/resources/adapters/`)
- Project adapters are loaded from `.dde/adapters/`
- Both sets are mounted into the container and executed by the entrypoint in alphabetical order
- Built-in adapters run first, then project adapters
