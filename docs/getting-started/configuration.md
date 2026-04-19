---
title: "Configuration"
---


dde uses two YAML configuration files: a global config for system-wide defaults and a project config for per-project settings.

## Global Configuration

Located at `~/.dde/config.yml`. This file is not created automatically — create it manually when you need to override defaults. All settings are optional.

```yaml
# Default output format (text or json)
output: text

# Upstream DNS servers for non-.test queries
dns:
  forward:
    - 9.9.9.9
    - 149.112.112.112

# SSH keys to load (empty = all keys in ~/.ssh/)
ssh:
  keys:
    - ~/.ssh/id_ed25519

# Default service versions
services:
  mariadb:
    version: "11.8"
  postgres:
    version: "18.3"
  valkey:
    version: "9"
```

## Project Configuration

Located at `.dde/config.yml` in the project root. Created by `dde project:init`.

```yaml
name: my-app
services:
  - mariadb
  - valkey
containers:
  web:
    shell: zsh
```

### Services

Services can be specified as a simple string (uses the global default version) or with an explicit version:

```yaml
services:
  - mariadb                    # uses default version
  - name: mariadb              # pins a specific version
    version: "10.6"
```

Available services: `mariadb`, `postgres`, `valkey`, `mailpit`.

### Containers

Per-container settings, keyed by the service name in your `docker-compose.yml`:

```yaml
containers:
  web:
    shell: zsh    # override shell for dde project:shell
```

#### Hostnames

dde assigns a meaningful hostname to every project container so the shell
prompt inside the container shows `<project>-<service>` (e.g. `meseto-web`)
instead of the random Docker container ID. The hostname is derived from the
project name and the compose service name and sanitized to a valid RFC 1123
label (lowercase, `a-z0-9-`, max 63 chars).

To opt out, set an explicit `hostname:` on the service in your
`docker-compose.yml` — dde leaves it untouched.

## Override Order

Settings are resolved with the following priority (highest first):

1. **CLI arguments** (`--output=json`)
2. **Project config** (`.dde/config.yml`)
3. **Global config** (`~/.dde/config.yml`)
4. **Built-in defaults**

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DDE_CONFIG_DIR` | `~/.dde` | Global configuration directory |
| `DDE_DATA_DIR` | `~/.dde/data` | Data directory (services, certs) |
| `DDE_UID` | Current user's UID | UID for the container user |
| `DDE_GID` | Current user's GID | GID for the container user |
| `DDE_SHELL` | Auto-detected (zsh > bash > sh) | Override container shell |
