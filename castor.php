<?php

declare(strict_types=1);

/*
 * Developer entry point of the Docker stack (https://castor.jolicode.com).
 *
 *   install            first run: image, dependencies, databases, fixtures
 *   docker:*           the stack itself (start, stop, logs, ...)   .castor/docker.php
 *   app:*              commands inside the cli container           .castor/app.php
 *   db:*               dev and test databases                      .castor/db.php
 *   qa:*               tests and linters                           .castor/qa.php
 *   assets:*           production build of the assets              .castor/assets.php
 *   workers:*          reloading the Messenger consumer            .castor/workers.php
 *
 * The most frequent tasks have a short alias: start, stop, logs, console, composer, sh, test, qa.
 */

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\context;
use function Castor\import;
use function Castor\io;
use function Castor\run;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

import(__DIR__.'/.castor');

#[AsContext(default: true)]
function default_context(): Context
{
    return new Context(workingDirectory: __DIR__);
}

#[AsTask(description: 'First run: builds the image, installs everything and loads the fixtures')]
function install(): void
{
    io()->title('Building the image');
    docker\build();

    io()->title('Starting the database and the web server');
    compose(['up', '-d', '--wait', '--remove-orphans', 'database', 'php', 'cli']);

    io()->title('Installing the dependencies');
    composer(['install', '--no-interaction', '--prefer-dist']);
    // After Composer: the UX packages are npm dependencies on their vendor/ copy.
    npm(['ci']);

    io()->title('Preparing the databases (dev and test)');
    db\create();
    db\fixtures();

    io()->title('Starting the whole stack');
    docker\start();
}

// --- Helpers shared by the task files ---------------------------------------------------------

function app_url(): string
{
    return 'http://localhost:'.(getenv('HTTP_PORT') ?: '8000');
}

function mailpit_url(): string
{
    return 'http://localhost:'.(getenv('MAILPIT_PORT') ?: '8025');
}

/**
 * @param list<string> $args
 */
function compose(array $args): void
{
    run(['docker', 'compose', ...$args], context: context()->withTty(has_tty()));
}

/**
 * Runs a command in the cli container (not the web server's, which may be restarting); returns
 * the exit code when failures are allowed.
 *
 * @param list<string> $command
 */
function in_container(array $command, string $service = 'cli', bool $allowFailure = false): int
{
    $tty = has_tty();
    $process = run(
        ['docker', 'compose', 'exec', ...($tty ? [] : ['-T']), $service, ...$command],
        context: context()->withTty($tty)->withAllowFailure($allowFailure),
    );

    return $process->getExitCode() ?? 0;
}

/**
 * @param list<string> $args
 */
function console(array $args, bool $allowFailure = false): int
{
    return in_container(['bin/console', ...$args], allowFailure: $allowFailure);
}

/**
 * @param list<string> $args
 */
function composer(array $args): void
{
    in_container(['composer', ...$args]);
}

/**
 * @param list<string> $args
 */
function npm(array $args): void
{
    in_container(['npm', ...$args]);
}

/** Castor refuses TTY mode without a terminal (CI, editors, agents). */
function has_tty(): bool
{
    return stream_isatty(\STDOUT) && is_writable('/dev/tty');
}
