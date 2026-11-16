<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\InvalidDefinitionException;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\RoleDefinition;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\PlaceStatus;

/**
 * Converts a definition to the array stored in the snapshot and back.
 *
 * The array mirrors the definition objects: every key is a public property,
 * which is also a constructor parameter, so decoding is a named-argument
 * spread. Enums are stored by value, collections of objects as lists. The
 * array is also the canonical form for hashing.
 *
 * The `format` key says which shape wrote the snapshot; it is recorded,
 * never checked. Decoding is what PHP makes of the stored keys: a key
 * left out takes the constructor's default, so a property added with one
 * reads an older snapshot as it is; a required key missing, a key the
 * constructor no longer knows or a value an enum refuses make the
 * snapshot unreadable by this version, and only a sync of a file written
 * for it puts a readable one in place.
 */
final class SnapshotCodec
{
    /** Bumped whenever the shape of the array changes, as a trace of which version wrote a snapshot. */
    public const int FORMAT = 11;

    /**
     * @return array<string, mixed>
     */
    public function encode(InstanceDefinition $definition): array
    {
        $data = self::encodeValue($definition);
        ksort($data['permissions']);

        return ['format' => self::FORMAT, ...$data];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function hash(array $data): string
    {
        return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigException when this version cannot read the snapshot
     */
    public function decode(array $data): InstanceDefinition
    {
        $format = json_encode($data['format'] ?? null);
        unset($data['format']);

        try {
            return new InstanceDefinition(...[
                ...$data,
                'migrationPolicy' => MigrationPolicy::from($data['migrationPolicy']),
                'roles' => array_map(static fn (array $role) => new RoleDefinition(...$role), $data['roles']),
                'fields' => array_map(static fn (array $field) => new FieldDefinition(...[
                    ...$field,
                    'type' => FieldType::from($field['type']),
                ]), $data['fields']),
                'links' => array_map(static fn (array $link) => new LinkTypeDefinition(...$link), $data['links']),
                'places' => array_map(static fn (array $place) => new PlaceDefinition(...[
                    ...$place,
                    'status' => PlaceStatus::from($place['status']),
                ]), $data['places']),
                'workflows' => array_map(self::decodeWorkflow(...), $data['workflows']),
            ]);
        } catch (InvalidDefinitionException|\Error|\ErrorException $e) {
            throw new ConfigException(\sprintf('Unreadable snapshot (format %s): %s', $format, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function decodeWorkflow(array $data): WorkflowDefinition
    {
        return new WorkflowDefinition(...[
            ...$data,
            'transitions' => array_map(static fn (array $transition) => new TransitionDefinition(...[
                ...$transition,
                'style' => TransitionStyle::from($transition['style']),
            ]), $data['transitions']),
            'fields' => array_map(static fn (array $usage) => new FieldUsage(...$usage), $data['fields']),
        ]);
    }

    /**
     * Objects become maps of their public properties, enums their value,
     * and arrays of objects lists (their keys are the objects' ids).
     */
    private static function encodeValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value)) {
            return array_map(self::encodeValue(...), get_object_vars($value));
        }
        if (\is_array($value)) {
            $encoded = array_map(self::encodeValue(...), $value);

            return [] !== $value && \is_object(reset($value)) ? array_values($encoded) : $encoded;
        }

        return $value;
    }
}
