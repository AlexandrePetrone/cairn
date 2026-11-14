<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Tracker;

/**
 * What the issues of an archive use, counted in one pass: issues,
 * comments and files per tracker (open and closed apart, since closed
 * issues may be left out), statuses per tracker, fields, link types,
 * versions, what each person wrote and the open issues assigned to
 * them. Kept on the import as an array
 * between batches and for the configuration pages.
 */
final class ImportAnalysis
{
    private const array NONE = ['issues' => 0, 'comments' => 0, 'files' => 0];

    /** @var array<array-key, array{open: array{issues: int, comments: int, files: int}, closed: array{issues: int, comments: int, files: int}}> */
    public array $trackers = [];

    /** @var array<array-key, array<array-key, int>> tracker id => status id => issues */
    public array $statuses = [];

    /** @var array<array-key, int> field id => issues with a value */
    public array $fields = [];

    /** @var array<array-key, int> link type id => links */
    public array $links = [];

    /** @var array<array-key, int> version id => issues */
    public array $versions = [];

    /** @var array<array-key, array{issues: int, comments: int}> person id => what they wrote */
    public array $people = [];

    /** @var array<array-key, array<array-key, int>> person id => tracker id => open issues assigned to them */
    public array $assigned = [];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $analysis = new self();
        foreach (array_keys(get_object_vars($analysis)) as $property) {
            $analysis->{$property} = (array) ($data[$property] ?? []);
        }

        return $analysis;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function count(ArchiveIssue $issue): void
    {
        $state = $issue->closed ? 'closed' : 'open';
        $this->trackers[$issue->tracker] ??= ['open' => self::NONE, 'closed' => self::NONE];
        ++$this->trackers[$issue->tracker][$state]['issues'];
        $this->trackers[$issue->tracker][$state]['comments'] += \count($issue->comments);
        $this->trackers[$issue->tracker][$state]['files'] += \count($issue->allFiles());

        $this->statuses[$issue->tracker][$issue->status] = ($this->statuses[$issue->tracker][$issue->status] ?? 0) + 1;
        foreach (array_keys($issue->fields) as $field) {
            $this->fields[$field] = ($this->fields[$field] ?? 0) + 1;
        }
        foreach ($issue->links as $link) {
            $this->links[$link['type']] = ($this->links[$link['type']] ?? 0) + 1;
        }
        if (null !== $issue->version) {
            $this->versions[$issue->version] = ($this->versions[$issue->version] ?? 0) + 1;
        }

        if (null !== $issue->assignee && !$issue->closed) {
            $this->assigned[$issue->assignee][$issue->tracker] = ($this->assigned[$issue->assignee][$issue->tracker] ?? 0) + 1;
        }

        $this->people[$issue->author] ??= ['issues' => 0, 'comments' => 0];
        ++$this->people[$issue->author]['issues'];
        foreach ($issue->comments as $comment) {
            $this->people[$comment['author']] ??= ['issues' => 0, 'comments' => 0];
            ++$this->people[$comment['author']]['comments'];
        }
    }

    /** How many issues a tracker has, open and closed. */
    public function issuesOf(string $tracker): int
    {
        return ($this->trackers[$tracker]['open']['issues'] ?? 0) + ($this->trackers[$tracker]['closed']['issues'] ?? 0);
    }

    /**
     * The open issues assigned to each person, among the trackers an
     * import configured that way takes. Someone left without a role on
     * the project loses them.
     *
     * @param list<Tracker> $existingTrackers the trackers of the target project
     *
     * @return array<array-key, int> person id => open issues
     */
    public function openAssigned(ImportMapping $mapping, array $existingTrackers): array
    {
        $assigned = [];
        foreach ($this->assigned as $person => $trackers) {
            foreach ($trackers as $tracker => $issues) {
                if (null !== $mapping->workflowOf((string) $tracker, $existingTrackers)) {
                    $assigned[$person] = ($assigned[$person] ?? 0) + $issues;
                }
            }
        }

        return $assigned;
    }

    /**
     * What the archive holds, or what an import configured that way would
     * write: the trackers it leaves out and the closed issues it does not
     * take are then not counted.
     *
     * @param list<Tracker> $existingTrackers the trackers of the target project
     *
     * @return array{issues: int, comments: int, files: int}
     */
    public function totals(?ImportMapping $mapping = null, array $existingTrackers = []): array
    {
        $totals = self::NONE;
        foreach ($this->trackers as $tracker => $states) {
            if (null !== $mapping && null === $mapping->workflowOf((string) $tracker, $existingTrackers)) {
                continue;
            }
            foreach ($states as $state => $counts) {
                if ('closed' === $state && false === $mapping?->closed) {
                    continue;
                }
                $totals['issues'] += $counts['issues'];
                $totals['comments'] += $counts['comments'];
                $totals['files'] += false === $mapping?->files ? 0 : $counts['files'];
            }
        }

        return $totals;
    }
}
