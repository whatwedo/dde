---
title: "Custom Service Versions"
---


Projects can request a specific version of a service that differs from the global default. This is useful when a project requires an older database version or a specific feature set.

## Declaring a Custom Version

In `.dde/config.yml`:

```yaml
services:
  - name: mariadb
    version: "10.6"
```

The version string is used directly as the Docker image tag: `mariadb:10.6`.

## What Changes with Non-Default Versions

### Container Name

The container name includes the version:

| Version          | Container Name     |
| ---------------- | ------------------ |
| `11.8` (default) | `dde-mariadb-11.8` |
| `10.6`           | `dde-mariadb-10.6` |

### Network Alias

Only the default version receives a network alias. Non-default versions do not get one:

| Version          | Network Alias |
| ---------------- | ------------- |
| `11.8` (default) | `mariadb`     |
| `10.6`           | (none)        |

This means projects using a non-default version must connect using the container name or configure the connection manually.

### Port Allocation

Default versions bind to the standard port on localhost:

```
127.0.0.1:3306  (mariadb 11.8, default)
```

Non-default versions receive a dynamically allocated port starting at 10000:

```
127.0.0.1:10000  (mariadb 10.6)
```

Once allocated, the port remains stable across restarts.

## Version Resolution Order

When `project:up` resolves the version for a service, it follows this chain:

1. **Project explicit version** -- if `.dde/config.yml` specifies a `version` other than `latest`, that version is used.
2. **Global default version** -- from `~/.dde/config.yml` `services` section.
3. **Built-in defaults** -- mariadb=11.8, postgres=18.3, valkey=9.

The `latest` value in a project config means "use the global default", not Docker's `latest` tag.

## Conflict Prevention

Only one version of a service can run at a time. If another version is already running, dde reports an error:

```
Cannot start dde-mariadb-10.6: service "mariadb" is already running as dde-mariadb-11.8.
Stop it first or use the same version.
```

To switch versions, stop the running service first (via `system:down` or stopping the project that uses it).

## Data Isolation

Each version has its own data directory. Data is not shared between versions. This means you can switch between versions without data corruption, but you need to migrate or re-import data when changing versions.

## Changing the Global Default

To change the default version for all projects, set it in `~/.dde/config.yml`:

```yaml
services:
  mariadb:
    version: "10.11"
  postgres:
    version: "15"
```

This affects which version gets the standard port and network alias. Existing containers for the old default version are not automatically stopped or migrated.
