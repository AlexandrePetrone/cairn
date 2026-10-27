<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * For kernel tests that need a configuration in force: applies the test
 * instance file (CAIRN_CONFIG in .env.test) as the first snapshot.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait AppliesConfiguration
{
    protected static function applyConfiguration(): void
    {
        $container = self::getContainer();
        $synchronizer = $container->get(ConfigSynchronizer::class);
        $synchronizer->apply($synchronizer->plan($container->get(ConfigLoader::class)->load()));
    }
}
