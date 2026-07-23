.PHONY: help install test test-e2e ecs phpstan rector ci build build-binary clean

## General
help:           ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

install:        ## Install dependencies
	composer install

## Quality
ecs:        ## Fix coding standard automatically
	vendor/bin/ecs check --fix

phpstan:        ## Run PHPStan Level 8
	@php bin/console cache:warmup --env=dev --quiet 2>/dev/null || true
	vendor/bin/phpstan analyse --memory-limit=512M

rector:         ## Run Rector (apply refactorings)
	vendor/bin/rector

## Testing
test:           ## Run all tests (except E2E)
	vendor/bin/phpunit --exclude-group=e2e

test-e2e:       ## Run E2E tests (requires Docker)
	vendor/bin/phpunit --group=e2e

test-unit:      ## Run unit tests only
	vendor/bin/phpunit --testsuite=unit

test-int:       ## Run integration tests only
	vendor/bin/phpunit --testsuite=integration

test-coverage:  ## Run tests with coverage report
	XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html var/coverage

qa:             ## Full check: ECS + PHPStan + Rector + Tests (w/o e2E)
	@echo "\033[33m=== ECS ===\033[0m"
	$(MAKE) ecs
	@echo "\033[33m=== PHPStan ===\033[0m"
	$(MAKE) phpstan
	@echo "\033[33m=== Rector Dry-Run ===\033[0m"
	$(MAKE) rector
	@echo "\033[33m=== Tests ===\033[0m"
	$(MAKE) test
	@echo "\033[32m=== QA passed ===\033[0m"

## Build
build:          ## Build PHAR binary
	composer install --no-dev --optimize-autoloader
	curl -fsSL https://github.com/box-project/box/releases/download/4.7.0/box.phar -o /tmp/box.phar
	./scripts/compile-phar.sh /tmp/box.phar
	rm -rf var/cache/*
	composer install

build-binary:   ## Build standalone binary (PHAR + micro.sfx)
	./scripts/build.sh

clean:          ## Delete temporary files
	rm -rf var/cache var/coverage vendor bin/dde
