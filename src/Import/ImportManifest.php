<?php

declare(strict_types=1);

namespace App\Import;

/**
 * What an archive declares, read from its `manifest.json`: where it comes
 * from, and the people, roles, workflows with their statuses, trackers,
 * priorities, fields, link types and versions its issues refer to by id.
 * Ids are short codes of the source (`ID_PATTERN`), unique within their list. Reading a
 * manifest checks all of it: one thing missing or misplaced refuses the
 * archive, with the place. Kept on the import as an array (`toArray()`,
 * `fromArray()`), so that the configuration pages never open the zip.
 */
final readonly class ImportManifest
{
    public const int FORMAT = 1;

    public const array FIELD_TYPES = ['text', 'number', 'date', 'list', 'boolean', 'link'];

    /** What an id of the archive looks like: letters, digits, `_`, `-` and `:`, 60 at most. */
    public const string ID_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_:-]{0,59}$/';

    /**
     * @param array<array-key, array{email: string, firstName: string, lastName: string, company: string|null, role: string|null}>                               $people
     * @param array<array-key, string>                                                                                                                           $roles      id => label
     * @param array<array-key, array{label: string, statuses: array<array-key, array{label: string, closed: bool}>}>                                             $workflows
     * @param array<array-key, array{label: string, workflow: string}>                                                                                           $trackers
     * @param array<array-key, string>                                                                                                                           $priorities id => label, lowest first
     * @param array<array-key, array{label: string, type: string, options: list<string>}>                                                                        $fields
     * @param array<array-key, string>                                                                                                                           $linkTypes  id => label
     * @param array<array-key, array{label: string, description: string, dueOn: string|null, delivered: bool, createdAt: string|null, deliveredAt: string|null}> $versions
     */
    private function __construct(
        public string $source,
        public array $people,
        public array $roles,
        public array $workflows,
        public array $trackers,
        public array $priorities,
        public array $fields,
        public array $linkTypes,
        public array $versions,
    ) {
    }

    /**
     * Reads the content of `manifest.json`.
     *
     * @param array<mixed> $data
     */
    public static function fromFile(array $data): self
    {
        if (self::FORMAT !== ($data['format'] ?? null)) {
            throw ImportArchiveException::unsupportedFormat($data['format'] ?? null);
        }
        $source = \is_array($data['source'] ?? null) ? self::text($data['source'], 'name', 'source.name') : throw ImportArchiveException::invalidManifest('source');

        $roles = self::labels($data, 'roles');
        $people = [];
        foreach (self::items($data, 'people') as $where => $person) {
            $email = mb_strtolower(self::text($person, 'email', $where.'.email'));
            if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw ImportArchiveException::invalidManifest($where.'.email');
            }
            $role = self::optional($person, 'role', $where.'.role');
            if (null !== $role && !isset($roles[$role])) {
                throw ImportArchiveException::invalidManifest($where.'.role');
            }
            $people[self::id($person, $where, $people)] = [
                'email' => $email,
                'firstName' => self::text($person, 'firstName', $where.'.firstName'),
                'lastName' => self::text($person, 'lastName', $where.'.lastName'),
                'company' => self::optional($person, 'company', $where.'.company'),
                'role' => $role,
            ];
        }
        if (\count($people) !== \count(array_unique(array_column($people, 'email')))) {
            throw ImportArchiveException::sharedEmail((string) array_key_first(array_filter(array_count_values(array_column($people, 'email')), static fn (int $times): bool => $times > 1)));
        }

        $workflows = [];
        foreach (self::items($data, 'workflows') as $where => $workflow) {
            $statuses = [];
            foreach (self::items($workflow, 'statuses', $where.'.') as $statusWhere => $status) {
                $statuses[self::id($status, $statusWhere, $statuses)] = ['label' => self::text($status, 'label', $statusWhere.'.label'), 'closed' => self::flag($status, 'closed', $statusWhere.'.closed')];
            }
            if ([] === $statuses) {
                throw ImportArchiveException::invalidManifest($where.'.statuses');
            }
            $workflows[self::id($workflow, $where, $workflows)] = ['label' => self::text($workflow, 'label', $where.'.label'), 'statuses' => $statuses];
        }

        $trackers = [];
        foreach (self::items($data, 'trackers') as $where => $tracker) {
            $workflow = self::text($tracker, 'workflow', $where.'.workflow');
            if (!isset($workflows[$workflow])) {
                throw ImportArchiveException::invalidManifest($where.'.workflow');
            }
            $trackers[self::id($tracker, $where, $trackers)] = ['label' => self::text($tracker, 'label', $where.'.label'), 'workflow' => $workflow];
        }
        if ([] === $trackers) {
            throw ImportArchiveException::invalidManifest('trackers');
        }

        $fields = [];
        foreach (self::items($data, 'fields') as $where => $field) {
            $type = self::text($field, 'type', $where.'.type');
            if (!\in_array($type, self::FIELD_TYPES, true)) {
                throw ImportArchiveException::invalidManifest($where.'.type');
            }
            $options = [];
            foreach ((array) ($field['options'] ?? []) as $option) {
                if (!\is_scalar($option) || '' === trim((string) $option)) {
                    throw ImportArchiveException::invalidManifest($where.'.options');
                }
                $options[] = (string) $option;
            }
            $fields[self::id($field, $where, $fields)] = ['label' => self::text($field, 'label', $where.'.label'), 'type' => $type, 'options' => $options];
        }

        $versions = [];
        foreach (self::items($data, 'versions') as $where => $version) {
            // A date that cannot be read does not refuse the archive: the release gets today's, and the report says so.
            $versions[self::id($version, $where, $versions)] = [
                'label' => self::text($version, 'label', $where.'.label'),
                'description' => (string) self::optional($version, 'description', $where.'.description'),
                'dueOn' => self::optional($version, 'dueOn', $where.'.dueOn'),
                'delivered' => self::flag($version, 'delivered', $where.'.delivered'),
                'createdAt' => self::optional($version, 'createdAt', $where.'.createdAt'),
                'deliveredAt' => self::optional($version, 'deliveredAt', $where.'.deliveredAt'),
            ];
        }

        return new self($source, $people, $roles, $workflows, $trackers, self::labels($data, 'priorities'), $fields, self::labels($data, 'linkTypes'), $versions);
    }

    /**
     * A date and time of the archive (ISO 8601 with its offset, with or
     * without fractions of a second), in the time zone of the instance;
     * null when it is not one.
     */
    public static function dateTime(?string $value): ?\DateTimeImmutable
    {
        $date = null === $value ? false : (\DateTimeImmutable::createFromFormat(\DATE_ATOM, $value) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value));

        return false === $date ? null : $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param array<string, mixed> $data what `toArray()` gave
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['source'] ?? ''),
            (array) ($data['people'] ?? []),
            (array) ($data['roles'] ?? []),
            (array) ($data['workflows'] ?? []),
            (array) ($data['trackers'] ?? []),
            (array) ($data['priorities'] ?? []),
            (array) ($data['fields'] ?? []),
            (array) ($data['linkTypes'] ?? []),
            (array) ($data['versions'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** "Claire Martin", for a person of the archive. */
    public function nameOf(string $personId): string
    {
        $person = $this->people[$personId] ?? null;

        return null === $person ? $personId : trim($person['firstName'].' '.$person['lastName']);
    }

    /**
     * The objects of a list of the manifest, by their place (`people[3]`).
     *
     * @param array<mixed> $data
     *
     * @return array<array-key, array<mixed>>
     */
    private static function items(array $data, string $key, string $prefix = ''): array
    {
        $list = $data[$key] ?? [];
        if (!\is_array($list)) {
            throw ImportArchiveException::invalidManifest($prefix.$key);
        }
        $items = [];
        foreach (array_values($list) as $index => $item) {
            $where = \sprintf('%s%s[%d]', $prefix, $key, $index);
            $items[$where] = \is_array($item) ? $item : throw ImportArchiveException::invalidManifest($where);
        }

        return $items;
    }

    /**
     * A list of `{id, label}`.
     *
     * @param array<mixed> $data
     *
     * @return array<array-key, string>
     */
    private static function labels(array $data, string $key): array
    {
        $labels = [];
        foreach (self::items($data, $key) as $where => $item) {
            $labels[self::id($item, $where, $labels)] = self::text($item, 'label', $where.'.label');
        }

        return $labels;
    }

    /**
     * @param array<mixed>         $item
     * @param array<string, mixed> $known the ids already read in the list
     */
    private static function id(array $item, string $where, array $known): string
    {
        $id = self::text($item, 'id', $where.'.id');
        if (isset($known[$id]) || 1 !== preg_match(self::ID_PATTERN, $id)) {
            throw ImportArchiveException::invalidManifest($where.'.id');
        }

        return $id;
    }

    /**
     * A flag is a JSON boolean, false when absent: `1` or "true" read as
     * false would make a closed status an open one.
     *
     * @param array<mixed> $item
     */
    private static function flag(array $item, string $key, string $where): bool
    {
        $value = $item[$key] ?? false;

        return \is_bool($value) ? $value : throw ImportArchiveException::invalidManifest($where);
    }

    /**
     * @param array<mixed> $item
     */
    private static function text(array $item, string $key, string $where): string
    {
        return self::optional($item, $key, $where) ?? throw ImportArchiveException::invalidManifest($where);
    }

    /**
     * @param array<mixed> $item
     */
    private static function optional(array $item, string $key, string $where): ?string
    {
        $value = $item[$key] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw ImportArchiveException::invalidManifest($where);
        }

        return str_replace("\0", '', trim((string) $value));
    }
}
