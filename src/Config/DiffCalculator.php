<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\InstanceDefinition;

/**
 * Compares two definitions through their canonical (encoded) form, walking
 * it recursively: lists of identified items are matched by id, maps key by
 * key, anything else as a value. The result is one line per added (+),
 * removed (-) or changed (~) element, with paths such as
 * `workflows.bug.places.done`.
 */
final class DiffCalculator
{
    public function __construct(
        private readonly SnapshotCodec $codec,
    ) {
    }

    /**
     * @return list<string>
     */
    public function between(?InstanceDefinition $current, InstanceDefinition $next): array
    {
        $before = null === $current ? [] : $this->codec->encode($current);
        $after = $this->codec->encode($next);
        unset($before['format'], $after['format']);

        $lines = [];
        self::compare('', $before, $after, $lines);

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function compare(string $path, mixed $before, mixed $after, array &$lines): void
    {
        if ($before === $after) {
            return;
        }

        if (self::isItemList($before) && self::isItemList($after)) {
            $before = array_column($before, null, self::idKey($before));
            $after = array_column($after, null, self::idKey($after));
        } elseif (!self::isMap($before) || !self::isMap($after)) {
            $lines[] = \sprintf('~ %s: %s → %s', $path, self::scalar($before), self::scalar($after));

            return;
        }

        foreach ($after as $key => $value) {
            $keyPath = '' === $path ? (string) $key : "$path.$key";
            if (!\array_key_exists($key, $before)) {
                self::report('+', $keyPath, $value, $lines);
            } else {
                self::compare($keyPath, $before[$key], $value, $lines);
            }
        }
        foreach ($before as $key => $value) {
            if (!\array_key_exists($key, $after)) {
                self::report('-', '' === $path ? (string) $key : "$path.$key", $value, $lines);
            }
        }
    }

    /**
     * An added or removed container is reported item by item, an item or a
     * value in one line.
     *
     * @param list<string> $lines
     */
    private static function report(string $symbol, string $path, mixed $value, array &$lines): void
    {
        if ([] === $value) {
            return;
        }
        if (self::isItemList($value)) {
            foreach ($value as $item) {
                $lines[] = \sprintf('%s %s.%s: %s', $symbol, $path, $item[self::idKey($value)], self::summarize($item));
            }
        } elseif (self::isMap($value) && !isset($value['id'])) {
            foreach ($value as $key => $nested) {
                self::report($symbol, "$path.$key", $nested, $lines);
            }
        } else {
            $lines[] = \sprintf('%s %s: %s', $symbol, $path, self::summarize($value));
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
