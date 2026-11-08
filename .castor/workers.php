<?php

declare(strict_types=1);

/*
 * The messenger service is a long-running consumer: it loads the code once.
 * FrankenPHP (classic mode), Vite and the Storia watcher need nothing after a code change.
 */

namespace workers;

use Castor\Attribute\AsTask;

use function Castor\io;

#[AsTask(description: 'Asks the messenger consumer to reload the code (it finishes its current message first)')]
function restart(): void
{
    // Writes the restart signal in the shared cache.app pool; compose starts the consumer again once it exits.
    console(['messenger:stop-workers']);
}

#[AsTask(description: 'Watches src/, config/, templates/, translations/ and the .env files and reloads the consumer on every change')]
function watch(): void
{
    $paths = ['src/...', 'config/...', 'templates/...', 'translations/...', '.env', '.env.local', 'composer.lock'];
    $lastRestart = 0.0;

    io()->writeln('Watching '.implode(', ', $paths).' (Ctrl+C to stop)');

    \Castor\watch($paths, static function (string $file, string $action) use (&$lastRestart): void {
        // One restart per burst of saves.
        if (microtime(true) - $lastRestart < 2.0) {
            return;
        }
        $lastRestart = microtime(true);

        io()->writeln(\sprintf('<comment>%s %s</comment>, reloading the consumer', $action, str_replace(\dirname(__DIR__).'/', '', $file)));
        restart();
    });
}
