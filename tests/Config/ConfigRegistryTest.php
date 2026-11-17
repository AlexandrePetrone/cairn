<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigRegistry;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConfigRegistryTest extends KernelTestCase
{
    public function testAWorkerReadsTheConfigurationAgainAtEachMessage(): void
    {
        self::bootKernel();
        ShopScene::load(self::getContainer());
        $registry = self::getContainer()->get(ConfigRegistry::class);
        $definition = $registry->definition();
        self::assertSame($definition, $registry->definition(), 'read once');

        // What a worker does between two messages, and the kernel between two requests.
        self::getContainer()->get('services_resetter')->reset();

        self::assertNotSame($definition, $registry->definition(), 'a change made meanwhile is seen: a worker never acts on the places of an older configuration');
    }
}
