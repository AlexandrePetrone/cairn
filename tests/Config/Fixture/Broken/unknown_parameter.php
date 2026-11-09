<?php

declare(strict_types=1);

/*
 * A file written for a version where roles had a `colour`: PHP refuses the
 * named argument it does not know.
 */

use App\Config\Definition\RoleDefinition;
use App\Tests\Config\Fixture\DefinitionFactory;

return DefinitionFactory::definition(roles: [
    new RoleDefinition(id: 'project_manager', label: 'Project manager', colour: 'amber'),
]);
