---
title: "Testing"
---


dde uses PHPUnit for all tests. Tests are organized into three tiers — unit, integration, and E2E — with strict separation.

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
  Integration/
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

### Unit Tests (`tests/Unit/`)

Unit tests verify individual classes in isolation. They mock dependencies and do not require Docker, external services, or CLI invocation. This is the default tier: every new class gets a unit test, written together with the code (TDD: red → green → refactor), covering at minimum:

- The happy path (expected behavior with valid inputs)
- The most important error cases (invalid inputs, edge cases)

### Integration Tests (`tests/Integration/`)

Integration tests wire real collaborators (e.g. a real `DockerComposeParser` instead of a mock) but still run without a Docker daemon. They are part of the default `make test` run.

### E2E Tests (`tests/E2E/`)

E2E tests spawn the `bin/console` CLI against a real Docker daemon and are tagged with:

```php
#[Group('e2e')]
```

These tests are excluded from the standard test suite and CI. Run them explicitly with `make test-e2e`.

Every E2E test must own its setUp/tearDown: an isolated `tempDir`, `DDE_CONFIG_DIR` / `DDE_DATA_DIR` pointing at a random path, and containers cleaned up with `$this->cleanupLeftoverContainers()`.

### Regression Tests

When a fix addresses a real-world bug, add a regression test that would have caught the bug. Verify it by reverting the fix and watching the test fail.

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
- Use `#[DataProvider]` with `iterable<string, array{…}>` providers for parametrised cases
- Mark tests that do not assert on their mocks with the `#[AllowMockObjectsWithoutExpectations]` attribute
