<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/public', __DIR__.'/bin', __DIR__.'/.castor'])
    ->append([__FILE__, __DIR__.'/castor.php'])
    ->notPath([
        'bundles.php',
        'reference.php',
    ])
;

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        // Not strict: the Castor task files call the global helpers of castor.php as \console() from inside their namespace.
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => false],
    ])
    ->setFinder($finder)
;
