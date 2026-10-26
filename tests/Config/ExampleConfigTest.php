<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigLoader;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\Snapshot\SnapshotCodec;
use PHPUnit\Framework\TestCase;

/**
 * The committed example file must always load, validate and survive a
 * snapshot round trip: it is both the documentation and the dev default.
 * Nothing here depends on its content, which evolves with the product.
 */
final class ExampleConfigTest extends TestCase
{
    public function testTheExampleFileIsValid(): void
    {
        $definition = (new ConfigLoader(\dirname(__DIR__, 2).'/config/instance.example.php'))->load();

        self::assertInstanceOf(InstanceDefinition::class, $definition);
        self::assertSame([], (new ConfigValidator())->validate($definition));

        $codec = new SnapshotCodec();
        self::assertEquals($definition, $codec->decode($codec->encode($definition)));
    }
}
