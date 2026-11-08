<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigException;
use App\Config\SnapshotCodec;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\TestCase;

final class SnapshotCodecTest extends TestCase
{
    private SnapshotCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new SnapshotCodec();
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $definition = DefinitionFactory::definition();
        $encoded = $this->codec->encode($definition);

        // Through JSON, as the database stores it.
        $stored = json_decode(json_encode($encoded, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $decoded = $this->codec->decode($stored);

        self::assertEquals($definition, $decoded);
        self::assertSame($encoded, $this->codec->encode($decoded));
        self::assertTrue($decoded->role('project_manager')->followsNewIssues);
        self::assertFalse($decoded->role('developer')->followsNewIssues);
    }

    public function testHashIgnoresTheOrderOfPermissionKeys(): void
    {
        $a = DefinitionFactory::definition(permissions: ['issue.create' => ['client'], 'tracker.manage' => ['project_manager']]);
        $b = DefinitionFactory::definition(permissions: ['tracker.manage' => ['project_manager'], 'issue.create' => ['client']]);

        self::assertSame($this->codec->hash($this->codec->encode($a)), $this->codec->hash($this->codec->encode($b)));
    }

    public function testHashChangesWithAnyAttribute(): void
    {
        $hash = static fn (SnapshotCodec $codec, $definition) => $codec->hash($codec->encode($definition));

        self::assertNotSame(
            $hash($this->codec, DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Anomalie')])),
            $hash($this->codec, DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Bug')])),
        );
    }

    public function testEncodedFormIsVersioned(): void
    {
        self::assertSame(SnapshotCodec::FORMAT, $this->codec->encode(DefinitionFactory::definition())['format']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Unsupported snapshot format');
        $this->codec->decode(['format' => 99]);
    }

    public function testCorruptedSnapshotIsReported(): void
    {
        $encoded = $this->codec->encode(DefinitionFactory::definition());
        $encoded['workflows'][0]['places'][0]['status'] = 'nope';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Corrupted snapshot');
        $this->codec->decode($encoded);
    }
}
