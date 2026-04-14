# Contributing

Thank you for your interest in contributing to dde!

Please see our [Contributing Guide](docs/contributing/development-setup.md) for details on:

- [Development setup](docs/contributing/development-setup.md)
- [Architecture overview](docs/contributing/architecture.md)
- [Testing](docs/contributing/testing.md)
- [Adding commands](docs/contributing/adding-a-command.md)
- [Adding services](docs/contributing/adding-a-service.md)
- [Adding adapters](docs/contributing/adding-an-adapter.md)
- [Release process](docs/contributing/release-process.md)

## Quick Start

```bash
git clone git@github.com:whatwedo/dde.git
cd dde
composer install
bin/console list
make qa
```

## Code Style

We use ECS with [whatwedo/php-coding-standard](https://github.com/whatwedo/php-coding-standard). Run `make ecs` to check and auto-fix.

## Quality Checks

Before submitting a pull request, ensure all checks pass:

```bash
make qa
```

This runs ECS, PHPStan (level 8), Rector, and all unit/integration tests.
