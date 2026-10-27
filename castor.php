<?php

declare(strict_types=1);

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

/*
 * Project tasks, run with the Castor CLI (https://castor.jolicode.com):
 *
 *   castor            list the tasks
 *   castor qa         everything below, in check mode
 *   castor cs         fix the code style (--check to only report)
 *   castor phpstan    static analysis
 *   castor test       PHPUnit, extra arguments are passed through
 *   castor storia     Storia catalogue with live reload
 *   castor fixtures   reset the dev database with the development data
 */

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\run;

#[AsTask(description: 'Fix the code style with php-cs-fixer')]
function cs(
    #[AsOption(description: 'Report violations without fixing them')]
    bool $check = false,
): int {
    return tool([
        'vendor/bin/php-cs-fixer',
        'fix',
        ...($check ? ['--dry-run', '--diff'] : []),
    ]);
}

#[AsTask(description: 'Run the static analysis with PHPStan')]
function phpstan(): int
{
    // phpstan-symfony reads the compiled dev container.
    run(['bin/console', 'cache:warmup', '--env=dev'], context: context()->withQuiet());

    return tool(['vendor/bin/phpstan', 'analyse', '--memory-limit=512M']);
}

#[AsTask(description: 'Open the component catalogue with live reload (blocks the terminal)')]
function storia(): int
{
    return tool(['bin/console', 'storia:watch']);
}

#[AsTask(description: 'Reset the dev database and load the development data')]
function fixtures(): int
{
    return tool(['bin/console', 'foundry:load-fixtures', 'main', '--no-interaction']);
}

#[AsTask(description: 'Run the test suite with PHPUnit')]
function test(
    #[AsRawTokens]
    array $arguments = [],
): int {
    return tool(['php', 'bin/phpunit', ...$arguments]);
}

#[AsTask(description: 'Code style check, static analysis and tests')]
function qa(): int
{
    return cs() ?: phpstan() ?: test();
}

/**
 * @param list<string> $command
 */
function tool(array $command): int
{
    $process = run($command, context: context()->withAllowFailure());

    return $process instanceof Process ? $process->getExitCode() ?? 1 : 1;
}
