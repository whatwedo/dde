# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed

- Plain HTTP requests are now redirected to HTTPS by default (Traefik's `web` entry point forwards to `websecure`; recreate the container via `dde system:down && dde system:up` to pick it up).

## [2.0.0-rc.1] - 2026-07-18

### Added

- Global `--project-dir` / `-C` option to run any command from outside the project root. (#214)
- Traefik dashboard at `https://traefik.test` (recreate the container via `dde system:down && dde system:up` to pick it up).
- `project:up` opens the project URL in the browser set via the new `default_browser` option. (#125)
- `git` now works inside worktree containers.

### Changed

- Plugins no longer time out after 300 seconds.

### Fixed

- `mail.test` / `traefik.test` now use a trusted certificate — no more browser warning. (#234)
- `sudo dde …` is rejected up-front to keep `~/.dde` free of root-owned files. (#142)
- `dde system:install` no longer needs a `sudo` prefix; the DNS writes escalate internally. (#142, #205)
- A `/etc/resolver/test` left behind by dde v1 is recognised as already configured. (#205)
- Project containers and their `nginx`/`php-fpm` reliably run as the `dde` user on every base image.
- `project:up` no longer times out when a large image is still being pulled.
- `project:db:export` / `project:db:snapshot:create` stream dumps to disk instead of buffering in memory.
- Alpine packages publish to the correct region with a valid `APKINDEX`.
- Generated `.dde/.gitignore` ignores `data/*` / `snapshots/*` so `.gitkeep` stays tracked.

## [2.0.0-beta.2] - 2026-06-01

### Added

- A user-supplied `docker-compose.override.yml` is now honoured on `project:up`. (#163)
- A `dde-nightly` package is now built on every push to `v2` (APT, Alpine, Arch, RPM, Homebrew).

### Changed

- Worktree hostnames are now a subdomain of the project host (`<suffix>.<project>.test`). Re-run `dde project:up` in existing worktrees to pick it up.
- `dde --version` now reports the git tag (stable), short SHA (nightly), or `dev` (local).

### Fixed

- Parallel checkouts (main + worktree) no longer collide on the shared `dde` network; Traefik attaches per project and reconciles on start, so `system:update` no longer leaves projects on 502/504. (#163)
- A worktree's URLs and `extra_hosts` now resolve its own hostnames, including projects with multiple database connections.
- Custom Compose tags (`!reset`, `!override`) and non-Traefik labels in a user override are now preserved instead of crashing or being dropped. (#163)
- Label-less helper containers (e.g. Playwright) no longer get auto-Traefik labels that crashed Traefik with `port is missing`.
- `project:up` restarts a stopped service container instead of failing with "container name already in use".
- `project:init` lists skipped entries first, so `Skipped .dde/ (already exists)` is no longer buried. (#127)

## [2.0.0-beta.1] - 2026-05-13

### BREAKING

- Plugins are now registered under the `project:` namespace instead of `project:exec:`. A plugin with `@command deploy` now runs as `dde project:deploy` (previously `dde project:exec:deploy`). Built-in `project:*` commands take precedence; colliding plugin names are silently shadowed.

### Added

- Each git worktree now owns its own per-project Docker network (`dde-services-<project>-<suffix>`). Main and worktree can run different versions of the same shared service (e.g. main on postgres-16, worktree on postgres-18) without DNS-alias collisions on the canonical service name. Main checkouts are unaffected.

### Fixed

- Mailpit retains all messages instead of capping at the most recent 500. The container now starts with `MP_MAX_MESSAGES=0`; users on existing installations pick this up after the next `dde system:update`. (#143)
- `project:db*` commands (`project:db`, `project:db:open`, `project:db:export`, `project:db:import`, `project:db:snapshot:create`, `project:db:snapshot:restore`) now target the worktree-suffixed database when run from inside a git worktree, matching the `DATABASE_URL` the compose override rewrites for the application container. An explicit `--database` value is still forwarded verbatim.
- `project:init` no longer injects `APP_ENV=dev` into `docker-compose.yml`. Symfony skips loading `.env`/`.env.local` whenever `APP_ENV` is already defined in the environment, which silently broke projects that rely on `.env` for application-level configuration. (#132)
- Worktrees nested inside the main checkout (e.g. `<main>/.claude/worktrees/<name>`) and worktrees whose branch has not yet committed `.dde/` are now correctly detected. Previously they launched with the main checkout's hostnames, breaking isolation.
- Traefik labels for subdomain hosts (e.g. `Host(\`preview.<project>.test\`)`) are now rewritten in the worktree compose override, preventing the "router defined multiple times" conflict between main and worktree.
- URL-bearing env vars declared via `env_file:` are now rewritten in the worktree compose override, so values like `E2E_TARGET_URL` or `MERCURE_URL` point at the worktree variant instead of leaking through to the main checkout.
- Worktree TLS certificates now cover every subdomain declared via Traefik labels, not just the bare worktree hostname. Subdomain routes inside a worktree are no longer served with an untrusted wildcard certificate.
- `project:up` and `project:update` now list every worktree subdomain URL in the "Available at:" output instead of only the bare worktree hostname.
- `project:up` "Available at:" only advertises URLs of services that are actually running — services excluded by inactive Compose profiles are no longer surfaced with broken TLS.
- `project:up` now detaches stale service containers from the per-project network when a service version changes (e.g. mariadb 10.11 → 11.8), preventing Docker's embedded DNS from round-robining between the old and new containers.
- The dev-layer build now skips shell-less images (distroless/scratch bases such as Garage) when selecting the target service, avoiding `exec: /bin/sh: no such file or directory` errors in multi-service projects.

## [2.0.0-alpha.5] - 2026-04-21

### BREAKING

- `project:restart` removed — use `project:stop && project:up` to restart with
  preserved containers, or `project:update` to rebuild with fresh images.
- `system:restart` removed — use `system:stop && system:up` to restart preserved
  containers, or `system:update` to rebuild global service images.
- APT/Arch/Alpine/RPM package upgrades now call `dde system:update` (previously
  `dde system:install`).

### Added

- `system:update` — rebuilds global service images (`docker build --pull`) and
  refreshes integrations (shell completion, Claude skill).
- `system:stop` — stops all dde containers (global + versioned) without
  removing them.

### Changed

- Homebrew formula now installs `mkcert` as a dependency.

### Fixed

- PostgreSQL services now use `/var/lib/postgresql/data` as the data directory,
  matching the upstream image default.

## [2.0.0-alpha.4] - 2026-04-19

### Added
- `project:up` and `project:update` now print the project's reachable domains after a successful run (worktree-aware)
- Auto-generated container hostnames (`<project>-<service>`) so the shell prompt inside a container shows `beispiel-web` instead of the random container ID; explicit `hostname:` in the user's `docker-compose.yml` is respected
- `project:init` now rewrites `MAILER_DSN` automatically (compose + `.env`) when `mailpit` is configured and prompts to migrate `DATABASE_URL` from `.env` into the compose service
- Per-worktree `DATABASE_URL` rewrite in the compose override so main and worktree checkouts talk to distinct databases on the shared service

### Fixed
- `project:down` on a worktree no longer strips the shared database from the per-project network while the main project is still running
- `project:down` no longer tries to remove the per-project network while other projects are still attached
- `project:open` from a worktree checkout now opens the worktree URL instead of the main project URL
- Worktrees now emit unique Traefik routers with `!override` labels, preventing the 404 fallback caused by duplicate router definitions between main and worktree
- `.env` migration during `project:init` strips and preserves surrounding quotes, so `DATABASE_URL="mysql://…"` is recognised and rewritten correctly
- `project:init` no longer errors out when the services prompt is submitted empty
- RPM distribution URL corrected

## [2.0.0-alpha.3] - 2026-04-16

### Added
- Auto-start project and system services when running a shell in a stopped project (`feat(shell)`)
- Per-project Docker network: created on `project:up`, disconnected on `project:down`; system service containers are automatically connected/disconnected
- SSH-agent injected into compose override per project, replacing hardcoded init boilerplate
- Multi-select service chooser with per-service version picker during `project:init`

### Changed
- Mailpit is now accessible exclusively via Traefik; host port forwarding removed
- `ConfigManager` split into `GlobalConfigManager` and `ProjectConfigManager` for clearer responsibility boundaries
- dde network, SSH-agent, and `OPEN_URL` boilerplate removed from init template; injected via compose override instead

### Fixed
- SSH keys are now added to the agent after `system:restart`
- SSH keys loaded from global config instead of auto-detecting all keys on the system
- Homebrew formula uses `caveats` instead of `post_install` for post-install instructions

## [2.0.0-alpha.2] - 2026-04-15

### Added
- RPM distribution for Fedora, RHEL, Rocky Linux, and AlmaLinux via DNF repository

## [2.0.0-alpha.1] - 2026-04-15

dde v2 is a complete rewrite of the Docker Development Environment. The previous
Bash-based solution has been replaced with a PHP binary built as a standalone
executable via static-php-cli, running on macOS and Linux.

### Added
- Complete CLI rewrite in PHP 8.5 with Symfony 8
- Traefik v3 as reverse proxy (replacing deprecated nginx-proxy)
- mkcert for locally-trusted TLS certificates
- Mailpit for email testing (replacing MailCrab)
- Git worktree support with automatic subdomain generation
- Plugin system for custom project commands
- Global and project-level configuration (YAML)
- Database management: shell, export, import, snapshots
- Service versioning (multiple MariaDB/PostgreSQL versions simultaneously)
- `--output=json` on all commands for scripting
- Hook system (project.up.pre/post, project.down.pre/post)
- Shell completion (bash, zsh)
- Cross-platform binary (macOS/Linux x86_64+arm64)
- `system:doctor` health checks (10 checks)
- Claude Code skills for AI-assisted development

### Changed
- CLI syntax: `dde project <cmd>` → `dde project:<cmd>`
- Configuration: `.dde.yml` → `.dde/config.yml` with new structure
- Dockerfile: 4-line boilerplate removed, automatic dev layer instead
- `dde project:exec:root` → `dde project:exec --root`

### Removed
- `dde project fix-permissions` (no longer needed, UID/GID mapping is automatic)
- Bash-based implementation
- nginx-proxy dependency
- Custom OpenSSL CA
