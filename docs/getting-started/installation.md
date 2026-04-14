---
title: "Installation"
---


## Prerequisites

- **Docker Desktop** (macOS) or **Docker Engine** (Linux) -- must be installed and running
- **mkcert** -- for generating locally-trusted TLS certificates (`brew install mkcert` on macOS, `apt install mkcert` on Linux)
- **macOS** (x86_64 or arm64) or **Linux** (x86_64 or arm64)

## Installation

### macOS (Homebrew)

```bash
brew tap whatwedo/dde
brew install dde
dde system:install
```

### Debian / Ubuntu (APT)

```bash
curl -fsSL https://packages.dde.sh/apt/key.gpg | sudo gpg --dearmor -o /usr/share/keyrings/dde.gpg
echo "deb [signed-by=/usr/share/keyrings/dde.gpg] https://packages.dde.sh/apt stable main" | sudo tee /etc/apt/sources.list.d/dde.list
sudo apt update
sudo apt install dde
dde system:install
```

### Alpine

```bash
curl -fsSL https://packages.dde.sh/alpine/key.rsa.pub -o /etc/apk/keys/dde.rsa.pub
echo "https://packages.dde.sh/alpine/main" >> /etc/apk/repositories
apk add dde
dde system:install
```

### Arch Linux

```bash
echo -e '\n[dde]\nServer = https://packages.dde.sh/arch/$arch\nSigLevel = Required DatabaseOptional' | sudo tee -a /etc/pacman.conf
curl -fsSL https://packages.dde.sh/arch/key.gpg | sudo pacman-key --add -
sudo pacman-key --lsign-key "$(curl -fsSL https://packages.dde.sh/arch/key.gpg | gpg --with-colons --import-options show-only --import 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')"
sudo pacman -Sy dde
dde system:install
```

## What system:install configures

The `system:install` command sets up all system-level components that dde needs:

- **mkcert** -- Installs a local root CA into your system trust store. All project certificates are signed by this CA, so browsers trust them without warnings.
- **dnsmasq** -- Configures DNS resolution for `*.test` domains. On macOS this uses `/etc/resolver/test`; on Linux it integrates with systemd-resolved or NetworkManager.
- **Traefik** -- Starts a Traefik v3 reverse proxy container on ports 80 and 443 that routes traffic to your project containers.
- **SSH agent** -- Starts a shared SSH agent container so your SSH keys are available inside project containers.
- **Shell completion** -- Installs completion scripts for your detected shell (Bash or Zsh).

DNS queries are forwarded to `9.9.9.9` and `149.112.112.112` (Quad9) by default. You can override this in the global config at `~/.dde/config.yml`.

## Verify your installation

After installation, verify that everything is working:

```bash
dde system:doctor
```

This runs 11 checks covering:

| Check             | What it verifies                                      |
|-------------------|-------------------------------------------------------|
| BinaryPath        | dde binary is in PATH and executable                  |
| DockerAvailable   | Docker daemon is reachable                            |
| DockerCompose     | `docker compose` subcommand is available              |
| Mkcert            | mkcert binary is installed                            |
| RootCaTrusted     | mkcert root CA is installed in the system trust store |
| Dnsmasq           | dnsmasq container is running                          |
| DnsResolution     | `*.test` domains resolve to 127.0.0.1                |
| Network           | The `dde` Docker network exists                       |
| Traefik           | Traefik container is running and healthy              |
| SshAgent          | SSH agent container is running                        |
| Mailpit           | Mailpit container is running                          |

All checks should pass. If any fail, re-run `dde system:install` or consult the error message for guidance.
