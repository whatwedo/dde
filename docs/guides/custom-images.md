---
title: "Custom Images"
---


dde works with any Docker image. The automatic dde layer detects whether the image is Alpine-based or Debian-based and adapts accordingly. This page shows examples for common non-PHP base images.

## Node.js

```yaml
# docker-compose.yml
services:
  web:
    image: node:20-alpine
    working_dir: /app
    volumes:
      - .:/app
    command: npm run dev
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`myapp.test`)'
      - 'traefik.http.routers.web.tls=true'
      - 'traefik.http.services.web.loadbalancer.server.port=3000'
```

The dde layer detects Alpine and installs `su-exec` + `shadow`. The entrypoint creates the `dde` user and execs `npm run dev` as the original command.

No built-in adapter applies (nginx/php-fpm/apache are not present), so the adapters step is a no-op.

### Custom Adapter for Node.js

If your Node.js app writes to specific directories, create a project adapter:

```sh
# .dde/adapters/node.sh
detect() {
    command -v node >/dev/null 2>&1
}

configure() {
    # Fix ownership of npm cache and app directories
    for dir in /home/dde/.npm /app/node_modules /app/.next; do
        [ -d "$dir" ] && chown -R dde:dde "$dir" 2>/dev/null || true
    done
}
```

## Python

```yaml
# docker-compose.yml
services:
  web:
    image: python:3.12-slim
    working_dir: /app
    volumes:
      - .:/app
    command: python manage.py runserver 0.0.0.0:8000
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`myapp.test`)'
      - 'traefik.http.routers.web.tls=true'
      - 'traefik.http.services.web.loadbalancer.server.port=8000'
```

For Debian-based Python images, dde installs `gosu` and creates the `dde` user. The `loadbalancer.server.port` label tells Traefik which port to route to.

## Ruby / Rails

```yaml
# docker-compose.yml
services:
  web:
    build:
      context: .
      target: dev
    volumes:
      - .:/app
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`myapp.test`)'
      - 'traefik.http.routers.web.tls=true'
      - 'traefik.http.services.web.loadbalancer.server.port=3000'
```

```dockerfile
FROM ruby:3.3-slim as base
WORKDIR /app

FROM base as dev
RUN apt-get update && apt-get install -y build-essential libpq-dev
```

## Go

```yaml
# docker-compose.yml
services:
  web:
    image: golang:1.22-alpine
    working_dir: /app
    volumes:
      - .:/app
    command: go run .
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`myapp.test`)'
      - 'traefik.http.routers.web.tls=true'
      - 'traefik.http.services.web.loadbalancer.server.port=8080'
```

## Multi-Service Projects

Projects with multiple containers get the dde layer applied to the first service with an `image` directive in the compose file. The runtime entrypoint override is generated for all services.

```yaml
services:
  web:
    image: whatwedo/nginx:v2.6
    labels:
      - 'traefik.enable=true'
      - 'traefik.http.routers.web.rule=Host(`myapp.test`)'
      - 'traefik.http.routers.web.tls=true'

  worker:
    image: php:8.4-cli
    command: php bin/console messenger:consume
```

Both `web` and `worker` receive the dde entrypoint override with UID/GID environment variables, volume mounts for adapters, and the original command preservation.

## Images Without a Package Manager

If your base image has no package manager (e.g., `scratch`, `distroless`, or minimal images), the automatic dde layer build will fail because it cannot install `gosu`/`su-exec`. In this case, you need to provide a dev-stage Dockerfile that starts from a full image:

```dockerfile
FROM gcr.io/distroless/base as base
COPY myapp /app/myapp

FROM debian:bookworm-slim as dev
COPY --from=base /app/myapp /app/myapp
RUN apt-get update && apt-get install -y curl
```

Use `target: dev` in your compose build config to select the development stage.
