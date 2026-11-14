<?php

declare(strict_types=1);

namespace App\Import;

/**
 * One issue of an archive, read from its line of `issues.ndjson` and
 * checked against the manifest: every person, tracker, status, priority,
 * version and link type it names is declared there. Dates are ISO 8601
 * with their offset, brought to the timezone of the application. Texts
 * may hold the tokens `{{issue:ID}}`, `{{user:ID}}` and `{{file:ID}}`.
 */
final readonly class ArchiveIssue
{
    /**
     * @param array<array-key, string|list<string>>                                                                                                                                                              $fields   field id => value
     * @param list<string>                                                                                                                                                                                       $watchers person ids
     * @param list<array{id: string, path: string, name: string, author: string, createdAt: \DateTimeImmutable}>                                                                                                 $files
     * @param list<array{author: string, body: string, private: bool, createdAt: \DateTimeImmutable, files: list<array{id: string, path: string, name: string, author: string, createdAt: \DateTimeImmutable}>}> $comments oldest first
     * @param list<array{type: string, to: string}>                                                                                                                                                              $links
     */
    private function __construct(
        public string $id,
        public string $tracker,
        public string $status,
        public bool $closed,
        public ?string $priority,
        public string $title,
        public string $description,
        public string $author,
        public ?string $assignee,
        public bool $private,
        public ?string $version,
        public array $fields,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $closedAt,
        public array $watchers,
        public array $files,
        public array $comments,
        public array $links,
    ) {
    }

    /**
     * @param array<mixed> $data the decoded line, with an id: a refusal names the issue by it
     */
    public static function fromLine(array $data, ImportManifest $manifest): self
    {
        $ref = \is_scalar($data['id'] ?? null) ? (string) $data['id'] : '?';
        $fail = static fn (string $where): ImportArchiveException => ImportArchiveException::invalidIssue($ref, $where);
        $text = static function (array $item, string $key, string $where, bool $required = true) use ($fail): ?string {
            $value = $item[$key] ?? null;
            if (null === $value || '' === $value) {
                return $required ? throw $fail($where) : null;
            }

            return \is_string($value) || \is_int($value) ? str_replace("\0", '', (string) $value) : throw $fail($where);
        };
        // A flag is a JSON boolean: `1` or "true" read as false would make a private note public.
        $flag = static function (array $item, string $key, string $where) use ($fail): bool {
            $value = $item[$key] ?? false;

            return \is_bool($value) ? $value : throw $fail($where);
        };
        $date = static function (array $item, string $key, string $where, bool $required = true) use ($fail): ?\DateTimeImmutable {
            $value = $item[$key] ?? null;
            if (null === $value && !$required) {
                return null;
            }

            return (\is_string($value) ? ImportManifest::dateTime($value) : null) ?? throw $fail($where);
        };
        $person = static function (array $item, string $key, string $where, bool $required = true) use ($text, $manifest, $fail): ?string {
            $id = $text($item, $key, $where, $required);

            return null === $id || isset($manifest->people[$id]) ? $id : throw $fail($where);
        };
        $files = static function (array $item, string $where) use ($text, $date, $person, $fail): array {
            $files = [];
            foreach (array_values((array) ($item['files'] ?? [])) as $index => $file) {
                $at = \sprintf('%sfiles[%d]', $where, $index);
                if (!\is_array($file)) {
                    throw $fail($at);
                }
                $path = (string) $text($file, 'path', $at.'.path');
                if (1 !== preg_match('#^files/[A-Za-z0-9._-]+$#', $path)) {
                    throw $fail($at.'.path');
                }
                $files[] = [
                    'id' => (string) $text($file, 'id', $at.'.id'),
                    'path' => $path,
                    'name' => (string) $text($file, 'name', $at.'.name'),
                    'author' => (string) $person($file, 'author', $at.'.author'),
                    'createdAt' => $date($file, 'createdAt', $at.'.createdAt') ?? throw $fail($at.'.createdAt'),
                ];
            }

            return $files;
        };

        $tracker = (string) $text($data, 'tracker', 'tracker');
        $workflow = $manifest->workflows[$manifest->trackers[$tracker]['workflow'] ?? ''] ?? throw $fail('tracker');
        $status = (string) $text($data, 'status', 'status');
        if (!isset($workflow['statuses'][$status])) {
            throw $fail('status');
        }
        $priority = $text($data, 'priority', 'priority', false);
        if (null !== $priority && !isset($manifest->priorities[$priority])) {
            throw $fail('priority');
        }
        $version = $text($data, 'version', 'version', false);
        if (null !== $version && !isset($manifest->versions[$version])) {
            throw $fail('version');
        }

        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $fieldId => $value) {
            if (!isset($manifest->fields[$fieldId])) {
                throw $fail('fields.'.$fieldId);
            }
            if (\is_array($value)) {
                $value = array_values(array_map(strval(...), array_filter($value, static fn (mixed $item): bool => \is_scalar($item) && '' !== (string) $item)));
            } elseif (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (\is_scalar($value)) {
                $value = str_replace("\0", '', (string) $value);
            } else {
                $value = '';
            }
            if ('' !== $value && [] !== $value) {
                $fields[(string) $fieldId] = $value;
            }
        }

        $comments = [];
        foreach (array_values((array) ($data['comments'] ?? [])) as $index => $comment) {
            $at = \sprintf('comments[%d]', $index);
            if (!\is_array($comment)) {
                throw $fail($at);
            }
            $comments[] = [
                'author' => (string) $person($comment, 'author', $at.'.author'),
                'body' => (string) $text($comment, 'body', $at.'.body', false),
                'private' => $flag($comment, 'private', $at.'.private'),
                'createdAt' => $date($comment, 'createdAt', $at.'.createdAt') ?? throw $fail($at.'.createdAt'),
                'files' => $files($comment, $at.'.'),
            ];
        }
        usort($comments, static fn (array $a, array $b): int => $a['createdAt'] <=> $b['createdAt']);

        $links = [];
        foreach (array_values((array) ($data['links'] ?? [])) as $index => $link) {
            $at = \sprintf('links[%d]', $index);
            if (!\is_array($link) || !isset($manifest->linkTypes[(string) $text($link, 'type', $at.'.type')])) {
                throw $fail($at.'.type');
            }
            $links[] = ['type' => (string) $link['type'], 'to' => (string) $text($link, 'to', $at.'.to')];
        }

        $watchers = [];
        foreach (array_values((array) ($data['watchers'] ?? [])) as $index => $watcher) {
            $watchers[] = (string) $person(['id' => $watcher], 'id', \sprintf('watchers[%d]', $index));
        }

        $createdAt = $date($data, 'createdAt', 'createdAt') ?? throw $fail('createdAt');
        $title = trim((string) $text($data, 'title', 'title'));
        if ('' === $title) {
            throw $fail('title');
        }

        $id = (string) $text($data, 'id', 'id');
        if (1 !== preg_match(ImportManifest::ID_PATTERN, $id)) {
            throw $fail('id');
        }

        return new self(
            $id,
            $tracker,
            $status,
            $workflow['statuses'][$status]['closed'],
            $priority,
            $title,
            (string) $text($data, 'description', 'description', false),
            (string) $person($data, 'author', 'author'),
            $person($data, 'assignee', 'assignee', false),
            $flag($data, 'private', 'private'),
            $version,
            $fields,
            $createdAt,
            $date($data, 'updatedAt', 'updatedAt', false) ?? $createdAt,
            $date($data, 'closedAt', 'closedAt', false),
            array_values(array_unique($watchers)),
            $files($data, ''),
            $comments,
            $links,
        );
    }

    /**
     * Every file of the issue, its own and those of its comments.
     *
     * @return list<array{id: string, path: string, name: string, author: string, createdAt: \DateTimeImmutable}>
     */
    public function allFiles(): array
    {
        return array_merge($this->files, ...array_column($this->comments, 'files'));
    }
}
