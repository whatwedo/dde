# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0-alpha.4] - 2026-04-19

### Added
- `project:up` and `project:update` now print the project's reachable domains after a successful run (worktree-aware)
- Auto-generated container hostnames (`<project>-<service>`) so the shell prompt inside a container shows `meseto-web` instead of the random container ID; explicit `hostname:` in the user's `docker-compose.yml` is respected
- `project:init` now proposes `.env` migrations for `APP_ENV`, `MAILER_DSN`, and `DATABASE_URL`, presenting each rewrite before it is written
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
