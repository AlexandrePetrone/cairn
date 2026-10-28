<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use App\Message\CommandBus;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shortcuts for kernel tests: a configuration in force (the test instance
 * file of CAIRN_CONFIG in .env.test, applied as the first snapshot) and the
 * two buses.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait KernelHelpers
{
    /**
     * The buses have no consumer in the container yet (controllers will),
     * so they are built from the `command.bus` and `query.bus` services.
     */
    protected static function commandBus(): CommandBus
    {
        return new CommandBus(self::getContainer()->get('command.bus'));
    }

    protected static function queryBus(): QueryBus
    {
        return new QueryBus(self::getContainer()->get('query.bus'));
    }

    protected static function applyConfiguration(): void
    {
        $container = self::getContainer();
        $synchronizer = $container->get(ConfigSynchronizer::class);
        $synchronizer->apply($synchronizer->plan($container->get(ConfigLoader::class)->load()));
    }
}
