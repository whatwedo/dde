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

## Targeting a project from another directory

All commands accept a global `--project-dir` / `-C` option (like `git -C`). Prefer it over `cd` when your working directory is not inside the project:

```bash
dde -C /path/to/project project:exec composer install
```

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
dde project:up                        # Start the project (if already running, no-op)
dde project:stop                      # Stop containers without removing them
dde project:down                      # Stop and remove containers
dde project:update                    # Full rebuild: pull images, rebuild, restart
dde project:logs --tail=50            # Show recent logs
```

**When to use which command:**
- "start the project" → `dde project:up`
- "rebuild", "update", "pull latest", "refresh environment" → `dde project:update`
- "restart" (no rebuild, preserve containers) → `dde project:stop && dde project:up`

Options for up/down/update:
- `--skip-hooks` — Skip pre/post hook scripts
- `--build` — Force rebuild images (up only)

## System services

dde runs global services (Traefik, dnsmasq, Mailpit, optionally a managed SSH-Agent). Manage them with:

```bash
dde system:status --output=json   # Show status of all services
dde system:up                      # Start all services
dde system:stop                    # Stop all dde containers without removing them
dde system:down                    # Stop and remove all services
dde system:update                  # Rebuild global service images and refresh integrations
dde system:doctor --output=json   # Run health checks
```

### When to suggest `system:update`

When the user has installed a new dde release (for example via `brew upgrade
dde` or `apt upgrade dde`), the follow-up command is `dde system:update`.
Most package managers call it automatically via their post-install hook, but
Homebrew surfaces it only as caveats — there the user has to run the
command manually.

`system:update` removes every dde container, rebuilds the global service
images with `--pull`, and refreshes the integrations that are bound to the
dde binary (shell completion, this skill). It does **not** touch the root
CA, DNS configuration, or the default certificate — those are owned by
`system:install` (one-off).

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

## Git worktrees

dde has first-class support for [Git worktrees](https://git-scm.com/docs/git-worktree): when you run `dde project:up` from a non-main worktree checkout, dde automatically assigns that checkout its own hostname (`<suffix>.<project>.test`, a subdomain of the project host) **and automatically rewrites every database-URL-valued environment variable** to point at a worktree-specific database name.

### What dde does automatically per worktree

Given a project named `my-app` and a worktree at `~/projects/my-app-feature-x`:

|                                                                                                               | Main checkout         | Worktree checkout                                     |
| ------------------------------------------------------------------------------------------------------------- | --------------------- | ----------------------------------------------------- |
| URL                                                                                                           | `https://my-app.test` | `https://feature-x.my-app.test`                       |
| Every env var holding a DB URL (`mysql://…`, `postgres://…`, …) — `DATABASE_URL`, `GUACAMOLE_DATABASE_URL`, … | `…/my_app?…`          | `…/my_app_feature_x?…`                                |
| Container env for `APP_URL`, `MERCURE_URL`, `TRUSTED_HOSTS`, …                                                | unchanged             | main `.test` hostname replaced with worktree hostname |

The DB URL rewrite is scheme-driven: any env var whose value starts with `mysql://`, `mariadb://`, `postgres://`, `postgresql://`, or `pgsql://` is candidate. It runs only when the project declares a matching dde database service (`mariadb` or `postgres`) in `.dde/config.yml`; otherwise the URL is assumed to point at an external database and is left untouched.

You do **not** need to edit `.env` or `docker-compose.yml` per worktree — dde patches all of this in the compose override at `project:up` time. The base `docker-compose.yml` and the `.env` file are never modified.

### Detecting a worktree

```bash
dde project:describe --output=json | jq '.data.worktree'
```

Returns `null` for the main checkout, otherwise an object with `branch`, `suffix` and `mainDirectory`.

### Opening the right URL

`dde project:open` always picks the worktree hostname when run from a worktree. Use `dde project:open --url-only` to print the URL without launching a browser.

### Seeding the worktree database

The worktree database does not exist yet. dde rewrites each DB-URL env var to point at a new name (e.g. `my_app_feature_x`), but it does **not** issue `CREATE DATABASE`. You have to run a bootstrap step once per worktree.

Most projects ship a one-shot install script (f.ex. `make install`) that creates the database, runs migrations and loads fixtures. Run it inside the worktree container:

```bash
dde project:exec make install
```

As an alternative, when you want the worktree to start from main's current state (this both creates the DB and loads the dump):

```bash
# In main: export
cd ~/projects/my-app
dde project:db:export /tmp/seed.sql

# In worktree: import into the auto-named DB
cd ~/projects/my-app-feature-x
dde project:db:import /tmp/seed.sql
```

### Running main and worktree in parallel

Both can be `project:up` at the same time: each worktree owns its own per-project Docker network (`dde-services-<project>-<suffix>`; main keeps `dde-services-<project>`), and the shared `mariadb`/`postgres` service container is attached to every network that needs it under the canonical alias. `project:down` in one worktree removes only that worktree's network — main and sibling worktrees are unaffected.

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
