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

Traefik runs on the shared `dde` network. To reach project containers, `project:up` also attaches Traefik to each project's per-project network (`dde-services-<project>` for the main checkout, `dde-services-<project>-<suffix>` for a worktree) and `project:down` detaches it again. Project containers themselves live only on the per-project network — joining `dde` as well would let parallel checkouts (main + worktree) shadow each other's service-name DNS aliases.

Docker does not preserve runtime `network connect` attachments across a container re-create, so `system:update` and `system:down` + `system:up` would otherwise leave previously running projects unreachable. Traefik reconciles its attachments on every start: it discovers every existing `dde-services-*` network that still has project containers and re-attaches itself, restarting once so its docker provider picks up the new networks.

See [Core Concepts → Networking](../getting-started/concepts.md#networking) for the full picture.

## TLS

TLS certificates are generated automatically by mkcert when you run `project:up`. HTTPS works out of the box for all `.test` domains.

## Dashboard

Traefik's built-in dashboard is exposed at [https://traefik.test](https://traefik.test). It lists every active router, service and middleware and flags misconfigured entries (for example a project whose Traefik labels do not parse), so configuration errors become visible at a glance instead of only surfacing in the container logs.

The dashboard is served by Traefik's internal `api@internal` service over the `websecure` entrypoint and reuses the wildcard `*.test` certificate, so no dedicated certificate is required. Like every dde service it binds to `127.0.0.1` only and is not reachable from outside the host.

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
