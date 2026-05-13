<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

#[Group('e2e')]
final class EnvMigrationE2ETest extends TestCase
{
    use E2ETestHelper;

    private Filesystem $filesystem;

    public function testForcedRulesAppliedAndDatabaseUrlRejectedInNonInteractive(): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/.env', implode("\n", [
            'APP_ENV=prod',
            'APP_SECRET=abc123',
            'MAILER_DSN=smtp://old-host:25',
            'DATABASE_URL=mysql://custom:secret@localhost:3306/myapp?serverVersion=5.7',
            '',
        ]));

        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-envmig',
            '--services=mariadb,mailpit',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init should succeed');

        // .env — APP_ENV and DATABASE_URL stay untouched (APP_ENV is never touched by design;
        // DATABASE_URL migration is rejected in non-interactive mode)
        $envContent = file_get_contents($this->projectDir.'/.env');
        $this->assertIsString($envContent);
        $this->assertStringContainsString('APP_ENV=prod', $envContent, 'APP_ENV in .env must remain unchanged');
        $this->assertStringContainsString('APP_SECRET=abc123', $envContent, 'APP_SECRET must remain unchanged');
        $this->assertStringContainsString('DATABASE_URL=mysql://custom:secret@localhost:3306/myapp', $envContent, 'DATABASE_URL in .env must remain unchanged (non-interactive rejects)');

        // .env — MAILER_DSN forced to null://null (mailpit is a configured dde service)
        $this->assertStringContainsString('MAILER_DSN=null://null', $envContent, 'MAILER_DSN in .env must be rewritten to null://null');
        $this->assertStringNotContainsString('smtp://old-host:25', $envContent, 'Previous MAILER_DSN value must be gone');

        // docker-compose.yml — forced environment injections
        $webEnv = $this->extractServiceEnvironment('web');
        $this->assertArrayNotHasKey(
            'APP_ENV',
            $webEnv,
            'APP_ENV must not be migrated into compose — Symfony stops loading .env files when APP_ENV is in the environment',
        );
        $this->assertSame('smtp://mailpit:1025', $webEnv['MAILER_DSN'] ?? null, 'MAILER_DSN must point to mailpit in compose');

        // docker-compose.yml — DATABASE_URL from migration NOT present.
        // The existing addServiceEnvironment helper ALSO skips adding DATABASE_URL when
        // one is defined in .env (which is the case here), so compose stays clean of DATABASE_URL.
        $this->assertArrayNotHasKey(
            'DATABASE_URL',
            $webEnv,
            'DATABASE_URL should not be in compose when .env has it and user did not accept migration',
        );
    }

    public function testIdempotentReRunProducesNoAdditionalChanges(): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/.env', implode("\n", [
            'APP_ENV=dev',
            'MAILER_DSN=null://null',
            '',
        ]));

        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-idem',
            '--services=mariadb,mailpit',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'first project:init should succeed');

        $envAfterFirst = file_get_contents($this->projectDir.'/.env');
        $composeAfterFirst = file_get_contents($this->projectDir.'/docker-compose.yml');

        // Second run must not drift
        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-idem',
            '--services=mariadb,mailpit',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'second project:init should succeed');

        $this->assertSame($envAfterFirst, file_get_contents($this->projectDir.'/.env'), '.env must not drift on re-run');
        $this->assertSame($composeAfterFirst, file_get_contents($this->projectDir.'/docker-compose.yml'), 'compose must not drift on re-run');
    }

    public function testMailerDsnUnchangedWhenMailpitNotConfigured(): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/.env', "MAILER_DSN=smtp://some-host:25\n");

        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-nomailpit',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init should succeed');

        $envContent = file_get_contents($this->projectDir.'/.env');
        $this->assertIsString($envContent);
        $this->assertStringContainsString('MAILER_DSN=smtp://some-host:25', $envContent, 'MAILER_DSN must be untouched when mailpit not configured');
        $this->assertStringNotContainsString('null://null', $envContent);

        $webEnv = $this->extractServiceEnvironment('web');
        $this->assertArrayNotHasKey('MAILER_DSN', $webEnv, 'compose must not gain MAILER_DSN when mailpit is absent');
        $this->assertArrayNotHasKey('APP_ENV', $webEnv, 'APP_ENV must never be injected into compose');
    }

    /**
     * @return array<string, string>
     */
    private function extractServiceEnvironment(string $service): array
    {
        $data = Yaml::parseFile($this->projectDir.'/docker-compose.yml');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('services', $data);
        $this->assertIsArray($data['services']);
        $this->assertArrayHasKey($service, $data['services']);
        $this->assertIsArray($data['services'][$service]);

        $env = $data['services'][$service]['environment'] ?? [];
        $this->assertIsArray($env);

        $result = [];

        foreach ($env as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $eq = strpos($value, '=');

                if ($eq === false) {
                    continue;
                }

                $result[substr($value, 0, $eq)] = substr($value, $eq + 1);
            } elseif (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';

        $this->projectDir = sys_get_temp_dir().'/dde-e2e-envmig-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectDir);

        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-envmig-data-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDataDir);

        $this->filesystem->dumpFile($this->projectDir.'/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: nginx:latest',
            '    volumes:',
            '      - ./:/var/www',
            '',
        ]));

        $this->filesystem->dumpFile($this->projectDir.'/index.php', "<?php\necho 'envmig test';\n");
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->projectDir);
        $this->filesystem->remove($this->tempDataDir);
    }
}
