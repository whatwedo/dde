<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Adapter\AdapterRegistry;
use App\Config\WorktreeInfo;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\MkcertManager;
use App\Manager\WorktreeManager;
use App\Model\UserContext;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorktreeComposeProjectTest extends TestCase
{
    public function testAllComposeOperationsUseTheSameWorktreeProject(): void
    {
        $commands = [];
        $worktree = new WorktreeInfo('/projects/shop', '/projects/shop-feature', 'feature', 'shop-feature');
        $manager = $this->manager($worktree, $commands);

        $manager->up('/projects/shop-feature');
        $manager->down('/projects/shop-feature');
        $manager->stop('/projects/shop-feature');
        $manager->build('/projects/shop-feature');
        $manager->pull('/projects/shop-feature');
        $manager->ps('/projects/shop-feature');
        $manager->exec('/projects/shop-feature', 'web', ['true']);
        $manager->logs('/projects/shop-feature', 'web');

        self::assertCount(8, $commands);
        $names = [];
        foreach ($commands as $command) {
            self::assertSame(['docker', 'compose', '--project-name'], array_slice($command, 0, 3));
            $names[] = $command[3];
            self::assertMatchesRegularExpression('/^dde-wt-feature-[a-f0-9]{12}$/', $command[3]);
        }

        self::assertCount(1, array_unique($names));
    }

    public function testEqualBasenamesInDifferentWorktreesUseDifferentProjects(): void
    {
        $firstCommands = [];
        $secondCommands = [];
        $first = new WorktreeInfo('/projects/shop', '/projects/a/feature', 'first', 'feature');
        $second = new WorktreeInfo('/projects/shop', '/projects/b/feature', 'second', 'feature');
        $this->manager($first, $firstCommands)->up($first->worktreeDirectory);
        $this->manager($second, $secondCommands)->up($second->worktreeDirectory);

        self::assertNotSame($firstCommands[0], $secondCommands[0]);
    }

    public function testConfigResolutionUsesTheSameProjectAsStartup(): void
    {
        $directory = sys_get_temp_dir().'/dde-compose-project-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/compose.yml', "name: shared\nservices:\n  web:\n    image: nginx:latest\n");
        $commands = [];
        $worktree = new WorktreeInfo('/projects/shop', $directory, 'feature', 'feature');
        $manager = $this->manager($worktree, $commands, '{"services":{"web":{"image":"nginx:latest"}}}');

        try {
            $manager->getMergedServices($directory, $directory.'/compose.override.yml');
            $manager->up($directory);
            self::assertSame('--project-name', $commands[0][2]);
            self::assertSame($commands[0][3], $commands[1][3]);
        } finally {
            unlink($directory.'/compose.yml');
            rmdir($directory);
        }
    }

    public function testMainCheckoutPreservesComposeProjectNameResolution(): void
    {
        $commands = [];
        $this->manager(null, $commands)->up('/projects/shop');

        self::assertSame(['docker', 'compose', 'up', '-d'], $commands[0]);
    }

    public function testBranchSwitchDoesNotChangeComposeProject(): void
    {
        $firstCommands = [];
        $secondCommands = [];
        $first = new WorktreeInfo('/projects/shop', '/projects/feature', 'first', 'feature');
        $second = new WorktreeInfo('/projects/shop', '/projects/feature', 'second', 'feature');
        $this->manager($first, $firstCommands)->up($first->worktreeDirectory);
        $this->manager($second, $secondCommands)->up($second->worktreeDirectory);

        self::assertSame($firstCommands[0], $secondCommands[0]);
    }

    /**
     * @param list<list<string>> $commands
     */
    private function manager(?WorktreeInfo $worktree, array &$commands, string $output = '[]'): DockerComposeManager
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);
        $factory = $this->createMock(ProcessFactory::class);
        $factory->expects($this->atLeastOnce())->method('create')->willReturnCallback(
            static function (array $command) use (&$commands, $process): Process {
                $commands[] = $command;

                return $process;
            },
        );
        $worktrees = $this->createStub(WorktreeManager::class);
        $worktrees->method('detect')->willReturn($worktree);

        return new DockerComposeManager(
            new AdapterRegistry(dirname(__DIR__, 3).'/resources', '/tmp/dde-compose-test'),
            $this->createStub(DockerManager::class),
            new UserContext(),
            $worktrees,
            $this->createStub(MkcertManager::class),
            processFactory: $factory,
        );
    }
}
