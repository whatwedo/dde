<?php

declare(strict_types=1);

namespace App\Parser;

use Symfony\Component\Filesystem\Filesystem;

readonly class DockerfileParser
{
    private const array V1_BOILERPLATE_LINE_PATTERNS = [
        '/^\s*COPY\s+\.dde\/configure-image\.sh/',
        '/^\s*ARG\s+DDE_UID/',
        '/^\s*ARG\s+DDE_GID/',
    ];

    private const string CONFIGURE_IMAGE_PATTERN = '/\/tmp\/dde-configure-image\.sh/';

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<string>
     *
     * @throws \RuntimeException
     */
    public function parse(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            throw new \RuntimeException(sprintf('Dockerfile not found: "%s"', $path));
        }

        $content = $this->filesystem->readFile($path);

        return explode("\n", $content);
    }

    /**
     * Finds v1 boilerplate lines within a specific named stage.
     *
     * @param list<string> $lines
     *
     * @return list<int>
     */
    public function findV1BoilerplateInStage(array $lines, string $stageName): array
    {
        $range = $this->findStageRange($lines, $stageName);

        if ($range === null) {
            return [];
        }

        $found = [];

        for ($lineNumber = $range['start']; $lineNumber <= $range['end']; $lineNumber++) {
            $line = $lines[$lineNumber];

            foreach (self::V1_BOILERPLATE_LINE_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $lineNumber;

                    break;
                }
            }
        }

        // RUN instructions referencing configure-image.sh within the stage
        foreach ($this->findRunInstructionRanges($lines) as $runRange) {
            if ($runRange['start'] < $range['start'] || $runRange['end'] > $range['end']) {
                continue;
            }

            $combined = '';

            for ($i = $runRange['start']; $i <= $runRange['end']; $i++) {
                $combined .= $lines[$i]."\n";
            }

            if (preg_match(self::CONFIGURE_IMAGE_PATTERN, $combined) === 1) {
                for ($i = $runRange['start']; $i <= $runRange['end']; $i++) {
                    if (! in_array($i, $found, true)) {
                        $found[] = $i;
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Finds the line range of a named stage (FROM ... AS <name> until the next FROM or EOF).
     *
     * @param list<string> $lines
     *
     * @return array{start: int, end: int}|null
     */
    public function findStageRange(array $lines, string $stageName): ?array
    {
        $start = null;
        $count = count($lines);
        $pattern = '/^\s*FROM\s+.+\s+AS\s+'.preg_quote($stageName, '/').'\s*$/i';

        for ($i = 0; $i < $count; $i++) {
            if (preg_match($pattern, $lines[$i]) === 1) {
                $start = $i;

                continue;
            }

            if ($start !== null && preg_match('/^\s*FROM\s+/i', $lines[$i]) === 1) {
                return [
                    'start' => $start,
                    'end' => $i - 1,
                ];
            }
        }

        if ($start !== null) {
            return [
                'start' => $start,
                'end' => $count - 1,
            ];
        }

        return null;
    }

    /**
     * Finds v1 boilerplate lines across all stages.
     *
     * @param list<string> $lines
     *
     * @return list<int>
     */
    public function findV1Boilerplate(array $lines): array
    {
        $found = [];

        // COPY/ARG boilerplate — search all lines
        foreach ($lines as $lineNumber => $line) {
            foreach (self::V1_BOILERPLATE_LINE_PATTERNS as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $lineNumber;

                    break;
                }
            }
        }

        // RUN instructions referencing configure-image.sh
        foreach ($this->findRunInstructionRanges($lines) as $range) {
            $combined = '';

            for ($i = $range['start']; $i <= $range['end']; $i++) {
                $combined .= $lines[$i]."\n";
            }

            if (preg_match(self::CONFIGURE_IMAGE_PATTERN, $combined) === 1) {
                for ($i = $range['start']; $i <= $range['end']; $i++) {
                    if (! in_array($i, $found, true)) {
                        $found[] = $i;
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Removes v1 boilerplate lines from the dev stage only. For RUN instructions
     * containing configure-image.sh that are chained with other commands, only the
     * boilerplate call is stripped and the remaining commands are preserved.
     *
     * @param list<string> $lines
     * @param list<int> $lineNumbers
     *
     * @return list<string>
     */
    public function removeLines(array $lines, array $lineNumbers): array
    {
        $runRanges = $this->findRunInstructionRanges($lines);
        $toRemove = array_flip($lineNumbers);

        foreach ($runRanges as $range) {
            if (! isset($toRemove[$range['start']])) {
                continue;
            }

            $combined = '';

            for ($i = $range['start']; $i <= $range['end']; $i++) {
                $combined .= $lines[$i]."\n";
            }

            if (preg_match(self::CONFIGURE_IMAGE_PATTERN, $combined) !== 1) {
                continue;
            }

            // Flatten the multi-line RUN into individual commands
            $flat = (string) preg_replace('/\\\\\s*\n\s*/', '', $combined);
            $flat = (string) preg_replace('/^\s*RUN\s+/', '', trim($flat));

            // Split by && and filter out the configure-image.sh call
            $commands = preg_split('/\s*&&\s*/', $flat) ?: [];
            $commands = array_values(array_filter(
                $commands,
                static fn (string $cmd): bool => preg_match(self::CONFIGURE_IMAGE_PATTERN, $cmd) !== 1,
            ));

            // Mark all lines in this range for removal
            for ($i = $range['start']; $i <= $range['end']; $i++) {
                $toRemove[$i] = $i;
            }

            // If there are remaining commands, insert them as a new RUN instruction
            if ($commands !== []) {
                $replacement = 'RUN '.implode(" && \\\n    ", $commands);
                $lines[$range['start']] = $replacement;
                unset($toRemove[$range['start']]);
            }
        }

        foreach (array_reverse(array_keys($toRemove)) as $lineNumber) {
            if (isset($lines[$lineNumber])) {
                unset($lines[$lineNumber]);
            }
        }

        return array_values($lines);
    }

    /**
     * @param list<string> $lines
     */
    public function write(string $path, array $lines): void
    {
        $this->filesystem->dumpFile($path, implode("\n", $lines));
    }

    /**
     * @param list<string> $lines
     */
    public function hasDevStage(array $lines): bool
    {
        return $this->findDevStageRange($lines) !== null;
    }

    /**
     * Finds the line range of the dev stage (FROM ... AS dev until the next FROM or EOF).
     *
     * @param list<string> $lines
     *
     * @return array{start: int, end: int}|null
     */
    public function findDevStageRange(array $lines): ?array
    {
        $start = null;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^\s*FROM\s+.+\s+AS\s+\S*dev\s*$/i', $lines[$i]) === 1) {
                $start = $i;

                continue;
            }

            if ($start !== null && preg_match('/^\s*FROM\s+/i', $lines[$i]) === 1) {
                return [
                    'start' => $start,
                    'end' => $i - 1,
                ];
            }
        }

        if ($start !== null) {
            return [
                'start' => $start,
                'end' => $count - 1,
            ];
        }

        return null;
    }

    /**
     * Finds all RUN instruction ranges, including multi-line continuations.
     *
     * @param list<string> $lines
     *
     * @return list<array{start: int, end: int}>
     */
    private function findRunInstructionRanges(array $lines): array
    {
        $ranges = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^\s*RUN\s/', $lines[$i]) !== 1) {
                continue;
            }

            $start = $i;

            while ($i < $count && str_ends_with(rtrim($lines[$i]), '\\')) {
                $i++;
            }

            $ranges[] = [
                'start' => $start,
                'end' => $i,
            ];
        }

        return $ranges;
    }
}
