---
title: "Advanced Topics"
---


## Networking

All containers share a single Docker network called `dde`. Containers communicate via service names — a web container reaches MariaDB at `mariadb:3306`, Valkey at `valkey:6379`, and Mailpit SMTP at `mail:1025`.

DNS resolution for `*.test` domains is handled by a dnsmasq container that resolves everything to `127.0.0.1`. On macOS this uses `/etc/resolver/test`, on Linux it integrates with systemd-resolved or NetworkManager. All configured automatically by `dde system:install`.

## TLS Certificates

dde uses [mkcert](https://github.com/FiloSottile/mkcert) for locally-trusted HTTPS. `system:install` creates a root CA trusted by your OS and browsers. Certificates are generated per-project during `project:up` — no manual setup needed.

## Multiple Projects

Multiple projects run simultaneously, each with a unique hostname (`project-a.test`, `project-b.test`). Traefik routes requests by hostname, so there are no port conflicts.

Database services are shared — a single MariaDB instance serves all projects, each using its own database (named after the project). If two projects need different versions, dde creates separate containers (`dde-mariadb-10`, `dde-mariadb-11.8`).

Stopping one project does not affect others. Use `dde system:down` to stop all services.

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
