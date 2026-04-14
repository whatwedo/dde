---
title: "Testing"
---


dde uses PHPUnit for all tests. Tests are organized into unit tests and E2E tests, with strict separation.

## Test Structure

Tests mirror the `src/` directory structure:

```
tests/
  Unit/
    Manager/
      ImageManagerTest.php
      ConfigManagerTest.php
      ...
    Doctor/
      Check/
        BinaryPathCheckTest.php
        ...
    ...
  E2E/
    ...
```

## Running Tests

```bash
# All tests except E2E (default for CI)
make test

# Unit tests only
make test-unit

# E2E tests (requires Docker)
make test-e2e

# Tests with coverage report
make test-coverage
```

## Test Categories

### Unit Tests

Unit tests verify individual classes in isolation. They mock dependencies and do not require Docker or any external services.

Every new class should have a corresponding unit test covering at minimum:

- The happy path (expected behavior with valid inputs)
- The most important error cases (invalid inputs, edge cases)

### E2E Tests

E2E tests require a running Docker daemon and are tagged with:

```php
#[Group('e2e')]
```

These tests are excluded from the standard test suite and CI. Run them explicitly with `make test-e2e`.

## Full QA Suite

The `make qa` target runs the complete quality assurance pipeline:

1. **ECS** -- coding standard check and auto-fix
2. **PHPStan** -- static analysis at level 8
3. **Rector** -- automated refactoring (dry-run in QA mode)
4. **Tests** -- PHPUnit excluding E2E

All four steps must pass before a commit is considered ready.

## Writing Tests

### Example: Unit Test

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Manager;

use App\Manager\ImageManager;
use App\Manager\DockerManager;
use App\Model\UserContext;
use PHPUnit\Framework\TestCase;

final class ImageManagerTest extends TestCase
{
    public function testHasLabelReturnsFalseForMissingImage(): void
    {
        $dockerManager = $this->createMock(DockerManager::class);
        $dockerManager->method('inspect')
            ->willThrowException(new \RuntimeException('not found'));

        $userContext = new UserContext(uid: 1000, gid: 1000);
        $manager = new ImageManager($dockerManager, $userContext);

        self::assertFalse($manager->hasLabel('nonexistent:latest', 'dde.configured'));
    }
}
```

### Conventions

- Test classes are `final`
- Test methods use `test` prefix (not `@test` annotation)
- Use `self::assert*()` instead of `$this->assert*()`
- Mock external dependencies (Docker, filesystem, processes)
- Each test method tests one behavior
