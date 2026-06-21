---
title: "Service Adapters"
---


Service adapters are shell scripts that configure services inside Docker containers at startup. They handle tasks like setting the correct user, adjusting file permissions, and updating service configuration to run under the `dde` user.

## How Adapters Work

When dde starts a project, it overrides each container's entrypoint with `/dde/entrypoint.sh`. This entrypoint script:

1. Creates the `dde` user with the host user's UID/GID (`DDE_UID`/`DDE_GID` environment variables)
2. Detects and configures the login shell
3. Runs **built-in adapters** from `/dde/adapters/` (mounted from dde's resources)
4. Runs **project adapters** from `/dde/adapters-project/` (mounted from `.dde/adapters/`)
5. Executes the original container entrypoint and command

For each adapter script, the entrypoint sources it and calls `detect()`. If `detect()` returns 0 (success), `configure()` is called. Both functions are then cleaned up before processing the next adapter.

## Adapter Interface

Every adapter script must define two shell functions:

### `detect()`

Returns 0 if this adapter applies to the current container. Typically checks for the presence of a binary:

```bash
detect() {
    command -v nginx >/dev/null 2>&1
}
```

### `configure()`

Performs the actual configuration. Runs only if `detect()` returned 0:

```bash
configure() {
    # Example: set nginx to run as dde user
    if [ -f /etc/nginx/nginx.conf ]; then
        sed -i 's/^user .*/user dde;/' /etc/nginx/nginx.conf
    fi
    for dir in /var/cache/nginx /var/log/nginx /var/run; do
        [ -d "$dir" ] && chown -R dde:dde "$dir" 2>/dev/null || true
    done
}
```

## Built-in Adapters

dde ships with three built-in adapters in `resources/adapters/`:

| Adapter | Detects | Configures |
|---|---|---|
| apache.sh | `apache2` or `httpd` | Updates run user/group to `dde` |
| nginx.sh | `nginx` | Updates the `user` directive and directory ownership to `dde` |
| php-fpm.sh | a `www.conf` pool config | Updates pool user/group and listen owner/group to `dde` |

> **Resolved group, not literal `dde`.** When `DDE_GID` maps onto a group that already exists in the image (e.g. gid 20 → `dialout` on Debian, which is `staff` on the macOS host), the entrypoint reuses that group rather than creating one named `dde`. The adapters therefore resolve the dde user's real primary group via `id -gn dde` and write *that* name — hardcoding `dde` would point nginx/php-fpm at a non-existent group and break startup.

### `apache.sh`

Detects `apache2` or `httpd` and updates the run user/group to `dde` in:
- `/etc/apache2/envvars` (Debian/Ubuntu)
- `/etc/httpd/conf/httpd.conf` (Alpine/CentOS)

### `nginx.sh`

Detects `nginx` and:
- Rewrites the `user` directive wherever it lives in the main context — both `/etc/nginx/nginx.conf` and any `/etc/nginx/directive.d/*.conf` include (the whatwedo base image ships it as `directive.d/05-user.conf`, not in `nginx.conf`)
- Fixes ownership of `/var/cache/nginx`, `/var/log/nginx`, and `/var/run`

### `php-fpm.sh`

Detects php-fpm by the presence of a `www.conf` pool config — not the binary name, which is unreliable (Debian ships `php-fpm8.4`, not `php-fpm`). Updates the FPM pool configuration (`www.conf`) to run as `dde`:
- Sets `user`, `group`, `listen.owner`, and `listen.group` directives
- Searches common FPM config paths across distributions (`/etc/php/*/fpm/pool.d/www.conf`, `/usr/local/etc/php-fpm.d/www.conf`)

## Custom Project Adapters

Place custom adapter scripts in `.dde/adapters/` in your project directory. They are automatically mounted into the container at `/dde/adapters-project/` and executed after the built-in adapters.

### Execution Order

1. Built-in adapters (`/dde/adapters/*.sh`) -- alphabetically sorted
2. Project adapters (`/dde/adapters-project/*.sh`) -- alphabetically sorted

Use numeric prefixes to control order within each group:

```
.dde/adapters/
  01-custom-service.sh
  02-permissions.sh
```

### Example: Custom Service Adapter

```bash
# .dde/adapters/01-supervisord.sh
detect() {
    command -v supervisord >/dev/null 2>&1
}

configure() {
    # Update supervisord to run programs as dde user
    if [ -f /etc/supervisor/supervisord.conf ]; then
        sed -i 's/^user=.*/user=dde/' /etc/supervisor/conf.d/*.conf 2>/dev/null || true
    fi
}
```

### Example: Fix Application Permissions

```bash
# .dde/adapters/99-permissions.sh
detect() {
    [ -d /var/www ]
}

configure() {
    chown -R dde:dde /var/www/var 2>/dev/null || true
    chown -R dde:dde /var/www/public/uploads 2>/dev/null || true
}
```

## Important Notes

- Adapters run as **root** inside the container (before the process drops to the `dde` user).
- The `configure()` function should be **idempotent** -- it may run every time the container starts.
- Failures in `configure()` are silently caught (`configure || true`), so a failing adapter does not prevent the container from starting.
- Adapter scripts are sourced (not executed), so they share the same shell environment. Functions are cleaned up (`unset -f detect configure`) between adapters.

## Related

- [Hooks](hooks.md) -- scripts that run on the host during project lifecycle events
- [Plugins](plugins.md) -- custom dde commands
