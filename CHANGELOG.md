# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-03-20

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
