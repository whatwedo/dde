<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests'
    ])
    ->withComposerBased(
        phpunit: true,
        symfony: true,
    )
    ->withSets([
        SetList::PHP_85,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ])
    ->withPreparedSets(
        $deadCode = true,
        $codeQuality = true,
        $codingStyle = true,
        $typeDeclarations = true,
        $typeDeclarationDocblocks = true,
        $privatization = true,
        //$naming = true,
        //$instanceOf = true,
        //$earlyReturn = true
    );
