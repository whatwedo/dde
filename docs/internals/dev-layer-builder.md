---
title: "Dev Layer Builder"
---


The `ImageManager` builds a development layer on top of project Docker images. This layer adds the `dde` user with the correct UID/GID, enabling file permission compatibility between the host and container.

## Why a Dev Layer?

Docker images typically run as root or a predefined user. For development, files created inside the container need to be owned by your host user. The dev layer solves this by:

1. Switching to `USER root` so the user can be created and the container starts as root at runtime (see [Non-root base images](#non-root-base-images))
2. Adding a `dde` user and group with your host UID/GID
3. Installing `su-exec` + `shadow` (Alpine) for privilege dropping and UID/GID remapping
4. Labeling the image with `dde.configured=true` and `dde.project={name}`

## Label Check

Before building, `ImageManager::hasLabel()` checks if the image already has `dde.configured=true`. If it does, the build is skipped.

The `isLayerCached()` method checks if the dev image already exists locally:

```php
public function isLayerCached(string $projectName): bool
{
    return $this->dockerManager->imageExists($this->getDevImageTag($projectName));
}
```

## Image Naming

Dev layer images follow the pattern:

```
dde-{projectName}:dev
```

For example: `dde-myproject:dev`

## Dockerfile Generation

The `generateDockerfile()` method creates a Dockerfile based on the detected distribution:

### Alpine-based Images

```dockerfile
FROM php:8.4-fpm-alpine
LABEL dde.configured="true"
LABEL dde.project="myproject"
USER root
RUN apk add --no-cache su-exec shadow \
    && addgroup -g 1000 dde || true \
    && adduser -u 1000 -G dde -D -h /home/dde dde || true
```

### Debian-based Images

```dockerfile
FROM php:8.4-fpm
LABEL dde.configured="true"
LABEL dde.project="myproject"
USER root
RUN groupadd -g 1000 dde 2>/dev/null || true; \
    useradd -u 1000 -g 1000 -m -d /home/dde -s /bin/sh dde 2>/dev/null || true
```

The Debian branch uses the C-binary `shadow` tools (`useradd`/`groupadd`), which ship in the base image — so no `apt-get` is needed. This is deliberate: Debian 13 (trixie) reimplemented `adduser`/`addgroup` as Perl scripts that abort under taint mode (`Insecure directory in $ENV{PATH}`) when PATH contains world-writable directories, as the whatwedo base images do. The shadow binaries are immune to that check.

Distribution detection is done by `detectDistro()`, which runs `cat /etc/os-release` inside an ephemeral container and checks for "alpine" in the output.

## Non-root base images

Some base images default to a non-root `USER` — for example, [`registry.whatwedo.ch/whatwedo/docker-base-images`](https://github.com/whatwedo/docker-base-images) **v3** ships `USER app`. Without intervention this breaks the dev layer twice:

- **At build time** the `RUN` would execute as the unprivileged user and fail to create the `dde` user (every command is `|| true`, so the build silently produces an image without a `dde` user — `dde exec --user dde` then fails).
- **At runtime** the dev layer *is* the container image, so the container would start as the non-root user. The entrypoint's user-creation/remap block is gated on `id -u = 0` and would be skipped, and the service adapters could not rewrite the root-owned nginx/php-fpm config to run as `dde`.

Adding `USER root` (and not resetting it) fixes both: the build runs as root, and the resulting image starts the container as root so the [entrypoint](entrypoint.md) and [service adapters](../extending/service-adapters.md) work. This restores the implicit contract dde relied on with the older root-by-default base images.

## Build Process

`buildDevLayer()` executes the full build:

1. Generate the dev image tag (`dde-{projectName}:dev`)
2. Detect the base image's distribution (Alpine vs Debian)
3. Generate the Dockerfile with host UID/GID from `UserContext`
4. Create a temporary directory, write the Dockerfile
5. Run `docker build` via `DockerManager::buildImage()`
6. Clean up the temporary directory

## Cache Invalidation

```php
public function invalidateLayer(string $projectName): void
```

Removes the dev layer image, forcing a rebuild on the next `project:up`. This is useful when:

- The base image has changed
- The host UID/GID has changed
- The `--build` flag is passed to `project:up`

## Integration with Project Lifecycle

The `ProjectLifecycleManager::up()` method calls `ImageManager::ensureDevLayers()` which:

1. Returns `null` early if the project name is empty
2. Checks if the layer is already cached (returns `null` if so)
3. Finds the first service with an `image` key whose base image has a usable shell (`DockerManager::imageHasShell()`); services without `image:` and services pointing at shell-less / distroless / scratch images are skipped, because the dev-layer Dockerfile runs `useradd` / `adduser` and cannot succeed without `/bin/sh`
4. Builds the dev layer for that service
5. Returns an array with the service name and image tag, or `null` when no shell-bearing `image:` service exists

This mirrors the shell-check `DockerComposeManager::generateOverride()` already applies before injecting the entrypoint, SSH-Agent socket or `env_file`.

The result is included in the return value of `ProjectLifecycleManager::up()` for informational purposes. To actually use the dev layer at runtime, the project config should set the container image explicitly (e.g. `containers.web.image: dde-myproject:dev`), which the runtime override will pick up via `DockerComposeManager::generateOverride()`.
