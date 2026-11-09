<?php

declare(strict_types=1);

/*
 * A file the definition objects refuse: two roles with the same id.
 */

use App\Config\Definition\RoleDefinition;
use App\Tests\Config\Fixture\DefinitionFactory;

return DefinitionFactory::definition(roles: [
    new RoleDefinition(id: 'client', label: 'Client'),
    new RoleDefinition(id: 'client', label: 'Client again'),
]);
