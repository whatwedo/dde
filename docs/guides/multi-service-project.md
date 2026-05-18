---
title: "Guide: Multi-Service Project"
---


This guide covers projects with multiple application containers — for example a web frontend, a background worker, a scheduler, and a project-local third-party container that is not part of dde's [built-in service catalogue](../services/overview.md) (databases, caches, mail).

## Prerequisites

- dde installed and `dde system:doctor` passing

## Architecture

A typical multi-service project has several containers defined in `docker-compose.yml`, all sharing the same codebase but running different processes:

```
my-app/
  docker-compose.yml
  Dockerfile
  .dde/
    config.yml
  src/
  ...
```

## 1. Docker Compose Configuration

```yaml
services:
  web:
    build:
      context: .
      target: dev
    volumes:
      - .:/var/www
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`my-app.test`)'
      - 'traefik.http.routers.web.tls=true'

  worker:
    build:
      context: .
      target: dev
    volumes:
      - .:/var/www
    command: ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600"]

  scheduler:
    build:
      context: .
      target: dev
    volumes:
      - .:/var/www
    command: ["supercronic", "/etc/crontab"]

  storage:
    image: dxflrs/garage:v1.0.1
    volumes:
      - garage_meta:/var/lib/garage/meta
      - garage_data:/var/lib/garage/data
      - ./garage.toml:/etc/garage.toml:ro

volumes:
  garage_meta:
  garage_data:
```

A few things to notice:

- Shared infrastructure like a database, cache, or mail server is **not** declared inline. Those belong in `.dde/config.yml` under `services:` (see next step) so dde can run them as versioned, machine-wide containers instead of spinning up a dedicated copy per project.
- `storage` is a project-local third-party container ([Garage](https://garagehq.deuxfleurs.fr/), an S3-compatible object store). It is declared inline precisely because it is not part of dde's built-in service catalogue. The application reaches it at `storage:3900` over the per-project network, just like any compose-defined sibling.
- The file declares no `networks:` block and no `dde_ssh-agent_socket-dir` volume — the per-project `dde-services-<project>` (or `dde-services-<project>-<suffix>` for a worktree) network and the SSH-Agent volume are injected by the overlay that `project:up` generates.

## 2. Initialize the Project

```bash
cd ~/projects/my-app
dde project:init --name=my-app --services=mariadb,valkey --container=web --shell=bash
```

The `--container=web` flag sets `web` as the default container for `dde project:shell` and `dde project:exec`.

## 3. dde Configuration

`.dde/config.yml`:

```yaml
name: my-app
services:
    - mariadb
    - valkey
containers:
    web:
        shell: bash
```

## 4. How dde Handles Multiple Services

When `dde project:up` runs, it generates a docker-compose override for **every service** in the compose file. Every service gets:

- Attached to the per-project `dde-services-<project>` (or `dde-services-<project>-<suffix>` for a worktree) network
- A `dde.managed=true` label

In addition, services whose image has a shell (`web`, `worker`, `scheduler` in this example) also get:

- The entrypoint set to `/dde/entrypoint.sh`, with the original entrypoint and command from the image preserved as the new `command`
- The built-in and project adapters mounted
- `DDE_UID`, `DDE_GID`, and `SSH_AUTH_SOCK` environment variables
- The shared SSH-Agent socket volume mounted at `/tmp/ssh-agent`

This means all shell-bearing application containers (web, worker, scheduler) get the `dde` user created with matching UID/GID, regardless of which one is configured as the default container. Shell-less images like the `storage` (Garage) container are left otherwise untouched — the dde entrypoint would fail on them, and there is no interactive shell to attach SSH keys to anyway.

## 5. Execute Commands in Specific Containers

Use the `--service` (`-s`) option to target a specific service:

```bash
# Run in web container (default, configured via --container in project:init)
dde project:exec php bin/console cache:clear

# Run in worker container
dde project:exec -s worker -- php bin/console messenger:consume --limit=10

# Run in scheduler container
dde project:exec -s scheduler cat /etc/crontab
```

## 6. View Logs Across Services

```bash
# All containers
dde project:logs

# Specific container
dde project:logs -s worker

# Follow logs
dde project:logs --follow -s worker
```

## 7. Traefik Labels for Multiple Web Services

If multiple containers serve HTTP traffic, add Traefik labels to each:

```yaml
services:
  web:
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`my-app.test`)'
      - 'traefik.http.routers.web.tls=true'

  api:
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.api.rule=Host(`api.my-app.test`)'
      - 'traefik.http.routers.api.tls=true'
```

Both hostnames receive trusted TLS certificates from dde's certificate manager.

## 8. Hooks and Multi-Service Projects

Hooks run on the host machine and can interact with any container:

```bash
#!/usr/bin/env bash
# .dde/hooks/project.up.post/01-setup.sh
set -euo pipefail

# Run migrations in web container
dde project:exec -s web php bin/console doctrine:migrations:migrate --no-interaction

# Warm up caches in all relevant containers
dde project:exec -s web php bin/console cache:warmup
dde project:exec -s worker php bin/console cache:warmup
```

## Related

- [Project Lifecycle](../getting-started/commands.md) -- up, down, restart commands
- [Project Exec](../getting-started/commands.md) -- executing commands in containers
- [Hooks](../extending/hooks.md) -- lifecycle hook scripts
