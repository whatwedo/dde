---
title: "Dev Layer Builder"
---


The `ImageManager` builds a development layer on top of project Docker images. This layer adds the `dde` user with the correct UID/GID, enabling file permission compatibility between the host and container.

## Why a Dev Layer?

Docker images typically run as root or a predefined user. For development, files created inside the container need to be owned by your host user. The dev layer solves this by:

1. Adding a `dde` user and group with your host UID/GID
2. Installing `su-exec` (Alpine) or `gosu` (Debian) for privilege dropping
3. Labeling the image with `dde.configured=true` and `dde.project={name}`

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
RUN apk add --no-cache su-exec shadow \
    && addgroup -g 1000 dde || true \
    && adduser -u 1000 -G dde -D -h /home/dde dde || true
```

### Debian-based Images

```dockerfile
FROM php:8.4-fpm
LABEL dde.configured="true"
LABEL dde.project="myproject"
RUN apt-get update && apt-get install -y --no-install-recommends gosu && rm -rf /var/lib/apt/lists/* \
    && addgroup --gid 1000 dde || true \
    && adduser --uid 1000 --gid 1000 --disabled-password --gecos "" --home /home/dde dde || true
```

Distribution detection is done by `detectDistro()`, which runs `cat /etc/os-release` inside an ephemeral container and checks for "alpine" in the output.

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
3. Finds the first service with an `image` key in the compose file
4. Builds the dev layer for that service
5. Returns an array with the service name and image tag

The result is included in the return value of `ProjectLifecycleManager::up()` for informational purposes. To actually use the dev layer at runtime, the project config should set the container image explicitly (e.g. `containers.web.image: dde-myproject:dev`), which the runtime override will pick up via `DockerComposeManager::generateOverride()`.
