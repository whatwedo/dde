---
title: "Traefik"
---


Traefik v3 serves as the reverse proxy for all dde projects, routing HTTP and HTTPS traffic based on hostname.

## What It Does

- Routes traffic to project containers based on their configured hostname (e.g. `myapp.test`)
- Provides both HTTP (port 80) and HTTPS (port 443) access
- Automatically picks up new projects and TLS certificates without restart
- Traefik labels are set automatically by `project:init` and `project:up`

## Network

Traefik lives on the shared `dde` network. Every project container is also attached to this network by the compose overlay that `project:up` generates, so Traefik can reach each container directly — no per-project routing configuration is required.

In parallel, each project also gets its own `dde-services-<project>` network used only to connect the container to its versioned services (`mariadb`, `postgres`, …). Traefik is not attached to it. See [Core Concepts → Networking](../getting-started/concepts.md#networking) for the full picture.

## TLS

TLS certificates are generated automatically by mkcert when you run `project:up`. HTTPS works out of the box for all `.test` domains.

## Routing Configuration

In v2, Traefik routing is configured via Docker labels, which are generated automatically by `project:init` based on your project's hostname configuration in `.dde/config.yml`. You do not need to set labels manually.

The generated labels follow the Traefik v3 format, e.g.:

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.http.routers.myapp.rule=Host(`myapp.test`)"
  - "traefik.http.routers.myapp.tls=true"
```

## Migration from v1

In v1, routing was configured via `VIRTUAL_HOST` and `VIRTUAL_PORT` environment variables on containers. These are no longer used in v2. Run `dde project:init` to regenerate the correct Traefik label configuration for your project.
