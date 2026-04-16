---
title: "Valkey"
---


Valkey is a Redis-compatible in-memory data store. It runs without authentication by default.

## Connection

From inside a project container (using the network alias):

```bash
redis-cli -h valkey
```

From the host machine (via the exposed port):

```bash
redis-cli -h 127.0.0.1 -p 6379
```

> **Note:** Valkey is fully Redis-compatible. Use the standard `redis-cli` client to connect.

## Data Persistence

Data survives container restarts and dde updates. Each version has its own isolated data directory.

## Configuration

Declare in `.dde/config.yml`:

```yaml
services:
  - name: valkey
```

Or with an explicit version:

```yaml
services:
  - name: valkey
    version: "8"
```

See [Custom Versions](custom-versions.md) for details on running non-default versions.
