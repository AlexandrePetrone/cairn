<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Enum\ImportRecordKind;
use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Entity\ImportRecord;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Command\Import\ImportIssue;
use App\Message\Command\Import\ImportLinks;
use App\Message\Command\Import\ImportRelease;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Tracker\CreateTracker;
use App\Message\Command\User\CreateUser;
use App\Message\CommandBus;
use App\Repository\ImportRecordRepository;
use App\Repository\ImportRepository;
use App\Repository\IssueRepository;
use App\Repository\ReleaseRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs a configured import, one batch at a time, through phases that
 * each go to their end before the next: the numbers of every imported
 * issue reserved in one block (so that a key is known before its issue
 * is written), the accounts, the access of those who get a role, the
 * trackers and the releases, the issues in the order of the archive,
 * their links once all are written, then the archive is put away. Every
 * item is one command in its own transaction, then one ImportRecord: a
 * refused item is recorded with its reason and the run goes on, an item
 * already recorded is not written again, so that a resumed import picks
 * up where it stopped. The unit of work is cleared after each item, so
 * that what a refused command left behind never reaches the next one.
 *
 * Like the analyser, a service that writes its own bookkeeping (the
 * import, its records); what lands in the project goes through commands.
 */
final class ImportRunner
{
    public const string FIRST_PHASE = 'numbers';

    /** The phases, in order, for the progress page. */
    public const array PHASES = ['numbers', 'accounts', 'access', 'structure', 'issues', 'links', 'finish'];

    private const int SECONDS = 20;

    private const int ITEMS = 50;

    /** That many issues in a row failing for no reason of theirs: the trouble is not the issues. */
    private const int UNEXPECTED = 5;

    private int $unexpected = 0;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly ImportsDirectory $directory,
        private readonly ImportRepository $imports,
        private readonly ImportRecordRepository $records,
        private readonly UserRepository $users,
        private readonly TrackerRepository $trackers,
        private readonly ReleaseRepository $releases,
        private readonly IssueRepository $issues,
        private readonly AccessControl $access,
        private readonly ImportIssueBuilder $builder,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs one batch.
     *
     * @return bool whether there is more to run
     */
    public function advance(Import $import): bool
    {
        $until = $this->clock->now()->modify('+'.self::SECONDS.' seconds');
        $manifest = ImportManifest::fromArray($import->getManifest());
        $mapping = ImportMapping::fromArray($import->getMapping());

        $more = match ($import->getPhase()) {
            'numbers' => $this->numbers($import, $manifest),
            'accounts' => $this->each($import, 'accounts', array_map(strval(...), array_keys($manifest->people)), 'access', $until, fn (Import $import, string $personId) => $this->account($import, $manifest, $mapping, $personId)),
            'access' => $this->each($import, 'access', array_values(array_filter(array_map(strval(...), array_keys($manifest->people)), static fn (string $id): bool => null !== $mapping->roleOf($id))), 'structure', $until, fn (Import $import, string $personId) => $this->grant($import, $manifest, $mapping, $personId)),
            'structure' => $this->each($import, 'structure', $this->structure($import, $manifest, $mapping), 'issues', $until, fn (Import $import, string $item) => $this->build($import, $manifest, $mapping, $item)),
            'issues', 'links' => $this->pass($import, $manifest, $mapping, $until),
            'finish' => $this->finish($import),
            default => throw new \LogicException(\sprintf('An import has no phase "%s".', $import->getPhase())),
        };
        $this->forget();

        return $more;
    }

    /**
     * Reserves the numbers of the issues the import takes, in one block.
     */
    private function numbers(Import $import, ImportManifest $manifest): bool
    {
        $taken = $this->taken($import, $manifest);
        if (null === $import->getFirstNumber()) {
            $project = $import->getProject();
            $this->entityManager->wrapInTransaction(function () use ($import, $project, $taken): void {
                $this->entityManager->refresh($project, LockMode::PESSIMISTIC_WRITE);
                $import->reserveNumbers($project->reserveIssueNumbers(max(1, \count($taken))));
            });
        }
        $import->move('accounts', 0, 0, \count($manifest->people));
        $this->entityManager->flush();

        return true;
    }

    private function account(Import $import, ImportManifest $manifest, ImportMapping $mapping, string $personId): void
    {
        if (null !== $this->records->findOne($import, ImportRecordKind::User, $personId)) {
            return;
        }
        $person = $manifest->people[$personId];
        $label = $manifest->nameOf($personId).' <'.$person['email'].'>';
        $account = $this->users->findOneBy(['email' => $person['email']]);
        $problem = null;
        if (null === $account) {
            [$account, $problem] = $this->attempt(new CreateUser(
                $person['email'],
                mb_substr($person['firstName'], 0, 80),
                mb_substr($person['lastName'], 0, 80),
                company: null === $person['company'] ? null : mb_substr($person['company'], 0, 120),
                active: $mapping->isActive($personId),
            ));
        }
        $this->record($import, ImportRecordKind::User, $personId, $account instanceof User ? $account->getId() : null, $label, $problem);
    }

    /**
     * Gives the person their role on the project, unless they already
     * hold one there, directly or through the workspace: an access in
     * place is never changed.
     */
    private function grant(Import $import, ImportManifest $manifest, ImportMapping $mapping, string $personId): void
    {
        if (null !== $this->records->findOne($import, ImportRecordKind::Membership, $personId)) {
            return;
        }
        $userId = $this->records->findOne($import, ImportRecordKind::User, $personId)?->getLocalId();
        $user = null === $userId ? null : $this->users->find($userId);
        if (null === $user) {
            return;
        }
        $problem = null;
        if (null === $this->access->roleOn($user, $import->getProject())) {
            [, $problem] = $this->attempt(new GrantMembership((int) $user->getId(), null, (int) $import->getProject()->getId(), (string) $mapping->roleOf($personId)));
        }
        $this->record($import, ImportRecordKind::Membership, $personId, null === $problem ? $userId : null, $manifest->nameOf($personId), $problem);
    }

    /**
     * The trackers the issues go to and the versions in use, as the items
     * of the `structure` phase: `tracker:<id>` and `version:<id>`.
     *
     * @return list<string>
     */
    private function structure(Import $import, ImportManifest $manifest, ImportMapping $mapping): array
    {
        $items = [];
        foreach ($mapping->trackers as $id => $tracker) {
            if (ImportMapping::SKIP !== $tracker['target']) {
                $items[] = 'tracker:'.$id;
            }
        }
        if ($mapping->versions) {
            // Only the versions of the issues the import takes: no release is created empty.
            foreach ($this->scan($import, $manifest)['versions'] as $id) {
                $items[] = 'version:'.$id;
            }
        }

        return $items;
    }

    private function build(Import $import, ImportManifest $manifest, ImportMapping $mapping, string $item): void
    {
        [$kind, $id] = explode(':', $item, 2);
        $project = $import->getProject();
        if ('tracker' === $kind) {
            if (null !== $this->records->findOne($import, ImportRecordKind::Tracker, $id)) {
                return;
            }
            $target = $mapping->trackers[$id];
            $existing = $this->trackers->findByProject($project, includeArchived: true);
            if (str_starts_with($target['target'], 'tracker:')) {
                $wanted = (int) substr($target['target'], 8);
                $tracker = array_find($existing, static fn (Tracker $tracker): bool => $tracker->getId() === $wanted);
                $problem = null === $tracker ? $this->translator->trans('admin.refused.not_found') : null;
            } else {
                $workflow = substr($target['target'], 4);
                $tracker = array_find($existing, static fn (Tracker $tracker): bool => $tracker->getName() === trim($target['name']) && $tracker->getWorkflowId() === $workflow);
                $problem = null;
                if (null === $tracker) {
                    [$tracker, $problem] = $this->attempt(new CreateTracker(null, (int) $project->getId(), $target['name'], $workflow));
                }
            }
            $this->record($import, ImportRecordKind::Tracker, $id, $tracker instanceof Tracker ? $tracker->getId() : null, $manifest->trackers[$id]['label'], $problem);

            return;
        }

        if (null !== $this->records->findOne($import, ImportRecordKind::Release, $id)) {
            return;
        }
        $version = $manifest->versions[$id];
        $name = trim(mb_substr($version['label'], 0, 40));
        $release = $this->releases->findOneByName($project, $name);
        $problem = null;
        if (null === $release) {
            $dates = [];
            foreach (['dueOn', 'createdAt', 'deliveredAt'] as $key) {
                $dates[$key] = 'dueOn' === $key ? (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $version[$key]) ?: null) : ImportManifest::dateTime($version[$key]);
                if (null !== $version[$key] && null === $dates[$key]) {
                    $dates[$key] = $this->clock->now();
                    $this->warn($import, ImportRecordKind::Release, $id.'#'.$key, $version['label'], $this->translator->trans('admin.import.problem.version_date', ['%date%' => $version[$key]]));
                }
            }
            [$release, $problem] = $this->attempt(new ImportRelease(
                (int) $project->getId(),
                $name,
                '' === $version['description'] ? null : $version['description'],
                $dates['dueOn'],
                $version['delivered'],
                $dates['createdAt'],
                $dates['deliveredAt'],
            ));
        }
        $this->record($import, ImportRecordKind::Release, $id, $release instanceof Release ? $release->getId() : null, $version['label'], $problem);
    }

    /**
     * Goes through the issues of the archive from where the phase is:
     * writes those the import takes (`issues`), or their links once all
     * are written (`links`). An issue with nothing to write costs
     * nothing: the position only moves on with the next one written, or
     * at the end of the batch.
     */
    private function pass(Import $import, ImportManifest $manifest, ImportMapping $mapping, \DateTimeImmutable $until): bool
    {
        $id = (int) $import->getId();
        $token = (string) $import->getRunToken();
        $phase = (string) $import->getPhase();
        $existing = $this->trackers->findByProject($import->getProject(), includeArchived: true);
        $archive = $this->directory->open($import);
        $numbers = array_flip($this->taken($import, $manifest));
        // Nobody gets an account once the issues are being written: read once for the batch.
        $users = $this->records->localIds($import, ImportRecordKind::User);
        $position = $import->getPosition();
        $done = $import->getDone();
        $written = 0;
        $finished = true;

        foreach ($archive->issues($manifest, $position) as $next => $issue) {
            $position = $next;
            if (!$mapping->takes($issue, $existing)) {
                continue;
            }
            ++$done;
            if ('issues' === $phase || [] !== $issue->links) {
                $counts = [0, 0];
                'issues' === $phase
                    ? $counts = $this->issue($import, $manifest, $mapping, $archive, $issue, $numbers, $users)
                    : $this->links($import, $mapping, $issue, $numbers);
                $this->forget();
                $import = $this->imports->find($id);
                if (null === $import || ImportStatus::Running !== $import->getStatus() || $token !== $import->getRunToken()) {
                    return false;
                }
                $import->countWritten(...$counts);
                $import->move($phase, $position, $done, \count($numbers));
                $this->entityManager->flush();
                ++$written;
            }
            if ($written >= self::ITEMS || $this->clock->now() >= $until) {
                $finished = false;
                break;
            }
        }

        if ($finished) {
            'issues' === $phase ? $import->move('links', 0, 0, \count($numbers)) : $import->move('finish', 0, 0, 0);
        } else {
            $import->move($phase, $position, $done, \count($numbers));
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * Writes one issue.
     *
     * @param array<array-key, int>      $numbers id in the archive => rank among the issues taken
     * @param array<array-key, int|null> $users   person id => account id
     *
     * @return array{int, int} the comments and files written
     */
    private function issue(Import $import, ImportManifest $manifest, ImportMapping $mapping, ImportArchive $archive, ArchiveIssue $issue, array $numbers, array $users): array
    {
        if (null !== $this->records->findOne($import, ImportRecordKind::Issue, $issue->id)) {
            return [0, 0];
        }
        $prefix = $import->getProject()->getIssuePrefix();
        $first = (int) $import->getFirstNumber();
        $already = $this->issues->findOneByKey($prefix.'-'.($first + $numbers[$issue->id]));
        if (null !== $already) {
            // Written by a batch that stopped before recording it.
            $this->record($import, ImportRecordKind::Issue, $issue->id, $already->getId(), $issue->title);

            return [0, 0];
        }

        $extracted = [];
        $hidden = [];
        try {
            $command = $this->builder->build($import, $manifest, $mapping, $archive, $issue, $first + $numbers[$issue->id], $users, static fn (string $ref): ?string => isset($numbers[$ref]) ? $prefix.'-'.($first + $numbers[$ref]) : null, $extracted, $hidden);
            [$written, $problem] = $command instanceof ImportIssue ? $this->attemptIssue($command) : [null, $command];
        } finally {
            foreach ($extracted as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        $this->record($import, ImportRecordKind::Issue, $issue->id, $written instanceof Issue ? $written->getId() : null, $issue->title, $problem);
        foreach ($written instanceof Issue ? $hidden : [] as $field => $why) {
            // Once per tracker and field, whatever the number of issues.
            $this->warn($import, ImportRecordKind::Tracker, $issue->tracker.'#'.$field, $manifest->trackers[$issue->tracker]['label'], $why);
        }

        return $command instanceof ImportIssue && null === $problem ? [\count($command->comments), \count($extracted)] : [0, 0];
    }

    /**
     * Writes the links of one issue to the other issues of the import.
     *
     * @param array<array-key, int> $numbers
     */
    private function links(Import $import, ImportMapping $mapping, ArchiveIssue $issue, array $numbers): void
    {
        $issueId = $this->records->findOne($import, ImportRecordKind::Issue, $issue->id)?->getLocalId();
        $links = [];
        foreach ($issue->links as $link) {
            $type = $mapping->links[$link['type']] ?? null;
            $other = isset($numbers[$link['to']]) ? $this->records->findOne($import, ImportRecordKind::Issue, $link['to'])?->getLocalId() : null;
            if (null !== $type && null !== $other) {
                $links[] = ['typeId' => $type, 'issueId' => $other];
            }
        }
        if (null !== $issueId && [] !== $links) {
            $this->attempt(new ImportLinks($issueId, $links, (int) $import->getCreatedBy()->getId()));
        }
    }

    /** The import is over: its archive is put away. */
    private function finish(Import $import): bool
    {
        $this->directory->archive($import);
        $import->complete();
        $this->entityManager->flush();

        return false;
    }

    /**
     * Runs the items of a phase one after the other, from where it is,
     * until the batch is full or its time is up; then moves to the next
     * phase. Stops at once when the import was started again elsewhere.
     *
     * @param list<string>                   $items
     * @param callable(Import, string): void $run
     */
    private function each(Import $import, string $phase, array $items, string $next, \DateTimeImmutable $until, callable $run): bool
    {
        $id = (int) $import->getId();
        $token = (string) $import->getRunToken();
        $position = $import->getPosition();
        $total = \count($items);
        for ($done = 0; $position < $total && $done < self::ITEMS && $this->clock->now() < $until; ++$done) {
            $run($import, $items[$position]);
            $this->forget();
            $import = $this->imports->find($id);
            if (null === $import || ImportStatus::Running !== $import->getStatus() || $token !== $import->getRunToken()) {
                return false;
            }
            ++$position;
            $import->move($phase, $position, $position, $total);
            $this->entityManager->flush();
        }
        if ($position >= $total) {
            $import->move($next, 0, 0, 0);
            $this->entityManager->flush();
        }

        return true;
    }

    /**
     * The ids of the issues the import takes, in the order of the
     * archive: the n-th gets the n-th reserved number. Read once, then
     * kept in the work folder.
     *
     * @return list<string>
     */
    private function taken(Import $import, ImportManifest $manifest): array
    {
        return $this->scan($import, $manifest)['taken'];
    }

    /**
     * What the import takes of the archive, read once and kept in its
     * work directory: the ids of its issues, in the order of the
     * archive, and the versions those issues are in. An id met twice is
     * taken once, the first time, and the report says so.
     *
     * @return array{taken: list<string>, versions: list<string>}
     */
    private function scan(Import $import, ImportManifest $manifest): array
    {
        $cache = $this->directory->work($import).'/scan.json';
        if (!is_file($cache)) {
            $mapping = ImportMapping::fromArray($import->getMapping());
            $existing = $this->trackers->findByProject($import->getProject(), includeArchived: true);
            $taken = [];
            $versions = [];
            foreach ($this->directory->open($import)->issues($manifest) as $issue) {
                if (!$mapping->takes($issue, $existing)) {
                    continue;
                }
                if (isset($taken[$issue->id])) {
                    $this->warn($import, ImportRecordKind::Issue, $issue->id.'#twice', $issue->title, $this->translator->trans('admin.import.problem.duplicate', ['%id%' => $issue->id]));
                    continue;
                }
                $taken[$issue->id] = true;
                if (null !== $issue->version) {
                    $versions[$issue->version] = true;
                }
            }
            file_put_contents($cache, json_encode(['taken' => array_keys($taken), 'versions' => array_keys($versions)], \JSON_THROW_ON_ERROR));
        }
        $scan = (array) json_decode((string) file_get_contents($cache), true);

        return ['taken' => array_map(strval(...), array_values((array) ($scan['taken'] ?? []))), 'versions' => array_map(strval(...), array_values((array) ($scan['versions'] ?? [])))];
    }

    /**
     * Something the report says although the item itself was written,
     * or beside it: recorded once under an id of its own, which no id
     * of an archive can be (`#`).
     */
    private function warn(Import $import, ImportRecordKind $kind, string $remoteId, string $label, string $problem): void
    {
        if (null === $this->records->findOne($import, $kind, $remoteId)) {
            $this->record($import, $kind, $remoteId, null, $label, $problem);
        }
    }

    private function record(Import $import, ImportRecordKind $kind, string $remoteId, ?int $localId, string $label, ?string $problem = null): void
    {
        // A refused command clears the unit of work: the import is taken again by reference.
        $import = $this->entityManager->getReference(Import::class, (int) $import->getId()) ?? throw new \LogicException('The import is gone.');
        $refused = null === $localId || null !== $problem;
        $this->entityManager->persist(new ImportRecord($import, $kind, $remoteId, $refused ? null : $localId, $label, $refused ? ($problem ?? $this->translator->trans('admin.refused.not_found')) : null));
        $this->entityManager->flush();
    }

    /**
     * Dispatches a command: what its handler returned, or why it was
     * refused, in the language of the instance. What a refused command
     * left in the unit of work is dropped.
     *
     * @return array{mixed, string|null}
     */
    private function attempt(object $command): array
    {
        try {
            return [$this->commands->dispatch($command), null];
        } catch (ValidationFailedException $e) {
            $problem = implode(' ', array_map(static fn ($violation): string => $violation->getPropertyPath().': '.$violation->getMessage(), iterator_to_array($e->getViolations())));
        } catch (\DomainException|AccessDeniedException $e) {
            $problem = $e instanceof TranslatableInterface ? $e->trans($this->translator) : $e->getMessage();
        }
        $this->forget();

        return [null, $problem];
    }

    /**
     * Dispatches the command of an issue. Whatever its data breaks, a
     * refusal or not, leaves that issue out with the reason, so that one
     * issue never stops an import for good. Several in a row failing
     * unexpectedly is something else (the database, the disk): the
     * import then fails, to be resumed once it is fixed.
     *
     * @return array{mixed, string|null}
     */
    private function attemptIssue(ImportIssue $command): array
    {
        try {
            $result = $this->attempt($command);
            $this->unexpected = 0;

            return $result;
        } catch (\Throwable $e) {
            if (++$this->unexpected >= self::UNEXPECTED) {
                throw $e;
            }
            $this->logger->error('Import: the issue {ref} could not be written: {reason}', ['ref' => $command->importedRef, 'reason' => $e->getMessage(), 'exception' => $e]);
            if (!$this->entityManager->isOpen()) {
                $this->doctrine->resetManager();
            }
            $this->forget();

            return [null, $this->translator->trans('admin.import.failure', ['%reason%' => $e->getMessage()])];
        }
    }

    /** Empties the unit of work, and what AccessControl remembers of the memberships. */
    private function forget(): void
    {
        $this->entityManager->clear();
        $this->access->reset();
    }
}
