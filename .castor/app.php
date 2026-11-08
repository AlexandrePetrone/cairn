<?php

declare(strict_types=1);

namespace app;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;

#[AsTask(description: 'Runs bin/console in the php container, e.g. "castor console debug:router"', aliases: ['console'])]
function console(#[AsRawTokens] array $args): int
{
    return \console(array_values($args), allowFailure: true);
}

#[AsTask(description: 'Runs composer in the php container, e.g. "castor composer require symfony/lock"', aliases: ['composer'])]
function composer(#[AsRawTokens] array $args): void
{
    \composer(array_values($args));
}

#[AsTask(description: 'Runs npm in the php container, e.g. "castor npm install sortablejs"', aliases: ['npm'])]
function npm(#[AsRawTokens] array $args): void
{
    \npm(array_values($args));
}

#[AsTask(description: 'Runs php in the php container')]
function php(#[AsRawTokens] array $args): void
{
    in_container(['php', ...array_values($args)]);
}

#[AsTask(description: 'Opens a shell in the php container', aliases: ['sh'])]
function sh(): void
{
    in_container(['bash']);
}

#[AsTask(name: 'config:sync', description: 'Applies the instance configuration file (CAIRN_CONFIG) to the dev database')]
function config_sync(
    #[AsOption(description: 'Show the changes without applying them', mode: InputOption::VALUE_NONE)]
    bool $dryRun,
): int {
    return \console(['app:config:sync', ...($dryRun ? ['--dry-run'] : [])], allowFailure: true);
}
