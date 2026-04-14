---
title: "Automatic dde Layer"
---


The automatic dde layer is a Docker image built on top of the project's base image. It adds the `dde` user and privilege escalation tools required for UID/GID remapping at container start.

## Image Naming

The generated image is tagged as:

```
dde-{projectName}:dev
```

For example, a project named `myapp` produces the image `dde-myapp:dev`.

## When the Layer Builds

The layer is built during `project:up`. The build is triggered only when the cached image does not exist in the local Docker image store. If the image already exists, the build is skipped entirely.

The layer applies to the first service in the compose file that has an `image` directive.

## What the Layer Contains

The layer depends on the detected distribution:

**Alpine:**
- Installs `su-exec` and `shadow`
- Creates `dde` group with host GID
- Creates `dde` user with host UID, home directory `/home/dde`

**Debian/Ubuntu (and all other distributions):**
- Installs `gosu`
- Creates `dde` group with host GID
- Creates `dde` user with host UID, home directory `/home/dde`

Distribution detection is automatic -- dde reads `/etc/os-release` from the base image to determine Alpine vs Debian.

## Cache Invalidation

The layer is cached indefinitely once built. It is invalidated in the following situations:

- **Docker image prune** -- if you run `docker image prune` or `docker system prune`, the dde layer image may be removed.
- **UID/GID change** -- if the host user's UID or GID changes (e.g., after a system migration), the existing layer will have the wrong user IDs baked in.

To force a rebuild, remove the cached image:

```bash
docker rmi dde-myapp:dev
```

The next `project:up` will automatically rebuild the layer.

## Relationship to the Runtime Entrypoint

The automatic layer and the runtime entrypoint serve complementary roles:

| Concern | Auto Layer (build time) | Entrypoint (run time) |
|---|---|---|
| Install gosu/su-exec | Yes | No |
| Create dde user | Yes (with build-time UID/GID) | Yes (with runtime UID/GID) |
| UID/GID remapping | No | Yes |
| Service adapters | No | Yes |
| Shell detection | No | Yes |

The layer pre-installs the user and tools so that the entrypoint can run quickly without needing network access or package installation at every container start. The entrypoint handles runtime remapping in case the UID/GID has changed since the layer was built.
