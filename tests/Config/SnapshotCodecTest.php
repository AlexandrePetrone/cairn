<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigException;
use App\Config\SnapshotCodec;
use App\Tests\Config\Fixture\DefinitionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $hash($this->codec, DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Defect')])),
            $hash($this->codec, DefinitionFactory::definition(workflows: [DefinitionFactory::bugWorkflow(label: 'Bug')])),
        );
    }

    public function testTheFormatIsRecordedButAnOlderOneStillDecodes(): void
    {
        $encoded = $this->codec->encode(DefinitionFactory::definition());
        self::assertSame(SnapshotCodec::FORMAT, $encoded['format']);

        $encoded['format'] = 1;
        self::assertEquals(DefinitionFactory::definition(), $this->codec->decode($encoded));
    }

    public function testAKeyLeftOutTakesTheDefaultOfTheConstructor(): void
    {
        // A snapshot written before `followsNewIssues` and `description` existed.
        $encoded = $this->codec->encode(DefinitionFactory::definition());
        unset($encoded['roles'][0]['followsNewIssues'], $encoded['workflows'][0]['transitions'][2]['description']);

        $decoded = $this->codec->decode($encoded);

        self::assertFalse($decoded->role('project_manager')->followsNewIssues);
        self::assertNull($decoded->workflow('bug')->transition('accept')->description);
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function unreadableSnapshots(): iterable
    {
        yield 'a required key missing' => [static function (array $encoded): array {
            unset($encoded['workflows'][0]['id']);

            return $encoded;
        }, 'Argument #1 ($id) not passed'];
        yield 'a key the constructor no longer has' => [static function (array $encoded): array {
            $encoded['roles'][0]['colour'] = 'amber';

            return $encoded;
        }, 'Unknown named parameter $colour'];
        yield 'a value an enum refuses' => [static function (array $encoded): array {
            $encoded['workflows'][0]['places'][0]['status'] = 'nope';

            return $encoded;
        }, '"nope" is not a valid backing value'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $alter
     */
    #[DataProvider('unreadableSnapshots')]
    public function testASnapshotThisVersionCannotReadIsReported(callable $alter, string $cause): void
    {
        $encoded = $alter($this->codec->encode(DefinitionFactory::definition()));

        try {
            $this->codec->decode($encoded);
            self::fail('The snapshot must be refused');
        } catch (ConfigException $e) {
            self::assertStringStartsWith(\sprintf('Unreadable snapshot (format %d): ', SnapshotCodec::FORMAT), $e->getMessage());
            self::assertStringContainsString($cause, $e->getMessage());
        }
    }
}
