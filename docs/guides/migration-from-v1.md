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

### 1. Stop and remove all dde v1 projects and system services

Stop every running dde project, then remove all dde v1 containers and system services. You have two options:

**Option A — Use the dde v1 command (recommended):**

```bash
dde system:destroy
```

This stops and removes all dde v1 projects and system services in one step.

**Option B — Use docker directly:**

If `dde system:destroy` no longer works (for example, because dde v1 is already partially uninstalled), or if you want to be extra sure all dde-managed containers are gone, run:

```bash
docker rm -f $(docker ps -a --filter "name=dde-" -q)
```

You can also run both commands in sequence — first `dde system:destroy`, then the `docker rm` command as a safety net. Note that `docker rm -f` will exit with an error if no matching containers exist (which is expected after `dde system:destroy` has already removed them).

> **Warning:** Do not use `docker rm -f $(docker ps -aq)` as this removes **all** containers on your machine, including those unrelated to dde.

### 2. Clean up shell configuration

Remove the dde v1 aliases and autocomplete from your `~/.zshrc` or `~/.bashrc`.

### 3. Optionally remove v1 data

```bash
rm -rf ~/dde
```

> **Warning:** This deletes all database contents managed by v1. Make backups first if needed.

### 4. Install dde v2

Install the v2 binary following the [installation guide](../getting-started/installation.md), then run the system setup:

```bash
dde system:install
```

### 5. Initialize your projects

Navigate to each project and initialize it for v2:

```bash
cd ~/projects/my-app
dde project:init
dde project:up
```

`project:init` creates the `.dde/` directory, detects your `docker-compose.yml`, adds the dde network and Traefik labels, and configures SSH agent volume mounts.

Open `https://my-app.test` in your browser to verify.

#### Remove dde user from existing Dockerfiles

If the existing `dev` stage of a Dockerfile references the `dde` user (e.g. in `chown` instructions), those references must be removed. v2 no longer has a `dde` user available during the docker bild.

Any customisations that previously relied on the `dde` user can be implemented using **hooks** or **service adapters** instead:

- [**Hooks**](../extending/hooks.md) (`.dde/hooks/`): shell scripts executed at defined lifecycle points (e.g. `post-up`).
- [**Service adapters**](../extending/service-adapters.md) (`.dde/adapters/`): allow project-specific configuration of services such as nginx or php-fpm.

## Breaking changes

| v1 command/feature             | v2 equivalent                    | Notes                                    |
|-------------------------------|----------------------------------|------------------------------------------|
| `dde project fix-permissions` | Removed                          | No longer needed (automatic UID/GID mapping) |
| `dde project exec-root`      | `dde project:exec --root`       | Now a flag on `project:exec`             |
| `VIRTUAL_HOST` env var        | Traefik labels (auto-generated) | Set automatically by `project:init`      |
| Custom openssl CA             | mkcert root CA                  | Installed via `system:install`           |
| MailCrab                      | Mailpit                         | Different web UI, same SMTP interface    |
| nginx-proxy                   | Traefik v3                      | Routing via labels instead of env vars   |
