---
title: dde — Docker Development Environment
description: A CLI tool that manages local Docker development environments with automatic HTTPS, DNS, database services, and per-project isolation.
template: splash
hero:
  tagline: Your existing Docker images work as-is. No custom Dockerfile layers needed.
  actions:
    - text: Get Started
      link: /getting-started/installation/
      icon: right-arrow
    - text: View on GitHub
      link: https://github.com/whatwedo/dde
      icon: external
      variant: minimal
---

![Architecture](./_img/architecture.svg)

## Features

- **Automatic HTTPS** — Traefik v3 reverse proxy with mkcert-generated certificates trusted by your OS. No browser warnings.
- **Local DNS** — dnsmasq resolves `*.test` domains to localhost. No `/etc/hosts` editing required.
- **Per-project services** — MariaDB, PostgreSQL, Valkey, and Mailpit with version pinning — isolated per project.
- **Git worktree support** — Each worktree gets its own hostname and TLS certificate for parallel feature development.
- **SSH agent forwarding** — A shared SSH agent container makes your host keys available inside all containers.
- **Hook system** — Run custom scripts on `project:up` and `project:down` lifecycle events.
- **Plugin system** — Extend dde with project-local or global plugins to add custom commands.
- **Shell completion** — Bash and Zsh completions installed automatically via `dde system:install`.

## Quick Start

**Prerequisites:** [Docker](https://docs.docker.com/get-docker/) and [mkcert](https://github.com/FiloSottile/mkcert) must be installed.

**macOS**
```bash
brew tap whatwedo/dde && brew install dde
```

**Debian/Ubuntu**
```bash
curl -fsSL https://packages.dde.sh/apt/key.gpg | sudo gpg --dearmor -o /usr/share/keyrings/dde.gpg
echo "deb [signed-by=/usr/share/keyrings/dde.gpg] https://packages.dde.sh/apt stable main" | sudo tee /etc/apt/sources.list.d/dde.list
sudo apt update && sudo apt install dde
```

**Alpine**
```bash
curl -fsSL https://packages.dde.sh/alpine/key.rsa.pub -o /etc/apk/keys/dde.rsa.pub
echo "https://packages.dde.sh/alpine/main" >> /etc/apk/repositories
apk add dde
```

**Arch Linux**
```bash
echo -e '\n[dde]\nServer = https://packages.dde.sh/arch/$arch\nSigLevel = Required DatabaseOptional' | sudo tee -a /etc/pacman.conf
curl -fsSL https://packages.dde.sh/arch/key.gpg | sudo pacman-key --add -
sudo pacman-key --lsign-key "$(curl -fsSL https://packages.dde.sh/arch/key.gpg | gpg --with-colons --import-options show-only --import 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')"
sudo pacman -Sy dde
```

Then set up the system and initialize your first project:

```bash
dde system:install      # configure Traefik, dnsmasq, mkcert
cd ~/projects/my-app
dde project:init        # create .dde/config.yml
dde project:up          # start containers
```

Your application is now available at `https://my-app.test` with a trusted TLS certificate.

## Why dde instead of DDEV?

[DDEV](https://ddev.readthedocs.io/) is the most mature tool in this space — well-documented, actively maintained, and great for standard setups. However, DDEV wraps every web image in its own Dockerfile layers (user setup, PHP-FPM config, Xdebug, Mailpit). This means you can't run your production images directly.

**dde takes a different approach: your existing Docker images work as-is.** dde adds a thin runtime layer that remaps the container user's UID/GID to match the host, and uses service adapters (nginx, php-fpm, apache) to reconfigure processes at startup. The same image you deploy to production runs locally.

|  | dde | DDEV |
|---|---|---|
| Custom prod images | Works as-is | Requires DDEV image layers |
| Image management | Your Dockerfiles, unchanged | DDEV-managed Dockerfiles |
| Runtime overhead | Thin entrypoint (UID remap) | Full image rebuild per project |
| Language | PHP (single binary via [static-php-cli](https://static-php.dev)) | Go |
| License | AGPL-3.0 | Apache-2.0 |

## Supported Platforms

| OS | Architecture |
|---|---|
| macOS | x86_64, arm64 |
| Linux | x86_64, arm64 |
