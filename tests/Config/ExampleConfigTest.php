<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ConfigLoader;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Config\SnapshotCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The committed example files must always load, validate and survive a
 * snapshot round trip: they are both the documentation and the dev default.
 * The French file is the English one translated: same identifiers, so that
 * an instance can switch from one to the other without a migration.
 */
final class ExampleConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function exampleFiles(): iterable
    {
        yield 'english' => ['instance.example.php'];
        yield 'french' => ['instance.example.fr.php'];
    }

    #[DataProvider('exampleFiles')]
    public function testTheExampleFileIsValid(string $file): void
    {
        $definition = self::load($file);

        self::assertSame([], (new ConfigValidator())->validate($definition));

        $codec = new SnapshotCodec();
        self::assertEquals($definition, $codec->decode($codec->encode($definition)));
    }

    public function testTheTranslatedExampleDescribesTheSameIdentifiers(): void
    {
        self::assertSame(
            self::identifiers(self::load('instance.example.php')),
            self::identifiers(self::load('instance.example.fr.php')),
        );
    }

    private static function load(string $file): InstanceDefinition
    {
        $definition = (new ConfigLoader(\dirname(__DIR__, 2).'/config/'.$file))->load();
        self::assertInstanceOf(InstanceDefinition::class, $definition);

        return $definition;
    }

    /**
     * @return array<string, mixed>
     */
    private static function identifiers(InstanceDefinition $definition): array
    {
        $workflows = [];
        foreach ($definition->workflows as $workflow) {
            $workflows[$workflow->id] = [
                'initial' => $workflow->initial,
                'places' => array_map(static fn ($place) => [$place->id, $place->status], array_values($workflow->places)),
                'transitions' => array_map(
                    static fn ($transition) => [$transition->id, $transition->from, $transition->to, $transition->roles, $transition->requiresComment],
                    array_values($workflow->transitions),
                ),
                'fields' => array_keys($workflow->fields),
            ];
        }

        return [
            'roles' => array_map(static fn ($role) => [$role->id, $role->internalOnly, $role->followsNewIssues], array_values($definition->roles)),
            'migrationPolicy' => $definition->migrationPolicy,
            'permissions' => $definition->permissions,
            'fields' => array_map(static fn ($field) => [$field->id, $field->type, array_keys($field->options), $field->internalOnly], array_values($definition->fields)),
            'links' => array_map(static fn ($link) => $link->id, array_values($definition->links)),
            'workflows' => $workflows,
        ];
    }
}
