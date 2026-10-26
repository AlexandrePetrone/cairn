<?php

declare(strict_types=1);

/*
 * Instance configuration file used by the test environment (CAIRN_CONFIG in
 * .env.test). Deliberately small and independent from the committed example,
 * which evolves with the product.
 */

use App\Tests\Config\Fixture\DefinitionFactory;

return DefinitionFactory::definition();
