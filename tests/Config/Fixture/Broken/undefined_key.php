<?php

declare(strict_types=1);

/*
 * A file reading a value that an older version provided: PHP raises a
 * warning, which Symfony's error handler turns into an exception.
 */

use App\Config\Definition\RoleDefinition;
use App\Tests\Config\Fixture\DefinitionFactory;

$legacy = ['id' => 'project_manager'];

return DefinitionFactory::definition(roles: [new RoleDefinition(id: $legacy['id'], label: $legacy['label'])]);
