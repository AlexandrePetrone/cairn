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

#[AsTask(description: 'Applies the migrations that the dev database has not run yet')]
function migrate(): void
{
    console(['doctrine:migrations:migrate', '--no-interaction']);
}

#[AsTask(description: 'Writes the migration of what the entities changed since the last one')]
function migration(): int
{
    // The difference is read against the dev database, which must be at the last migration.
    migrate();

    return console(['doctrine:migrations:diff', '--formatted', '--allow-empty-diff', '--no-interaction'], allowFailure: true);
}

#[AsTask(description: 'Resets the dev database and loads the development data (main and volume stories)')]
function fixtures(): void
{
    // Foundry drops the database, then creates it again from the migrations: PostgreSQL refuses
    // while the worker holds a connection.
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
