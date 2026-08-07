---
title: "Migration from v1 to v2"
---


dde v2 is a complete rewrite. v1 and v2 cannot run side by side — you must fully remove v1 before installing v2.

## Overview of changes

| Component        | v1                | v2                                            |
| ---------------- | ----------------- | --------------------------------------------- |
| Language         | Bash              | PHP 8.5 + static-php-cli                      |
| Reverse proxy    | nginx-proxy       | Traefik v3                                    |
| TLS certificates | Custom openssl CA | mkcert (OS-trusted root CA)                   |
| Mail catcher     | MailCrab          | Mailpit                                       |
| Configuration    | Shell variables   | YAML (`~/.dde/config.yml`, `.dde/config.yml`) |
| Worktree support | None              | Automatic hostname per worktree               |
| Plugin system    | None              | `.dde/plugins/` directory                     |
| Global config    | None              | `~/.dde/config.yml`                           |

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

Remove the dde v1 aliases and autocomplete from your `~/.zshrc` or `~/.bashrc` and restart your shell.
If the shell was not restarted, `dde` still resolves to the old v1 commands, even after you have replaced the binary. Running `exec $SHELL -l` will do the trick as well.

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

`project:init` creates the `.dde/` directory, detects your `docker-compose.yml`, and adds Traefik labels. It also strips v1 boilerplate (the explicit `dde` external network, the SSH-Agent volume mount and `SSH_AUTH_SOCK`, legacy `DDE_UID`/`DDE_GID` build args, fixed `container_name`) — in v2, networks and the SSH-Agent socket are injected by the runtime overlay, so the committed compose file stays clean.

Open `https://my-app.test` in your browser to verify. Firefox users may need to restart the browser to trust the certificate.

#### SSH agent

v1 always ran its own SSH-Agent container and loaded your private-key files into it. v2 instead forwards your existing host SSH agent (`SSH_AUTH_SOCK`) into project containers by default, so no keys are copied anywhere. To keep the v1-style behaviour, set `ssh.agent.mode: managed` in `~/.dde/config.yml` — see the [SSH agent guide](./ssh-agent.md).

#### .env file migration

While adapting the project, `project:init` also inspects the `.env`/`.env.local`/`.env.dev` files and migrates a small, well-known set of variables.

**Why this split?** v2 draws a clear line between the two config layers:

- **`.env` stays a neutral template.** It keeps values that are safe to commit and safe to use outside of dde (e.g. running tests on CI, opening the project on a plain host). `app:changeme` on `127.0.0.1`, `null://null` as mailer — the app boots without touching the developer's machine.
- **`docker-compose.yml` holds the dde-specific runtime.** The container-side credentials, service hostnames (`mariadb`, `mailpit`), ports and server-version hints live here. This is the layer that `dde project:up` actually renders into the container.

Because dde-specific values live only in `docker-compose.yml`, the [worktree override](./worktrees.md#environment-overrides) can cleanly rewrite them per worktree (hostnames, `DATABASE_URL` path segment) without ever touching the committed `.env`. Main and worktree both share the same `.env`, but see different values inside their containers.

With that split in mind, `project:init` applies these two rules:

| Variable       | Where                           | Behaviour                                                                                                                                                                                                                                                                                                    |
| -------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `MAILER_DSN`   | compose `environment:` + `.env` | Only when `mailpit` is a configured dde service. compose gets `smtp://mail:1025`; `.env` is rewritten to `null://null` (so the app is safe to run outside dde too).                                                                                                                                       |
| `DATABASE_URL` | compose `environment:` + `.env` | User-prompted. If the `.env` value matches a configured dde DB service, you are asked whether to migrate. Accepting rewrites `.env` to `<scheme>://app:changeme@127.0.0.1:<port>/<db>?<query>` and adds a compose entry `<scheme>://<root>:<root>@<service>/<sanitized-db>?serverVersion=<version>&<query>`. |

In non-interactive mode (piped stdout, `--no-interaction`) the `DATABASE_URL` prompt is silently rejected — run `project:init` in a real terminal if you want to apply it.

#### Remove dde user from existing Dockerfiles

If the existing `dev` stage of a Dockerfile references the `dde` user (e.g. in `chown` instructions), those references must be removed. v2 no longer has a `dde` user available during the docker bild.

Any customisations that previously relied on the `dde` user can be implemented using **hooks** or **service adapters** instead:

- [**Hooks**](../extending/hooks.md) (`.dde/hooks/`): shell scripts executed at defined lifecycle points (e.g. `post-up`).
- [**Service adapters**](../extending/service-adapters.md) (`.dde/adapters/`): allow project-specific configuration of services such as nginx or php-fpm.

## Breaking changes

| v1 command/feature            | v2 equivalent                   | Notes                                                       |
| ----------------------------- | ------------------------------- | ----------------------------------------------------------- |
| `dde project fix-permissions` | Removed                         | No longer needed (automatic UID/GID mapping)                |
| `dde project exec-root`       | `dde project:exec --root`       | Now a flag on `project:exec`                                |
| `VIRTUAL_HOST` env var        | Traefik labels (auto-generated) | Set automatically by `project:init`                         |
| Custom openssl CA             | mkcert root CA                  | Installed via `system:install`                              |
| MailCrab                      | Mailpit                         | Different web UI, same SMTP interface                       |
| nginx-proxy                   | Traefik v3                      | Routing via labels instead of env vars                      |
| `DDE_BROWSER` env var         | `default_browser` config option | Browser for `project:open` now lives in `~/.dde/config.yml` |

## v2: restart commands removed

`project:restart` and `system:restart` were removed in v2. The lifecycle
commands are now explicitly symmetric:

### Replacement for `project:restart`

- Restart the existing containers (fast, container state is preserved):
  ```bash
  dde project:stop && dde project:up
  ```
- Rebuild with fresh images:
  ```bash
  dde project:update
  ```

### Replacement for `system:restart`

- Restart the global services only (containers are preserved):
  ```bash
  dde system:stop && dde system:up
  ```
- Rebuild the global service images plus skill/completion refresh:
  ```bash
  dde system:update
  ```

### New commands

- `dde project:stop` / `dde system:stop`: halt containers without removing
  them. Resume quickly via `project:up` / `system:up`.
- `dde system:update`: removes all dde containers, rebuilds the global
  service images with `docker build --pull`, and refreshes the integrations
  that are bound to the dde binary (shell completion, Claude skill).
  Package managers run this automatically after an `apt` / `dnf` / `pacman` /
  `apk` upgrade; Homebrew surfaces it in its caveats.

## DNS resolver leftovers

The `/etc/resolver/test` file (macOS) written by dde v1 is recognised as already
configured by v2 — including v1's missing trailing newline — so `dde
system:install` after an upgrade needs no root and no manual cleanup. Never run
`dde` with `sudo`; it escalates internally where required (see
[Privilege handling](system-lifecycle.md#privilege-handling)).
