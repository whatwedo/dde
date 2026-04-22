---
title: "Guide: Git Worktrees"
---

dde supports [Git worktrees](https://git-scm.com/docs/git-worktree) natively. When you run a project from a worktree checkout, dde automatically detects this and gives the worktree its own hostname **and** its own database namespace, so you can run multiple branches of the same project **simultaneously** without any manual setup.

## TL;DR

```bash
# Main checkout
cd ~/projects/my-app
dde project:up           # https://my-app.test, DB: my_app

# Feature worktree
git worktree add ~/projects/my-app-feature-x feature/x
cd ~/projects/my-app-feature-x
dde project:up           # https://my-app-feature-x.test, DB: my_app_feature_x
```

Both worktrees stay reachable at the same time. Each gets its own per-project Docker network, so a worktree can run a different service version than main (e.g. upgrading from Postgres 16 to 18 on a branch). Both write to different databases so the branches never corrupt each other's state.

## How Detection Works

When `dde project:up` runs, `WorktreeManager::detect()` executes `git worktree list --porcelain` in the project directory. A worktree is detected when:

1. The `git worktree list` command succeeds and returns at least two entries.
2. The current project directory matches a **non-main** worktree entry (the first entry is always the main worktree).

If the project directory is the main worktree or the repository has no worktrees, detection returns `null` and standard hostname resolution applies.

## Hostname Generation

The hostname for a worktree follows the pattern:

```
<project-name>-<suffix>.test
```

Where `<suffix>` is derived from the worktree directory name through `IdentifierSanitizer::forHostname()`:

1. **Strip project name prefix** -- if the directory starts with the project name followed by a hyphen, that prefix is removed. For example, `my-app-feature-x` becomes `feature-x`.
2. **Transliterate unicode** -- non-ASCII characters are converted to ASCII equivalents using Symfony's `AsciiSlugger` (e.g. `ue` for `ü`).
3. **Replace invalid characters** -- anything that is not `a-z`, `0-9`, or `-` is replaced with a hyphen.
4. **Collapse hyphens** -- consecutive hyphens are merged into one.
5. **Trim hyphens** -- leading and trailing hyphens are removed.
6. **Fallback** -- if the suffix is empty after processing, it defaults to `worktree`.
7. **Truncate** -- the result is capped at 63 characters (DNS label limit).

### Examples

Assuming project name `my-app`:

| Worktree directory | Hostname |
|-------------------|----------|
| `~/projects/my-app` (main) | `my-app.test` |
| `~/projects/my-app-feature-x` | `my-app-feature-x.test` |
| `~/projects/my-app-PROJ-123` | `my-app-proj-123.test` |
| `~/projects/my-app-hotfix` | `my-app-hotfix.test` |

If the directory name does not start with the project name:

| Worktree directory | Hostname |
|-------------------|----------|
| `~/worktrees/bugfix-login` | `my-app-bugfix-login.test` |

## Environment Overrides

When running in a worktree, dde rewrites every environment value the base `docker-compose.yml` declares for the primary container so each worktree gets an isolated runtime.

### Hostname rewrite

Any occurrence of the main project hostname (`<project-name>.test`) is replaced with the worktree hostname in all environment values. This applies to both YAML map (`APP_URL: https://my-app.test`) and list (`- APP_URL=https://my-app.test`) formats.

Typical candidates that benefit from this:

- `APP_URL`
- `MERCURE_URL`
- `TRUSTED_HOSTS`
- Anything else that hard-codes the project's `.test` domain.

### DATABASE_URL rewrite

If the primary container declares a `DATABASE_URL` in its compose environment, the database name in the URL path segment is extended with the sanitized worktree suffix (via `IdentifierSanitizer::forDatabaseSuffix`, separator `_`). The rest of the URL — scheme, credentials, host, port, query string — stays untouched, including percent-encoded values.

| Main | Worktree `my-app-feature-x` |
|---|---|
| `mysql://root:root@mariadb/my_app?serverVersion=11.8.0-MariaDB` | `mysql://root:root@mariadb/my_app_feature_x?serverVersion=11.8.0-MariaDB` |

The final database name is clamped to 63 characters (MySQL/PostgreSQL identifier limit). The rewrite is skipped if the URL has no path segment (`mysql://host:3306` or `mysql://host:3306/`).

> **You are responsible for creating the worktree database.** dde does not create it automatically. Use `dde database:snapshot` on the main project and restore the dump into the worktree DB, or run your project's migration command inside the worktree container.

## Parallel Execution

Main and worktree can run side-by-side. The Traefik routers emitted by dde are **unique per hostname**, so there is no router-name collision between the two containers. The override file is written with YAML's `!override` tag, so the labels from the base `docker-compose.yml` are **replaced** (not merged) on the worktree container.

Each worktree gets its own per-project Docker network, named `dde-services-<project>-<suffix>`. The main checkout keeps the historical `dde-services-<project>`. A service container (e.g. `dde-postgres-18`) can attach to every network whose project declares that version, each time under the canonical alias (`postgres`). `project:down` removes only the calling project's network — main and sibling worktrees are unaffected.

**Why:** this lets a worktree run a different version of a system service (e.g. upgrading from Postgres 16 on main to Postgres 18 on a branch) without the canonical alias colliding. Service containers themselves remain shared: one container per `(service, version)` pair, reused across every network that needs it.

## Setup

### 1. Create worktrees

```bash
cd ~/projects/my-app
git worktree add ~/projects/my-app-feature-x feature/feature-x
git worktree add ~/projects/my-app-PROJ-123 feature/PROJ-123
```

### 2. Start each worktree

Each worktree needs its own `dde project:up`:

```bash
# Main worktree
cd ~/projects/my-app
dde project:up

# Feature worktree
cd ~/projects/my-app-feature-x
dde project:up
```

### 3. Access in browser

- Main: `https://my-app.test`
- Feature: `https://my-app-feature-x.test`
- Ticket: `https://my-app-proj-123.test`

Each hostname gets its own trusted TLS certificate (generated by mkcert under the hood).

## TLS Certificates

When a worktree is detected, `MkcertManager` generates a certificate that includes the worktree hostname. The wildcard DNS resolution via dnsmasq (`.test` domain) ensures all worktree hostnames resolve correctly.

## Traefik Labels

The docker-compose override generated by `DockerComposeManager::generateOverride()` emits fresh Traefik labels whose router names are derived from the worktree hostname (not the main project hostname). The `!override` YAML tag replaces the base file's labels rather than merging with them, so each container advertises exactly one router per role. This is what lets main and worktree containers coexist without Traefik logging "Router defined multiple times with different configurations".

## Viewing Worktree Information

- `dde project:describe` shows worktree details for the current checkout, including the detected hostname and the main-worktree path.
- `dde project:open` opens the current checkout's URL. Inside a worktree it always opens the worktree hostname, even when the base `docker-compose.yml` still declares the main hostname in Traefik labels.

## Limitations

- Each worktree runs its own application containers and owns its per-project Docker network. System-service containers (Traefik, dnsmasq, MariaDB, Postgres, …) live once per `(service, version)` pair and are attached to every project network that requests them.
- `dde` does **not** create the worktree's database automatically. Seed it yourself, either from the main project's snapshot or by running your framework's migration command inside the worktree container.
- The `.dde/` directory lives in the repository and is shared across worktrees. Worktree-specific hooks or plugins are not supported.

## Related

- [Configuration](../getting-started/configuration.md)
- [Project Commands](../getting-started/commands.md)
- [project:init env migration](./migration-from-v1.md#env-file-migration)
