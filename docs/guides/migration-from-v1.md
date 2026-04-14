---
title: "Migration from v1 to v2"
---


dde v2 is a complete rewrite. v1 and v2 cannot run side by side — you must fully remove v1 before installing v2.

## Overview of changes

| Component         | v1                          | v2                              |
|-------------------|-----------------------------|---------------------------------|
| Language          | Bash                        | PHP 8.5 + static-php-cli        |
| Reverse proxy     | nginx-proxy                 | Traefik v3                       |
| TLS certificates  | Custom openssl CA           | mkcert (OS-trusted root CA)     |
| Mail catcher      | MailCrab                    | Mailpit                          |
| Configuration     | Shell variables             | YAML (`~/.dde/config.yml`, `.dde/config.yml`) |
| Worktree support  | None                        | Automatic hostname per worktree  |
| Plugin system     | None                        | `.dde/plugins/` directory        |
| Global config     | None                        | `~/.dde/config.yml`             |

## Step-by-step migration

### 1. Stop and remove all dde v1 projects

Stop every running dde project. To remove only dde-managed containers:

```bash
docker rm -f $(docker ps -a --filter "name=dde-" -q)
```

> **Warning:** Do not use `docker rm -f $(docker ps -aq)` as this removes **all** containers on your machine, including those unrelated to dde.

### 2. Destroy v1 system services

```bash
dde system:destroy
```

### 3. Clean up shell configuration

Remove the dde v1 aliases and autocomplete from your `~/.zshrc` or `~/.bashrc`.

### 4. Optionally remove v1 data

```bash
rm -rf ~/dde
```

> **Warning:** This deletes all database contents managed by v1. Make backups first if needed.

### 5. Install dde v2

Install the v2 binary following the [installation guide](../getting-started/installation.md), then run the system setup:

```bash
dde system:install
```

### 6. Initialize your projects

Navigate to each project and initialize it for v2:

```bash
cd ~/projects/my-app
dde project:init
dde project:up
```

`project:init` creates the `.dde/` directory, detects your `docker-compose.yml`, adds the dde network and Traefik labels, and configures SSH agent volume mounts.

Open `https://my-app.test` in your browser to verify.

## Breaking changes

| v1 command/feature             | v2 equivalent                    | Notes                                    |
|-------------------------------|----------------------------------|------------------------------------------|
| `dde project fix-permissions` | Removed                          | No longer needed (automatic UID/GID mapping) |
| `dde project exec-root`      | `dde project:exec --root`       | Now a flag on `project:exec`             |
| `VIRTUAL_HOST` env var        | Traefik labels (auto-generated) | Set automatically by `project:init`      |
| Custom openssl CA             | mkcert root CA                  | Installed via `system:install`           |
| MailCrab                      | Mailpit                         | Different web UI, same SMTP interface    |
| nginx-proxy                   | Traefik v3                      | Routing via labels instead of env vars   |
