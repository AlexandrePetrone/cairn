<?php

declare(strict_types=1);

namespace app;

use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

#[AsTask(description: 'Runs bin/console in the cli container, e.g. "castor console debug:router"', aliases: ['console'])]
function console(#[AsRawTokens] array $args): int
{
    return \console(array_values($args), allowFailure: true);
}

#[AsTask(description: 'Runs composer in the cli container, e.g. "castor composer require symfony/lock"', aliases: ['composer'])]
function composer(#[AsRawTokens] array $args): void
{
    \composer(array_values($args));
}

#[AsTask(description: 'Runs npm in the cli container, e.g. "castor npm install sortablejs"', aliases: ['npm'])]
function npm(#[AsRawTokens] array $args): void
{
    \npm(array_values($args));
}

#[AsTask(description: 'Runs php in the cli container')]
function php(#[AsRawTokens] array $args): void
{
    in_container(['php', ...array_values($args)]);
}

#[AsTask(description: 'Opens a shell in the cli container', aliases: ['sh'])]
function sh(): void
{
    in_container(['bash']);
}

#[AsTask(name: 'config:install', description: 'Installs the starter configuration into a dev database that has none')]
function config_install(): int
{
    return \console(['app:config:install'], allowFailure: true);
}
