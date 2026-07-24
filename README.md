# dde -- Docker Development Environment

[![CI](https://github.com/whatwedo/dde/actions/workflows/ci.yml/badge.svg?branch=v2)](https://github.com/whatwedo/dde/actions/workflows/ci.yml)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.html.en)
[![GitHub release](https://img.shields.io/github/v/release/whatwedo/dde)](https://github.com/whatwedo/dde/releases)

A CLI tool that manages local Docker development environments with automatic HTTPS, DNS, database services, and per-project isolation.

- **Automatic HTTPS** -- Traefik v3 reverse proxy with mkcert-generated certificates trusted by your OS
- **Local DNS** -- dnsmasq resolves `*.test` domains to localhost, no `/etc/hosts` editing
- **Per-project services** -- MariaDB, PostgreSQL, Valkey, and Mailpit with version pinning
- **Git worktree support** -- Each worktree gets its own hostname and TLS certificate
- **SSH agent forwarding** -- Shared SSH agent container for all projects
- **Shell completion** -- Bash and Zsh completions installed automatically
- **Hook system** -- Run custom scripts on `project:up` and `project:down` lifecycle events
- **Plugin system** -- Extend dde with project-local or global plugins

## Quick Start

**Prerequisites:** [Docker](https://docs.docker.com/get-started/get-docker/) and [mkcert](https://github.com/FiloSottile/mkcert) must be installed.

```bash
# macOS
brew tap whatwedo/dde && brew install dde

# Debian/Ubuntu
curl -fsSL https://packages.dde.sh/apt/key.gpg | sudo gpg --dearmor -o /usr/share/keyrings/dde.gpg
echo "deb [signed-by=/usr/share/keyrings/dde.gpg] https://packages.dde.sh/apt stable main" | sudo tee /etc/apt/sources.list.d/dde.list
sudo apt update && sudo apt install dde

# Alpine
curl -fsSL https://packages.dde.sh/alpine/key.rsa.pub -o /etc/apk/keys/dde.rsa.pub
echo "https://packages.dde.sh/alpine" >> /etc/apk/repositories
apk add dde

# Arch Linux
echo -e '\n[dde]\nServer = https://packages.dde.sh/arch/$arch\nSigLevel = Required DatabaseOptional' | sudo tee -a /etc/pacman.conf
curl -fsSL https://packages.dde.sh/arch/key.gpg | sudo pacman-key --add -
sudo pacman-key --lsign-key "$(curl -fsSL https://packages.dde.sh/arch/key.gpg | gpg --with-colons --import-options show-only --import 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')"
sudo pacman -Sy dde
```

Then set up the system and start your first project:

```bash
dde system:install
cd ~/projects/my-app
dde project:init
dde project:up
```

Your application is now available at `https://my-app.test` with a trusted TLS certificate.

## Why dde instead of DDEV?

[DDEV](https://docs.ddev.com/en/stable/) is the most mature tool in this space — well-documented, actively maintained, and great for standard setups. However, DDEV wraps every web image in its own Dockerfile layers (user setup, PHP-FPM config, Xdebug, Mailpit). This means you can't run your production images directly — DDEV requires you to adopt its image ecosystem.

dde takes a different approach: **your existing Docker images work as-is.** dde adds a thin runtime layer that remaps the container user's UID/GID to match the host, and uses service adapters (nginx, php-fpm, apache) to reconfigure processes at startup. There is no custom Dockerfile layer — the same image you deploy to production runs locally.

|                    | dde                                                              | DDEV                           |
| ------------------ | ---------------------------------------------------------------- | ------------------------------ |
| Custom prod images | Works as-is                                                      | Requires DDEV image layers     |
| Image management   | Your Dockerfiles, unchanged                                      | DDEV-managed Dockerfiles       |
| Runtime overhead   | Thin entrypoint (UID remap)                                      | Full image rebuild per project |
| Language           | PHP (single binary via [static-php-cli](https://static-php.dev)) | Go                             |
| License            | AGPL-3.0                                                         | Apache-2.0                     |

## Supported Platforms

| OS    | Architecture  |
| ----- | ------------- |
| macOS | x86_64, arm64 |
| Linux | x86_64, arm64 |

## Documentation

Full documentation is available in the [docs/](docs/) directory:

**Getting Started**
- [Installation](docs/getting-started/installation.md)
- [Core Concepts](docs/getting-started/concepts.md)
- [Configuration](docs/getting-started/configuration.md)
- [Commands Reference](docs/getting-started/commands.md)

**Guides**
- [Multi-Service Projects](docs/guides/multi-service-project.md)
- [Custom Images](docs/guides/custom-images.md)
- [Git Worktrees](docs/guides/worktrees.md)
- [Advanced Topics](docs/guides/advanced-topics.md)
- [Migration from v1](docs/guides/migration-from-v1.md)

**Services**
- [Overview](docs/services/overview.md) -- [MariaDB](docs/services/mariadb.md) -- [PostgreSQL](docs/services/postgresql.md) -- [Valkey](docs/services/valkey.md) -- [Mailpit](docs/services/mailpit.md) -- [Traefik](docs/services/traefik.md) -- [SSH-Agent](docs/services/ssh-agent.md) -- [Custom Versions](docs/services/custom-versions.md)

**Extending**
- [Hooks](docs/extending/hooks.md) -- [Plugins](docs/extending/plugins.md) -- [Service Adapters](docs/extending/service-adapters.md)

**Internals**
- [Auto Layer](docs/internals/auto-layer.md) -- [Dev Layer Builder](docs/internals/dev-layer-builder.md) -- [Docker Compose Override](docs/internals/docker-compose-override.md) -- [Entrypoint](docs/internals/entrypoint.md) -- [Config Loader](docs/internals/config-loader.md) -- [Plugin Loader](docs/internals/plugin-loader.md)

**Contributing**
- [Development Setup](docs/contributing/development-setup.md) -- [Architecture](docs/contributing/architecture.md) -- [Testing](docs/contributing/testing.md) -- [Release Process](docs/contributing/release-process.md) -- [Adding a Command](docs/contributing/adding-a-command.md) -- [Adding a Service](docs/contributing/adding-a-service.md) -- [Adding an Adapter](docs/contributing/adding-an-adapter.md)

## License

This project is licensed under the [AGPL-3.0-or-later](LICENSE) license.
