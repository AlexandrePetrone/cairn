<?php

declare(strict_types=1);

namespace db;

use Castor\Attribute\AsTask;

#[AsTask(description: 'Creates the dev and test databases when missing')]
function create(): void
{
    foreach (['dev', 'test'] as $env) {
        console(['doctrine:database:create', '--if-not-exists', '--env='.$env]);
    }
}

#[AsTask(description: 'Applies the entity mapping to the dev and test schemas (schema:update, no migrations before the first release)')]
function reset(): void
{
    foreach (['dev', 'test'] as $env) {
        console(['doctrine:schema:update', '--force', '--complete', '--env='.$env]);
    }
}

#[AsTask(description: 'Resets the dev database and loads the development data (main and volume stories)')]
function fixtures(): void
{
    // Foundry drops the database: PostgreSQL refuses while the worker holds a connection.
    compose(['stop', 'messenger']);
    try {
        console(['foundry:load-fixtures', 'main', 'volume', '--no-interaction']);
    } finally {
        compose(['up', '-d', 'messenger']);
    }
}

#[AsTask(description: 'Opens a psql session on the dev database')]
function psql(): void
{
    in_container(['psql', '-U', 'app', 'app'], service: 'database');
}
