---
title: "PostgreSQL"
---


## Default Credentials

| Variable | Value |
|---|---|
| `POSTGRES_USER` | `postgres` |
| `POSTGRES_PASSWORD` | `postgres` |

## Connection

From inside a project container (using the network alias):

```bash
psql -h postgres -U postgres
```

From the host machine (via the exposed port):

```bash
psql -h 127.0.0.1 -p 5432 -U postgres
```

## Automatic DATABASE_URL

When a project's `docker-compose.yml` contains a `postgres` service, dde automatically injects a `DATABASE_URL` environment variable into the primary service. The database name is derived from the project name (hyphens replaced with underscores).

The variable is only added if:
- It is not already defined in the service's environment
- It is not defined in the project's `.env` or `.env.dev` files

## Data Persistence

Data survives container restarts and dde updates. Each version has its own isolated data directory.

## Configuration

Declare in `.dde/config.yml`:

```yaml
services:
  - name: postgres
```

Or with an explicit version:

```yaml
services:
  - name: postgres
    version: "15"
```

See [Custom Versions](custom-versions.md) for details on running non-default versions.
