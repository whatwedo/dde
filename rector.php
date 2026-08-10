<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Symfony\Symfony42\Rector\New_\StringToArrayArgumentProcessRector;
use Rector\Symfony\Symfony61\Rector\Class_\CommandConfigureToAttributeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests'
    ])
    ->withSkip([
        // PHPUnit stubs of Process match the rule's object type, so ->method('isSuccessful') becomes ->method(['isSuccessful'])
        StringToArrayArgumentProcessRector::class => [
            __DIR__ . '/tests',
        ],
        // the description comes from the plugin definition at runtime and cannot be an attribute argument
        CommandConfigureToAttributeRector::class => [
            __DIR__ . '/src/Plugin/PluginProxyCommand.php',
        ],
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
