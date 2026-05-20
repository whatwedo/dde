---
title: "Installation"
---


## Prerequisites

- **Docker Desktop** (macOS) or **Docker Engine** (Linux) -- must be installed and running
- **mkcert** -- for generating locally-trusted TLS certificates
  - macOS: `brew install mkcert`
  - debian based linux: `apt install mkcert`
  - arch: `pacman -S mkcert`
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
echo "https://packages.dde.sh/alpine" >> /etc/apk/repositories
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

### Fedora / RHEL / Rocky Linux / AlmaLinux

```bash
sudo curl -fsSL https://packages.dde.sh/rpm/dde.repo -o /etc/yum.repos.d/dde.repo
sudo dnf install dde
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

## Nightly channel

A separate `dde-nightly` package is published from the `v2` development branch
on every push. It installs the same `dde` binary as the stable package but
carries the package name `dde-nightly` and declares a conflict with `dde`, so
you can run one channel or the other on a machine — not both.

Pick this only if you actively want to dogfood unreleased code and accept that
nightlies can regress between two pushes. To go back to stable, uninstall the
nightly package and reinstall `dde` from the stable repo.

### macOS (Homebrew)

```bash
brew tap whatwedo/dde
brew install dde-nightly
dde system:install
```

### Debian / Ubuntu (APT)

```bash
curl -fsSL https://packages.dde.sh/apt-nightly/key.gpg | sudo gpg --dearmor -o /usr/share/keyrings/dde-nightly.gpg
echo "deb [signed-by=/usr/share/keyrings/dde-nightly.gpg] https://packages.dde.sh/apt-nightly stable main" | sudo tee /etc/apt/sources.list.d/dde-nightly.list
sudo apt update
sudo apt install dde-nightly
dde system:install
```

### Alpine

```bash
curl -fsSL https://packages.dde.sh/alpine-nightly/key.rsa.pub -o /etc/apk/keys/dde-nightly.rsa.pub
echo "https://packages.dde.sh/alpine-nightly" >> /etc/apk/repositories
apk add dde-nightly
dde system:install
```

### Arch Linux

```bash
echo -e '\n[dde-nightly]\nServer = https://packages.dde.sh/arch-nightly/$arch\nSigLevel = Required DatabaseOptional' | sudo tee -a /etc/pacman.conf
curl -fsSL https://packages.dde.sh/arch-nightly/key.gpg | sudo pacman-key --add -
sudo pacman-key --lsign-key "$(curl -fsSL https://packages.dde.sh/arch-nightly/key.gpg | gpg --with-colons --import-options show-only --import 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')"
sudo pacman -Sy dde-nightly
dde system:install
```

### Fedora / RHEL / Rocky Linux / AlmaLinux

```bash
sudo curl -fsSL https://packages.dde.sh/rpm-nightly/dde-nightly.repo -o /etc/yum.repos.d/dde-nightly.repo
sudo dnf install dde-nightly
dde system:install
```

The nightly package version is a UTC timestamp `YYYYMMDD.HHMM`, so
`apt upgrade` / `apk upgrade` / `brew upgrade` always picks up the newest
build.

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

## Upgrading dde

After `brew upgrade dde` / `apt-get upgrade dde` / …, dde runs `system:update`
automatically (except on Homebrew, where it is surfaced via caveats) — see
the [System Lifecycle](../guides/system-lifecycle.md) guide.
