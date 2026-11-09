<?php

declare(strict_types=1);

/*
 * A file written for a version where roles had no label: PHP refuses the
 * missing required argument.
 */

use App\Config\Definition\RoleDefinition;
use App\Tests\Config\Fixture\DefinitionFactory;

return DefinitionFactory::definition(roles: [
    new RoleDefinition(id: 'project_manager'),
]);
