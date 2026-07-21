---
title: "Advanced Topics"
---


## Networking

Each project gets its own Docker network — `dde-services-<project>` for the main checkout, `dde-services-<project>-<suffix>` for a worktree. Project containers join only that network; the shared `dde` network is reserved for system service containers (Traefik, dnsmasq, Mailpit, the versioned MariaDB/Postgres containers). Traefik and the configured DB/mail services attach to the per-project network on `project:up` so cross-container DNS still works — a web container reaches MariaDB at `mariadb:3306`, Valkey at `valkey:6379`, and Mailpit SMTP at `mail:1025`.

The per-project network is created unconditionally, even for projects that declare no `services:` in `.dde/config.yml` — that keeps parallel checkouts (main + worktree) isolated and avoids alias collisions on the shared `dde` network.

DNS resolution for `*.test` domains is handled by a dnsmasq container that resolves everything to `127.0.0.1`. On macOS this uses `/etc/resolver/test`, on Linux it integrates with systemd-resolved or NetworkManager. All configured automatically by `dde system:install`.

## TLS Certificates

dde uses [mkcert](https://github.com/FiloSottile/mkcert) for locally-trusted HTTPS. `system:install` creates a root CA trusted by your OS and browsers. Certificates are generated per-project during `project:up` — no manual setup needed.

### Container Trust

Project containers automatically trust the mkcert root CA. During `project:up`, dde bind-mounts the CA certificate into each container and the `ca-trust` adapter installs it into the container's certificate store. This enables HTTPS calls between local `.test` services from within containers (e.g. a PHP application calling `https://api.test`). Supported base images: Debian/Ubuntu, Alpine, RHEL/Fedora/CentOS, and openSUSE. If you generated the mkcert CA after containers were already running, recreate them with `dde project:down && dde project:up` to pick up the certificate.

## Multiple Projects

Multiple projects run simultaneously, each with a unique hostname (`project-a.test`, `project-b.test`). Traefik routes requests by hostname, so there are no port conflicts.

Database services are shared — a single MariaDB instance serves all projects, each using its own database (named after the project). If two projects need different versions, dde creates separate containers (`dde-mariadb-10`, `dde-mariadb-11.8`).

Stopping one project does not affect others. Use `dde system:down` to stop all services.

## Running dde from Outside the Project Root

dde normally detects the project by walking up from the current working directory until it finds `.dde/config.yml`. The global `--project-dir` / `-C` option overrides this — dde behaves as if it had been started in the given directory, mirroring `git -C`:

```bash
dde -C /path/to/project project:up
dde --project-dir=/path/to/project project:exec composer install
```

## JSON Output

All commands support `--output=json` for scripting:

```bash
dde project:describe --output=json | jq -r '.data.hostname'
```

Response format:

```json
{
    "status": "ok",
    "message": "",
    "data": { ... },
    "errors": []
}
```

Interactive commands (`project:shell`, `project:logs --follow`) do not support JSON output.

## Local Compose Tweaks via `docker-compose.override.yml`

Drop a `docker-compose.override.yml` (or `compose.override.yml`) next to the base compose file for developer-local tweaks — host port bindings, an extra `DISPLAY` env var for a Playwright container, an optional debug service. dde picks it up on `project:up` and slots it between the base file and its own runtime overlay, so the dde overlay still has the final word on the network and worktree hostname, while everything else (environment, volumes, extra services) behaves exactly like a normal Compose override.

The override is paired by base filename: a project on `docker-compose.yml` looks for `docker-compose.override.yml` (or `.yaml`); a project on `compose.yml` looks for `compose.override.yml`. Mixing the two stems is intentionally not supported — keep the names consistent.
