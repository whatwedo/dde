---
title: "Development Setup"
---


## Prerequisites

- PHP 8.5+
- Composer
- Docker (for E2E tests)
- make

## Getting Started

```bash
git clone git@github.com:whatwedo/dde.git
cd dde
composer install
bin/console list  # Verify it works
```

To use your local development version instead of the installed binary, set an alias:

```bash
alias dde='/path/to/dde/bin/console'
```

## Quality Assurance

Run the full QA suite:

```bash
make qa
```

This executes, in order:

1. **ECS** -- coding standard check and auto-fix (`make ecs`)
2. **PHPStan** -- static analysis at level 8 (`make phpstan`)
3. **Rector** -- automated refactoring, dry-run mode (`make rector`)
4. **Tests** -- PHPUnit, excluding E2E tests (`make test`)

### Individual Tools

```bash
make ecs          # Fix coding standard (whatwedo/php-coding-standard)
make phpstan      # Run PHPStan level 8 (auto-warms cache)
make rector       # Run Rector (PHP 8.5 + Symfony 8 rule sets)
make test         # Run all tests (excludes E2E)
make test-e2e     # Run E2E tests (requires Docker)
make test-unit    # Run unit tests only
```

## Building

### PHAR Binary

```bash
make build
```

This produces `bin/dde.phar` using [humbug/box](https://github.com/box-project/box).

### Standalone Binary

```bash
make build-binary
```

This combines the PHAR with `micro.sfx` from [static-php-cli](https://github.com/crazywhalecc/static-php-cli) into a standalone executable. The build script (`scripts/build.sh`) automates the micro.sfx download and reads the target PHP version from `composer.json`.

## Project Structure

```
src/
  Command/          # CLI commands (Project/*, System/*)
  Manager/          # Business logic orchestration
  Service/          # System service implementations
  Config/           # Configuration DTOs and TreeBuilder definitions
  Model/            # Data models
  Parser/           # Docker Compose and Dockerfile parsers
  Modifier/         # Docker Compose persistent modifications
  Adapter/          # Container entrypoint adapters
  Database/         # Database adapter implementations
  Doctor/           # Health check system
  Event/            # Project lifecycle events
  Hook/             # Hook execution system
  Plugin/           # Plugin loading and proxy commands
  Output/           # Output formatters (Text, JSON)
  Util/             # Utility classes

tests/
  Unit/             # Unit tests (mirrors src/ structure)
  Integration/      # Integration tests
  E2E/              # End-to-end tests (require Docker)

resources/
  adapters/         # Built-in adapter scripts (nginx, php-fpm, apache)
  entrypoint.sh     # Container entrypoint script
  docker/           # Dockerfiles for system services
```
