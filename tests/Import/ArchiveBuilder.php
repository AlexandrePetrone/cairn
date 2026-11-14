<?php

declare(strict_types=1);

namespace App\Tests\Import;

/**
 * Builds import archives for the tests from the archive of `Fixture/`,
 * which started as a copy of the example the format page shows
 * (`templates/admin/import/example/`) and lives its own life: Alice (101, manager), Bob (102, reporter)
 * and Carol (103) of a Redmine, and three issues: #1001, an open bug of
 * Bob assigned to Alice, with a screenshot shown in its description, two
 * comments of Alice (the second private, with a file), watched by Carol
 * and Alice; #1002, a shipped feature of Carol that blocks #1001; #1003,
 * a private support request of Bob assigned to Carol, related to #1001.
 */
final class ArchiveBuilder
{
    /** A 1×1 PNG. */
    public const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    /**
     * Writes the archive of `Fixture/` as a zip, after the given change.
     *
     * @param (callable(array<mixed>, array<mixed>, array<mixed>): array{array<mixed>, array<mixed>, array<mixed>})|null $change receives and returns the manifest, the issues and the files (path => content)
     */
    public static function example(string $directory, string $name = 'example.zip', ?callable $change = null): string
    {
        $source = __DIR__.'/Fixture/';
        $manifest = json_decode((string) file_get_contents($source.'manifest.json'), true, 64, \JSON_THROW_ON_ERROR);
        $issues = array_map(static fn (string $line): array => json_decode($line, true, 64, \JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($source.'issues.ndjson')))));
        $files = ['files/501' => self::PNG, 'files/502' => "trace\n"];
        if (null !== $change) {
            [$manifest, $issues, $files] = $change($manifest, $issues, $files);
        }

        return self::write($directory, $name, [
            'manifest.json' => json_encode($manifest, \JSON_THROW_ON_ERROR),
            'issues.ndjson' => implode("\n", array_map(static fn (array $issue): string => json_encode($issue, \JSON_THROW_ON_ERROR), $issues))."\n",
        ] + $files);
    }

    /**
     * A change that gives everything of the example a numeric id, as a
     * Redmine has: PHP keeps such ids as integer keys.
     *
     * @return callable(array<mixed>, array<mixed>, array<mixed>): array{array<mixed>, array<mixed>, array<mixed>}
     */
    public static function numericIds(): callable
    {
        return static function (array $manifest, array $issues, array $files): array {
            $ids = [
                'roles' => ['manager' => '3', 'reporter' => '5'],
                'workflows' => ['bugs' => '1', 'features' => '2'],
                'statuses' => ['new' => '1', 'in_progress' => '2', 'closed' => '5', 'shipped' => '6'],
                'trackers' => ['bug' => '1', 'feature' => '2', 'support' => '3'],
                'priorities' => ['low' => '1', 'normal' => '2', 'high' => '3', 'urgent' => '4'],
                'fields' => ['environment' => '10', 'browser' => '11', 'due_date' => '12', 'module' => '13'],
                'linkTypes' => ['relates' => '1', 'blocks' => '2'],
                'versions' => ['v1' => '20', 'v2' => '21'],
            ];
            foreach (['roles', 'trackers', 'priorities', 'fields', 'linkTypes', 'versions', 'workflows'] as $list) {
                foreach ($manifest[$list] as &$item) {
                    $item['id'] = $ids[$list][$item['id']];
                    if (isset($item['workflow'])) {
                        $item['workflow'] = $ids['workflows'][$item['workflow']];
                    }
                    foreach ($item['statuses'] ?? [] as $index => $status) {
                        $item['statuses'][$index]['id'] = $ids['statuses'][$status['id']];
                    }
                }
                unset($item);
            }
            foreach ($manifest['people'] as &$person) {
                $person['role'] = null === $person['role'] ? null : $ids['roles'][$person['role']];
            }
            unset($person);
            foreach ($issues as &$issue) {
                $issue['tracker'] = $ids['trackers'][$issue['tracker']];
                $issue['status'] = $ids['statuses'][$issue['status']];
                $issue['priority'] = $ids['priorities'][$issue['priority']];
                $issue['version'] = null === $issue['version'] ? null : $ids['versions'][$issue['version']];
                $issue['fields'] = array_combine(array_map(static fn (int|string $field): string => $ids['fields'][$field] ?? (string) $field, array_keys($issue['fields'])), $issue['fields']);
                foreach ($issue['links'] as $index => $link) {
                    $issue['links'][$index]['type'] = $ids['linkTypes'][$link['type']];
                }
            }
            unset($issue);

            return [$manifest, $issues, $files];
        };
    }

    /**
     * @param array<string, string> $entries path in the zip => content
     */
    public static function write(string $directory, string $name, array $entries): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        $path = $directory.'/'.$name;
        if (is_file($path)) {
            unlink($path);
        }
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return $path;
    }
}
