---
name: dde
description: Use when working in a project with a .dde/ directory, when the user mentions dde, or when you need to run commands in Docker containers, start/stop projects, access databases, or read logs in a local development environment.
---

# dde — Docker Development Environment

dde manages local Docker development environments. When a project has a `.dde/` directory, ALL project-dependent commands must run inside the container — never directly on the host.

## Detecting a dde project

A dde project has a `.dde/config.yml` file. When you encounter one, use `dde` for all project operations.

## Getting project information

Always start by understanding the project:

```bash
dde project:describe --output=json
```

This returns the project URL, running containers, configured services, and database connection details. Use this output to inform all subsequent actions.

## Running commands in the container

**This is the most important rule.** Never run project-dependent commands directly on the host. Always use:

```bash
dde project:exec <command>
```

Examples:

```bash
dde project:exec composer install
dde project:exec php bin/console cache:clear
dde project:exec npm run build
dde project:exec python manage.py migrate
```

Options:
- `--service=SERVICE` — Target a specific container (default: first container)
- `--root` — Execute as root user

## Project lifecycle

```bash
dde project:up              # Start the project (if already running, no-op)
dde project:down            # Stop and remove containers
dde project:restart         # Restart containers
dde project:update          # Full rebuild: pull images, rebuild, restart
dde project:logs --tail=50  # Show recent logs
```

**When to use which command:**
- "start the project" → `dde project:up`
- "rebuild", "update", "pull latest", "refresh environment" → `dde project:update`
- "restart" (no rebuild) → `dde project:restart`

Options for up/down/restart/update:
- `--skip-hooks` — Skip pre/post hook scripts
- `--build` — Force rebuild images (up/restart only)

## System services

dde runs global services (Traefik, dnsmasq, Mailpit, SSH-Agent). Manage them with:

```bash
dde system:status --output=json   # Show status of all services
dde system:up                      # Start all services
dde system:down                    # Stop all services
dde system:doctor --output=json   # Run health checks
```

## Database operations

Open a database shell:

```bash
dde project:db
```

Export and import:

```bash
dde project:db:export dump.sql
dde project:db:import dump.sql
```

Snapshots (fast save/restore without files):

```bash
dde project:db:snapshot:create --name=before-migration
dde project:db:snapshot:restore before-migration
dde project:db:snapshot:list
```

Options for all DB commands:
- `--service=SERVICE` — Target specific DB service
- `--database=DATABASE` — Database name (default: project name)

## Debugging

When something is not working:

1. Check system health: `dde system:doctor --output=json`
2. Check container logs: `dde project:logs --tail=50`
3. Check project status: `dde project:status --output=json`

## JSON output

Most commands support `--output=json` for structured output:

```json
{
  "status": "ok",
  "message": "",
  "data": { ... },
  "errors": []
}
```
