---
title: "Mailpit"
---


Mailpit captures outgoing email from project containers and provides a web UI for inspection. It runs as a global service (started by `system:up`).

## Web UI

The Mailpit web interface is accessible at:

- **HTTP:** http://mail.test
- **HTTPS:** https://mail.test

## SMTP Configuration

Configure your project to send mail through Mailpit's SMTP server:

| Setting | Value |
|---|---|
| SMTP Host | `mail` (network alias) |
| SMTP Port | `1025` |
| Authentication | None |
| Encryption | None |

### Symfony Mailer DSN

```
smtp://mail:1025
```

### Automatic MAILER_DSN

When a project's `docker-compose.yml` contains a `mailpit` service, dde automatically injects `MAILER_DSN=smtp://mailpit:1025` into the primary service. The variable is only added if it is not already defined in the service environment or in the project's `.env`/`.env.dev` files.

Note: The auto-injected DSN uses `mailpit` as the hostname (the compose service name), while the global Mailpit service uses the network alias `mail`. Both resolve to the same container when it is running on the `dde` network.

## Global vs Project Mailpit

Mailpit runs as a global service (started by `system:up`). It can also be declared as a project service in `.dde/config.yml`, but dde prevents starting a second instance of the same service.
