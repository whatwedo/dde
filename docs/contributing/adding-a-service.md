---
title: "Adding a Service"
---


This guide explains how to add a new shared service type to dde (e.g. memcached, elasticsearch, rabbitmq).

## Overview

Shared services are Docker containers managed by dde's `SystemServiceManager`. They are started automatically when a project declares them in `.dde/config.yml` and run with versioned container names (e.g. `dde-memcached-1`).

## Step 1: Add the Service Type to ServiceRegistry

Edit `src/Service/ServiceRegistry.php` and add an entry to the `SERVICE_TYPES` constant:

```php
private const array SERVICE_TYPES = [
    // ... existing services ...
    'memcached' => [
        'image' => 'memcached',
        'defaultVersion' => '1',
        'defaultPort' => 11211,
        'dataPath' => 'data',
        'dataMount' => '/data',
        'environment' => [],
    ],
];
```

Each entry requires:

| Key | Description |
|-----|-------------|
| `image` | Docker Hub image name |
| `defaultVersion` | Default version tag (used if not overridden in config) |
| `defaultPort` | Default port the service exposes |
| `dataPath` | Subdirectory name for persistent data |
| `dataMount` | Mount path inside the container for data volume |
| `environment` | Default environment variables (e.g. root passwords) |

## Step 2: Test the Service

After adding the service type, verify it works end-to-end:

1. Add the service to a project's `.dde/config.yml`:

```yaml
services:
  - name: memcached
```

2. Start the project:

```bash
dde project:up
```

3. Verify the container is running:

```bash
dde system:status
```

4. Verify the service is reachable from project containers by its name (`memcached`).

## Step 3: Database Adapter (if applicable)

If the new service is a database, you also need to:

1. Create an adapter class implementing `App\Database\DatabaseAdapterInterface` in `src/Database/`
2. Register it in `App\Database\DatabaseAdapterRegistry`

This enables `dde db`, `dde db:export`, `dde db:import`, and snapshot commands for the new database.

## Step 4: Update Documentation

Document the new service type, including:

- Default version and port
- Any required environment variables
- How projects should declare it

## Step 5: Version Override

Users can override the default version globally or per-project:

```yaml
# ~/.dde/config.yml (global)
services:
  memcached:
    version: "1.6"

# .dde/config.yml (project)
services:
  - name: memcached
    version: "1.6"
```

The override chain is: project explicit version > global services config > ServiceRegistry defaults.

## Notes

- Service containers are named `dde-{name}-{version}` (e.g. `dde-memcached-1`)
- Different versions run as separate containers with separate data volumes
- Services are shared across all projects; stopping a project does not stop shared services
- Use `dde system:down` to stop all services
