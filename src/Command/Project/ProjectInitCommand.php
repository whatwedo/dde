<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectInitAdaptationManager;
use App\Manager\ProjectInitManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'project:init',
    description: 'Initialize a project for dde',
)]
final class ProjectInitCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly ProjectInitManager $projectInitManager,
        private readonly ProjectInitAdaptationManager $adaptationManager,
        private readonly DockerComposeManager $dockerComposeManager,
        private readonly ServiceRegistry $serviceRegistry,
        FormatterResolver $formatterResolver,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Project name')
            ->addOption('services', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of services, each optionally pinned with ":version" (e.g. "mariadb:11.4,valkey,postgres:16")')
            ->addOption('container', null, InputOption::VALUE_REQUIRED, 'Main container name')
            ->addOption('shell', null, InputOption::VALUE_REQUIRED, 'Shell for the container')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip all confirmations')
            ->addOption('no-docker', null, InputOption::VALUE_NONE, 'Skip docker-compose/Dockerfile adaptation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = getcwd();

        if ($projectDir === false) {
            return $this->outputError($output, $input, 'Could not determine current working directory.');
        }

        $formatter = $this->resolveFormatter($output, $input);
        $isDryRun = (bool) $input->getOption('dry-run');
        $isForce = (bool) $input->getOption('force');
        $noDocker = (bool) $input->getOption('no-docker');
        $suppressInteractive = ! $input->isInteractive() || !$formatter->isInteractive();

        // Remove legacy v1 config file
        $legacyConfigPath = $projectDir.'/.dde.yml';

        if ($this->filesystem->exists($legacyConfigPath)) {
            if (! $suppressInteractive) {
                $io->writeln('  <comment>Removing</comment> .dde.yml (legacy v1 config)');
            }

            if (! $isDryRun) {
                $this->filesystem->remove($legacyConfigPath);
            }
        }

        // Remove legacy v1 configure-image.sh (now handled by dev layers)
        $legacyConfigureImagePath = $projectDir.'/.dde/configure-image.sh';

        if ($this->filesystem->exists($legacyConfigureImagePath)) {
            if (! $suppressInteractive) {
                $io->writeln('  <comment>Removing</comment> .dde/configure-image.sh (legacy v1 script)');
            }

            if (! $isDryRun) {
                $this->filesystem->remove($legacyConfigureImagePath);
            }
        }

        // Detect compose file early for smart defaults
        $composePath = $this->dockerComposeManager->findComposeFileOrNull($projectDir);
        $firstComposeService = $this->adaptationManager->detectFirstService($composePath);

        // Resolve user input
        $name = $this->resolveProjectName($input, $io, $projectDir, $suppressInteractive);
        $services = $this->resolveServices($input, $output, $io, $suppressInteractive);
        $container = $this->resolveContainer($input, $io, $suppressInteractive, $firstComposeService);
        $shell = $this->resolveShell($input);

        // Create .dde/ structure
        $result = $this->projectInitManager->createDirectoryStructure($projectDir, $name, $services, $container, $shell, $isDryRun);

        if ($formatter->isInteractive()) {
            $this->printStructureResult($io, $result, $isDryRun);
        }

        // Docker file adaptation
        $dockerResult = [];

        if (! $noDocker) {
            $dockerResult = $this->handleDockerAdaptation($projectDir, $name, $container, $isDryRun, $isForce, $io, $suppressInteractive);
        }

        // Apply env migrations (forced: MAILER_DSN; prompted: DATABASE_URL)
        if (! $isDryRun) {
            $serviceDefinitions = [];

            foreach ($services as $s) {
                if (is_string($s)) {
                    $serviceDefinitions[] = $this->serviceRegistry->getServiceConfig($s);
                } else {
                    $serviceDefinitions[] = $this->serviceRegistry->getServiceConfig($s['name'], $s['version']);
                }
            }

            $envMigrations = $this->adaptationManager->proposeEnvMigrations(
                $projectDir,
                $name,
                $container,
                $this->serviceNames($services),
                $serviceDefinitions,
            );

            foreach ($envMigrations['appliedChanges'] as $change) {
                if ($formatter->isInteractive()) {
                    $io->writeln(sprintf('  <info>%s</info>', $change));
                }
            }

            $accepted = [];

            foreach ($envMigrations['proposals'] as $proposal) {
                if ($suppressInteractive) {
                    continue; // Default: reject in non-interactive mode
                }

                $io->section(sprintf('%s migration', $proposal->variable));
                $io->writeln(sprintf('  <comment>current:</comment> %s', $proposal->originalValue));
                $io->writeln(sprintf('  <comment>.env:</comment>    %s', $proposal->envTargetValue));
                $io->writeln(sprintf('  <comment>compose:</comment> %s', $proposal->composeValue));

                if ($io->confirm(sprintf('Apply this %s migration?', $proposal->variable), true)) {
                    $accepted[] = $proposal;
                }
            }

            if ($accepted !== []) {
                $this->adaptationManager->applyEnvMigrations($projectDir, $container, $accepted);
            }
        }

        return $this->outputResult($output, $input, $result, $dockerResult, $isDryRun);
    }

    private function resolveProjectName(InputInterface $input, SymfonyStyle $io, string $projectDir, bool $useDefaults): string
    {
        $name = $input->getOption('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $default = basename($projectDir);

        if ($useDefaults) {
            return $default;
        }

        $result = $io->ask('Project name', $default);

        return is_string($result) ? $result : $default;
    }

    /**
     * @return list<string|array{name: string, version: string}>
     */
    private function resolveServices(InputInterface $input, OutputInterface $output, SymfonyStyle $io, bool $useDefaults): array
    {
        $servicesOption = $input->getOption('services');

        if (is_string($servicesOption) && $servicesOption !== '') {
            return $this->parseServicesOption($servicesOption);
        }

        if ($useDefaults) {
            return [];
        }

        $io->section('Services');

        $available = $this->serviceRegistry->getAllServiceTypes();
        $question = new ChoiceQuestion(
            'Which services do you need? (comma-separated, empty for none)',
            $available,
            '',
        );
        $question->setMultiselect(true);
        $question->setErrorMessage('Service "%s" is not available.');

        $innerValidator = $question->getValidator();
        $question->setValidator(static function (mixed $selected) use ($innerValidator): mixed {
            if ($selected === null || $selected === '') {
                return [];
            }

            return $innerValidator !== null ? ($innerValidator)($selected) : $selected;
        });

        $selected = $this->questionHelper()->ask($input, $output, $question);

        /** @var list<string> $selected */
        $selected = is_array($selected) ? array_values(array_filter($selected, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];

        if ($selected === []) {
            return [];
        }

        $io->listing($selected);

        $resolved = [];

        foreach ($selected as $service) {
            $version = $this->resolveServiceVersion($service, $input, $output, $io);

            $resolved[] = $version === null
                ? $service
                : [
                    'name' => $service,
                    'version' => $version,
                ];

            $io->newLine();
        }

        return $resolved;
    }

    /**
     * Returns the pinned version, or null to signal "use the service's default".
     */
    private function resolveServiceVersion(string $service, InputInterface $input, OutputInterface $output, SymfonyStyle $io): ?string
    {
        if (! $this->serviceRegistry->supportsVersionChoice($service)) {
            return null;
        }

        $default = $this->serviceRegistry->getServiceVersion($service);

        if ($io->confirm(sprintf('[%s] Use default version %s?', $service, $default), true)) {
            return null;
        }

        $knownVersions = $this->serviceRegistry->getKnownVersions($service);
        $customLabel = 'custom…';

        $choice = new ChoiceQuestion(
            sprintf('[%s] Select version', $service),
            [...$knownVersions, $customLabel],
            $default,
        );
        $choice->setErrorMessage('Version "%s" is not available.');

        $picked = $this->questionHelper()->ask($input, $output, $choice);

        if (! is_string($picked)) {
            return null;
        }

        if ($picked !== $customLabel) {
            return $picked;
        }

        $customQuestion = new Question(sprintf('[%s] Version', $service));
        $customQuestion->setValidator(static function (mixed $value): string {
            if (! is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Version must not be empty.');
            }

            return trim($value);
        });

        $custom = $this->questionHelper()->ask($input, $output, $customQuestion);

        return is_string($custom) && $custom !== '' ? $custom : null;
    }

    private function questionHelper(): QuestionHelper
    {
        $helper = $this->getHelper('question');
        \assert($helper instanceof QuestionHelper);

        return $helper;
    }

    /**
     * Parses --services CSV into a mixed list. Entries without ":version" stay
     * as bare strings; entries like "mariadb:11.4" become `{name, version}`.
     *
     * @return list<string|array{name: string, version: string}>
     */
    private function parseServicesOption(string $value): array
    {
        $out = [];

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (! str_contains($entry, ':')) {
                $out[] = $entry;

                continue;
            }

            [$name, $version] = explode(':', $entry, 2);
            $name = trim($name);
            $version = trim($version);

            if ($name === '' || $version === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'version' => $version,
            ];
        }

        return $out;
    }

    /**
     * @param list<string|array{name: string, version: string}> $services
     *
     * @return list<string>
     */
    private function serviceNames(array $services): array
    {
        return array_map(
            static fn (string|array $s): string => is_string($s) ? $s : $s['name'],
            $services,
        );
    }

    private function resolveContainer(InputInterface $input, SymfonyStyle $io, bool $useDefaults, ?string $detectedDefault = null): string
    {
        $container = $input->getOption('container');

        if ($container !== null && is_string($container) && $container !== '') {
            return $container;
        }

        $default = $detectedDefault ?? 'web';

        if ($useDefaults) {
            return $default;
        }

        $result = $io->ask('Main container name', $default);

        return is_string($result) ? $result : $default;
    }

    private function resolveShell(InputInterface $input): ?string
    {
        $shell = $input->getOption('shell');

        if ($shell !== null && is_string($shell) && $shell !== '') {
            return $shell;
        }

        return null;
    }

    /**
     * @param array{created: list<string>, skipped: list<string>} $result
     */
    private function printStructureResult(SymfonyStyle $io, array $result, bool $isDryRun): void
    {
        foreach ($result['created'] as $path) {
            $label = $isDryRun ? 'Would create' : 'Created';
            $io->writeln(sprintf('  <info>%s</info> %s', $label, $path));
        }

        foreach ($result['skipped'] as $path) {
            $io->writeln(sprintf('  <comment>Skipped</comment> %s (already exists)', $path));
        }
    }

    /**
     * @return array{compose_modified?: bool, dockerfile_modified?: bool, compose_changes?: list<string>, dockerfile_changes?: list<string>}
     */
    private function handleDockerAdaptation(
        string $projectDir,
        string $name,
        string $container,
        bool $isDryRun,
        bool $isForce,
        SymfonyStyle $io,
        bool $suppressInteractive,
    ): array {
        $adaptation = $this->adaptationManager->adaptDocker($projectDir, $name, $container);

        $result = [
            'compose_modified' => false,
            'dockerfile_modified' => false,
            'compose_changes' => [],
            'dockerfile_changes' => [],
        ];

        // Handle compose adaptation
        $compose = $adaptation['compose'];

        if ($compose === null && ! $suppressInteractive) {
            $io->warning('Could not parse docker-compose file.');
        }

        if ($compose !== null && $compose['changes'] !== []) {
            if (! $suppressInteractive && $compose['diff'] !== '') {
                $io->section('docker-compose.yml changes');
                $io->writeln($compose['diff']);

                foreach ($compose['changes'] as $change) {
                    $io->writeln(sprintf('  - %s', $change));
                }
            }

            $shouldApply = $isForce || $suppressInteractive || $io->confirm('Apply these changes to docker-compose.yml?', true);

            if ($shouldApply && ! $isDryRun) {
                $this->adaptationManager->writeCompose($compose['composePath'], $compose['config']);
            }

            $result['compose_modified'] = $shouldApply && ! $isDryRun;
            $result['compose_changes'] = $compose['changes'];
        }

        // Handle dockerfile adaptation
        $dockerfile = $adaptation['dockerfile'];

        if ($dockerfile === null && ! $suppressInteractive) {
            $io->warning('Could not parse Dockerfile.');
        }

        if ($dockerfile !== null && $dockerfile['changes'] !== []) {
            if (! $suppressInteractive && $dockerfile['diff'] !== '') {
                $io->section('Dockerfile changes');
                $io->writeln($dockerfile['diff']);
            }

            $shouldApply = $isForce || $suppressInteractive || $io->confirm('Apply these changes to Dockerfile?', true);

            if ($shouldApply && ! $isDryRun) {
                $this->adaptationManager->writeDockerfile($dockerfile['dockerfilePath'], $dockerfile['lines']);
            }

            $result['dockerfile_modified'] = $shouldApply && ! $isDryRun;
            $result['dockerfile_changes'] = $dockerfile['changes'];
        }

        return $result;
    }

    /**
     * @param array{created: list<string>, skipped: list<string>} $structureResult
     * @param array{compose_modified?: bool, dockerfile_modified?: bool, compose_changes?: list<string>, dockerfile_changes?: list<string>} $dockerResult
     */
    private function outputResult(
        OutputInterface $output,
        InputInterface $input,
        array $structureResult,
        array $dockerResult,
        bool $isDryRun,
    ): int {
        $formatter = $this->resolveFormatter($output, $input);

        $data = [
            'structure' => $structureResult,
            'docker' => $dockerResult,
        ];

        $prefix = $isDryRun ? 'Dry run: project would be initialized' : 'Project initialized';

        return $formatter->success($data, $prefix.' successfully.');
    }

    private function outputError(OutputInterface $output, InputInterface $input, string $message): int
    {
        return $this->resolveFormatter($output, $input)->error($message);
    }
}
