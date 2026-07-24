---
title: "System Services Overview"
---


dde manages two categories of services: global services that provide shared infrastructure, and project services that provide per-project databases and tools.

## Global Services

Global services are started by `system:up` and shared across all projects.

| Service | Purpose |
|---|---|
| Traefik | Reverse proxy with TLS (ports 80, 443) |
| dnsmasq | DNS for `.test` domain (port 53) |
| SSH-Agent | SSH key sharing (only in `managed` agent mode; by default the host agent is forwarded instead) |
| Mailpit | Email testing (port 8025) |

Global services are automatically started when running `project:up` if they are not already running.

## Project Services

Project services are declared in the project's `.dde/config.yml`. They are versionable: each project can request a specific version of a service.

| Service | Default Version | Default Port |
|---|---|---|
| MariaDB | 11.8 | 3306 |
| PostgreSQL | 18.3 | 5432 |
| Valkey | 9 | 6379 |
| Mailpit | latest | 8025 |

### Container Naming

Project service containers follow the naming pattern `dde-{service}-{version}`. Examples: `dde-mariadb-11.8`, `dde-postgres-18.3`, `dde-valkey-9`.

### Network Aliases

When a service runs at the default version, it receives a network alias matching the service name. This allows projects to connect using a simple hostname (e.g. `mariadb`, `valkey`). Non-default versions do not get an alias.

### Port Allocation

Default-version services bind to the standard port on localhost (e.g. `3306` for MariaDB). Non-default versions receive a dynamically allocated port starting at 10000.

### Conflict Prevention

Only one version of a service can run at a time. If another version of the same service is already running, dde will report an error to prevent port conflicts.

## Service Configuration in Projects

Declare services in `.dde/config.yml`:

```yaml
services:
  - name: mariadb
  - name: valkey
```

Short form (defaults to the global default version):

```yaml
services:
  - mariadb
  - valkey
```

With explicit version override:

```yaml
services:
  - name: mariadb
    version: "10.6"
```

Supported service names: `mariadb`, `postgres`, `valkey`, `mailpit`.
