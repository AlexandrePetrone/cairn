<?php

declare(strict_types=1);

namespace App\Config\Diff;

use App\Config\Definition\InstanceDefinition;
use App\Config\Snapshot\SnapshotCodec;

/**
 * Compares two definitions through their canonical (encoded) form, walking
 * it recursively: lists of identified items are matched by id, maps key by
 * key, anything else as a value. Paths read like `workflows.bug.places.done`.
 */
final class DiffCalculator
{
    public function __construct(
        private readonly SnapshotCodec $codec,
    ) {
    }

    public function between(?InstanceDefinition $current, InstanceDefinition $next): ConfigDiff
    {
        $before = null === $current ? [] : $this->codec->encode($current);
        $after = $this->codec->encode($next);
        unset($before['format'], $after['format']);

        $entries = [];
        self::compare('', $before, $after, $entries);

        return new ConfigDiff($entries);
    }

    /**
     * @param list<DiffEntry> $entries
     */
    private static function compare(string $path, mixed $before, mixed $after, array &$entries): void
    {
        if ($before === $after) {
            return;
        }

        if (self::isItemList($before) && self::isItemList($after)) {
            $before = array_column($before, null, self::idKey($before));
            $after = array_column($after, null, self::idKey($after));
        } elseif (!self::isMap($before) || !self::isMap($after)) {
            $entries[] = new DiffEntry(DiffKind::Changed, $path, \sprintf('%s → %s', self::scalar($before), self::scalar($after)));

            return;
        }

        foreach ($after as $key => $value) {
            $keyPath = '' === $path ? (string) $key : "$path.$key";
            if (!\array_key_exists($key, $before)) {
                self::report(DiffKind::Added, $keyPath, $value, $entries);
            } else {
                self::compare($keyPath, $before[$key], $value, $entries);
            }
        }
        foreach ($before as $key => $value) {
            if (!\array_key_exists($key, $after)) {
                self::report(DiffKind::Removed, '' === $path ? (string) $key : "$path.$key", $value, $entries);
            }
        }
    }

    /**
     * An added or removed container is reported item by item, an item or a
     * value in one line.
     *
     * @param list<DiffEntry> $entries
     */
    private static function report(DiffKind $kind, string $path, mixed $value, array &$entries): void
    {
        if ([] === $value) {
            return;
        }
        if (self::isItemList($value)) {
            foreach ($value as $item) {
                $entries[] = new DiffEntry($kind, $path.'.'.$item[self::idKey($value)], self::summarize($item));
            }
        } elseif (self::isMap($value) && !isset($value['id'])) {
            foreach ($value as $key => $nested) {
                self::report($kind, "$path.$key", $nested, $entries);
            }
        } else {
            $entries[] = new DiffEntry($kind, $path, self::summarize($value));
        }
    }

    private static function isItemList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!\is_array($item) || (!isset($item['id']) && !isset($item['field']))) {
                return false;
            }
        }

        return true;
    }

    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function idKey(array $items): string
    {
        return isset($items[0]['id']) || [] === $items ? 'id' : 'field';
    }

    private static function summarize(mixed $value): string
    {
        if (\is_array($value) && isset($value['label'])) {
            return \sprintf('«%s»', $value['label']);
        }
        if (self::isMap($value)) {
            $parts = [];
            foreach ($value as $key => $nested) {
                if (!\in_array($key, ['id', 'field'], true) && !\is_array($nested)) {
                    $parts[] = $key.'='.self::scalar($nested);
                }
            }

            return implode(', ', $parts);
        }

        return self::scalar($value);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            null === $value => 'none',
            \is_bool($value) => $value ? 'yes' : 'no',
            \is_array($value) => '['.implode(', ', array_map(self::scalar(...), $value)).']',
            default => (string) $value,
        };
    }
}
