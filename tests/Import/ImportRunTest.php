<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\ImportStatus;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\ReleaseStatus;
use App\Entity\Import;
use App\Entity\ImportRecord;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\IssueSubscription;
use App\Entity\JournalEntry;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Import\ImportMapping;
use App\Message\Command\Import\AbandonImport;
use App\Message\Command\Import\ConfigureImport;
use App\Message\Command\Import\CreateImport;
use App\Message\Command\Import\StartImport;
use App\Message\CommandBus;
use App\Message\Import\AdvanceImport;
use App\Message\Notification\NotifyIssueChange;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Command\Import\ImportException;
use App\MessageHandler\Import\AdvanceImportHandler;
use App\Repository\ImportRecordRepository;
use App\Security\AccessControl;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The example archive (see ArchiveBuilder) imported into the shop of the
 * scene (MD-SPT, ten issues, trackers Bug and Feature), batch after batch
 * as the worker would: Alice of the archive has the email of Nadia, a
 * developer of the shop; Bob and Carol have no account.
 */
final class ImportRunTest extends KernelTestCase
{
    use ImportsArchives;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private ShopScene $scene;
    private InMemoryTransport $async;
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->scene = ShopScene::load($container);
        $this->commands = self::commandBus();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $async = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        // The scene made its issues through the real commands, which notify.
        $this->async->reset();
        $this->directory = $this->importsDirectory();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheAnalysisCountsTheArchiveAndGuessesTheConfiguration(): void
    {
        $import = $this->analysed();

        self::assertSame(ImportStatus::Configuring, $import->getStatus(), (string) $import->getError());
        $analysis = $import->getAnalysis();
        self::assertSame(['issues' => 1, 'comments' => 2, 'files' => 2], $analysis['trackers']['bug']['open']);
        self::assertSame(1, $analysis['trackers']['feature']['closed']['issues']);
        self::assertSame(['issues' => 2, 'comments' => 0], $analysis['people']['102']);
        self::assertSame(['issues' => 0, 'comments' => 2], $analysis['people']['101']);

        $mapping = ImportMapping::fromArray($import->getMapping());
        self::assertSame('tracker:'.$this->scene->bugs->getId(), $mapping->trackers['bug']['target'], 'the tracker of the project that has its name');
        self::assertSame('tracker:'.$this->scene->evolutions->getId(), $mapping->trackers['feature']['target']);
        self::assertSame('new:bug', $mapping->trackers['support']['target']);
        self::assertSame(['new' => 'new', 'in_progress' => 'in_progress'], $mapping->places['bug']['bugs'], 'by label; the closed status has no issue of these trackers');
        self::assertSame(['shipped' => 'done'], $mapping->places['evolution']['features'], 'a closed status goes to the first closed place');
        self::assertSame(['environment' => 'field:environment', 'browser' => 'field:browser', 'due_date' => 'field:due_date', 'module' => 'append'], $mapping->fields);
        self::assertSame(['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'urgent' => 'blocking'], $mapping->priorities);
        self::assertSame(['relates' => 'related', 'blocks' => 'blocks'], $mapping->links);
        self::assertFalse($mapping->isActive('102'), 'nobody is active unless said');
    }

    public function testAnImportWritesTheIssuesAsTheyStoodAndNotifiesNobody(): void
    {
        $import = $this->configured();
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        self::assertSame([], $this->leftOut($import));

        $nadia = $this->user('nadia.benali@cairn.test');
        $bob = $this->user('bob@client.example');
        $carol = $this->user('carol@client.example');

        $first = $this->issue('MD-SPT-11');
        self::assertSame('The menu overlaps the logo', $first->getTitle());
        self::assertSame('1001', $first->getImportedRef());
        self::assertSame('2024-03-01T09:00:00+00:00', $first->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame('2024-03-04T16:20:00+00:00', $first->getUpdatedAt()->format(\DATE_ATOM));
        self::assertSame('new', $first->getPlace());
        self::assertSame(Criticality::Normal, $first->getCriticality());
        self::assertSame($this->scene->bugs->getId(), $first->getTracker()->getId());
        self::assertSame($bob->getId(), $first->getAuthor()->getId());
        self::assertSame($nadia->getId(), $first->getAssignee()?->getId());
        self::assertSame('1.1', $first->getRelease()?->getName());
        self::assertSame(['environment' => 'prod', 'browser' => 'Firefox', 'due_date' => '2024-03-15'], $first->getFieldValues());
        self::assertGreaterThan($this->scene->issue('MD-SPT-1')->getBoardRank(), $first->getBoardRank() + 1, 'ranked in its column');

        $attachments = $this->entityManager->getRepository(Attachment::class)->findBy(['issue' => $first], ['id' => 'ASC']);
        self::assertCount(2, $attachments);
        [$screen, $trace] = 'screen.png' === $attachments[0]->getFilename() ? $attachments : array_reverse($attachments);
        self::assertSame('image/png', $screen->getMediaType());
        self::assertSame($bob->getId(), $screen->getUploadedBy()->getId());
        self::assertNull($screen->getComment());
        self::assertFileExists(self::getContainer()->get(AttachmentStorage::class)->path($screen));
        $description = $first->getDescription();
        self::assertStringContainsString('See #MD-SPT-12 and ask @[Alice Martin](user:'.$nadia->getId().').', $description);
        self::assertStringContainsString('![](/attachments/'.$screen->getUuid()->toRfc4122().'/screen.png)', $description);
        self::assertStringContainsString('- **Module**: Checkout', $description, 'a field without a counterpart ends the description');

        $comments = $this->entityManager->getRepository(Comment::class)->findBy(['issue' => $first], ['createdAt' => 'ASC']);
        self::assertCount(2, $comments);
        self::assertSame('Fixed with #MD-SPT-12, see @[Bob Client](user:'.$bob->getId().').', $comments[0]->getBody());
        self::assertSame('2024-03-02T10:00:00+00:00', $comments[0]->getCreatedAt()->format(\DATE_ATOM));
        self::assertSame($nadia->getId(), $comments[0]->getAuthor()?->getId());
        self::assertTrue($comments[1]->isPrivate(), 'a private comment stays private');
        self::assertSame($comments[1]->getId(), $trace->getComment()?->getId(), 'and its file goes with it');
        self::assertStringContainsString('[trace.log](/attachments/'.$trace->getUuid()->toRfc4122().'/trace.log)', $comments[1]->getBody());

        $journal = $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $first]);
        self::assertSame([JournalEntryType::Imported], array_map(static fn (JournalEntry $entry) => $entry->getType(), $journal));
        self::assertSame(['source' => 'Redmine', 'ref' => '1001', 'notes' => []], $journal[0]->getData());
        self::assertNull($journal[0]->getAuthor());
        self::assertTrue($journal[0]->isPrivate());

        $second = $this->issue('MD-SPT-12');
        self::assertSame('done', $second->getPlace());
        self::assertSame('2024-02-14T12:00:00+00:00', $second->getClosedAt()?->format(\DATE_ATOM));
        self::assertSame(Criticality::Low, $second->getCriticality());
        self::assertSame($carol->getId(), $second->getAuthor()->getId());
        self::assertSame(ReleaseStatus::Delivered, $second->getRelease()?->getStatus());
        self::assertSame('2024-02-16', $second->getRelease()->getDeliveredAt()?->format('Y-m-d'));
        self::assertNotNull($this->entityManager->getRepository(IssueLink::class)->findOneBy(['source' => $second, 'type' => 'blocks', 'target' => $first]));

        $third = $this->issue('MD-SPT-13');
        self::assertSame('Support', $third->getTracker()->getName(), 'a tracker created by the import');
        self::assertSame('bug', $third->getTracker()->getWorkflowId());
        self::assertSame('in_progress', $third->getPlace());
        self::assertTrue($third->isPrivate());
        self::assertSame(Criticality::Blocking, $third->getCriticality());
        self::assertNull($third->getAssignee(), 'Carol has no role on the project: an open issue is not left with her');
        self::assertSame(['Carol Former was assigned, but has no role on the project.'], $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $third])?->getData()['notes']);
        self::assertNotNull($this->entityManager->getRepository(IssueLink::class)->findOneBy(['source' => $third, 'type' => 'related', 'target' => $first]));

        self::assertTrue($bob->isActive());
        self::assertFalse($bob->isInternal());
        self::assertTrue($bob->awaitsPassword(), 'created without a password, to invite');
        self::assertSame('Client Ltd', $bob->getCompany());
        self::assertFalse($carol->isActive());
        $access = self::getContainer()->get(AccessControl::class);
        self::assertSame('client', $access->roleOn($bob, $this->scene->shop));
        self::assertNull($access->roleOn($carol, $this->scene->shop));
        self::assertSame('developer', $access->roleOn($nadia, $this->scene->shop), 'an access in place is not changed');

        $subscribers = array_map(
            static fn (IssueSubscription $subscription): string => $subscription->getUser()->getEmail(),
            $this->entityManager->getRepository(IssueSubscription::class)->findBy(['issue' => $first], ['id' => 'ASC']),
        );
        self::assertSame(['bob@client.example', 'nadia.benali@cairn.test'], $subscribers, 'author, assignee and watchers who can sign in and see it');

        self::assertSame([], $this->sent(NotifyIssueChange::class), 'an import notifies nobody');
        self::assertEmailCount(0);
        self::assertSame(2, $import->getCommentsWritten());
        self::assertSame(2, $import->getFilesWritten());

        self::assertFileDoesNotExist($this->directory.'/example.zip');
        self::assertFileExists($this->directory.'/archive/example.zip', 'the archive is put away');
        self::assertDirectoryDoesNotExist($this->directory.'/work/'.$import->getId());

        foreach (['1002', '#1002'] as $search) {
            $found = self::queryBus()->ask(new SearchIssues($this->scene->user('julie.admin@cairn.test'), projectId: $this->scene->shop->getId(), q: $search, closed: true));
            self::assertSame(['MD-SPT-12'], array_map(static fn (Issue $issue): string => $issue->getKey(), $found->items), 'found by what named it before');
        }
    }

    public function testADeactivatedAccountWithARoleKeepsItsOpenIssues(): void
    {
        $import = $this->configured();
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), people: ['103' => ['active' => false, 'role' => 'client']]));
        $this->launch($import);

        $third = $this->issue('MD-SPT-13');
        self::assertSame('carol@client.example', $third->getAssignee()?->getEmail());
        self::assertFalse($third->getAssignee()->isActive(), 'imported without a word to the client: the account is activated later');
        self::assertSame([], $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $third])?->getData()['notes']);
    }

    public function testWithoutClosedIssuesOrFilesOnlyTheRestIsWritten(): void
    {
        $import = $this->configured();
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), closed: false, files: false, versions: false));
        $this->launch($import);

        self::assertSame(ImportStatus::Completed, $this->reload($import)->getStatus());
        self::assertSame(['1001', '1003'], array_map(static fn (Issue $issue): ?string => $issue->getImportedRef(), $this->entityManager->getRepository(Issue::class)->findBy(['key' => ['MD-SPT-11', 'MD-SPT-12']], ['number' => 'ASC'])));
        $first = $this->issue('MD-SPT-11');
        self::assertNull($first->getRelease());
        self::assertSame(0, $this->entityManager->getRepository(Attachment::class)->count(['issue' => $first]));
        self::assertStringContainsString('See #1002', $first->getDescription(), 'a reference to an issue left out stays a text');
    }

    public function testOnlyTheVersionsOfTheIssuesTakenBecomeReleases(): void
    {
        $import = $this->configured();
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), closed: false));
        $this->launch($import);

        self::assertSame(ImportStatus::Completed, $this->reload($import)->getStatus());
        $releases = array_map(static fn (Release $release): string => $release->getName(), $this->entityManager->getRepository(Release::class)->findBy(['project' => $this->scene->shop]));
        self::assertContains('1.1', $releases, 'the version of the open bug');
        self::assertNotContains('1.0', $releases, 'only the closed feature was in it: it would be empty');
    }

    public function testWhatTheArchiveGotWrongIsImportedAnywayAndSaidInTheReport(): void
    {
        $import = $this->configured(static function (array $manifest, array $issues, array $files): array {
            $manifest['people'][0]['email'] = 'nadia.benali@cairn.test';
            // A date as JavaScript writes it, and one that is no date.
            $manifest['versions'][0]['createdAt'] = '2024-01-02T08:00:00.000Z';
            $manifest['versions'][0]['deliveredAt'] = 'mid-February';
            // The same id twice.
            $issues[] = ['id' => '1001', 'title' => 'The same menu again'] + $issues[0];

            return [$manifest, $issues, $files];
        });
        // The bugs go to a workflow that does not use the field their environment is sent to.
        $trackers = ['bug' => ['target' => 'new:task', 'name' => 'Old bugs']] + ImportMapping::fromArray($import->getMapping())->trackers;
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), trackers: $trackers, places: ['task' => ['bugs' => ['new' => 'new']]] + ImportMapping::fromArray($import->getMapping())->places));
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        $said = [];
        foreach (self::getContainer()->get(ImportRecordRepository::class)->findRefused($import) as $record) {
            $said[$record->getLabel()][] = $record->getProblem();
        }
        self::assertSame(['Another issue of the archive has the id 1001: only the first one is imported.'], $said['The same menu again'] ?? null);
        self::assertSame(['Its date "mid-February" cannot be read: today\'s date took its place.'], $said['1.0'] ?? null);
        self::assertContains('The workflow "Internal task" does not use the field "Environment": its values are kept but shown nowhere on these issues.', $said['Bug'] ?? []);

        self::assertSame(1, $this->entityManager->getRepository(Issue::class)->count(['importedRef' => '1001']));
        $first = $this->entityManager->getRepository(Issue::class)->findOneBy(['importedRef' => '1001']);
        self::assertSame('The menu overlaps the logo', $first?->getTitle());
        self::assertSame('prod', $first->getFieldValues()['environment'] ?? null, 'kept as chosen');
        self::assertSame(['MD-SPT-11', 'MD-SPT-12', 'MD-SPT-13'], array_map(static fn (Issue $issue): string => $issue->getKey(), $this->entityManager->getRepository(Issue::class)->findBy(['importedRef' => ['1001', '1002', '1003']], ['number' => 'ASC'])), 'no number is lost to the second one');
        $release = $this->entityManager->getRepository(Release::class)->findOneBy(['project' => $this->scene->shop, 'name' => '1.0']);
        self::assertSame('2024-01-02', $release?->getCreatedAt()->format('Y-m-d'));
        self::assertNotNull($release->getDeliveredAt());
    }

    public function testAStoppedImportResumesWithoutWritingTwice(): void
    {
        // The third issue names a file; the zip loses it after the analysis.
        $withFile = static function (array $manifest, array $issues, array $files): array {
            $manifest['people'][0]['email'] = 'nadia.benali@cairn.test';
            $issues[2]['files'] = [['id' => '503', 'path' => 'files/503', 'name' => 'notes.txt', 'author' => '102', 'createdAt' => '2024-03-05T09:00:00+00:00']];

            return [$manifest, $issues, $files + ['files/503' => 'notes']];
        };
        $import = $this->configured($withFile);
        ArchiveBuilder::example($this->directory, 'example.zip', static function (array $manifest, array $issues, array $files) use ($withFile): array {
            [$manifest, $issues, $files] = $withFile($manifest, $issues, $files);
            unset($files['files/503']);

            return [$manifest, $issues, $files];
        });
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Failed, $import->getStatus());
        self::assertSame('files/503 is missing from the archive.', $import->getError());
        self::assertTrue($import->isResumable());
        self::assertSame(2, $this->entityManager->getRepository(Issue::class)->count(['importedRef' => ['1001', '1002', '1003']]));

        ArchiveBuilder::example($this->directory, 'example.zip', $withFile);
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        $keys = array_map(static fn (Issue $issue): string => $issue->getKey(), $this->entityManager->getRepository(Issue::class)->findBy(['importedRef' => ['1001', '1002', '1003']], ['number' => 'ASC']));
        self::assertSame(['MD-SPT-11', 'MD-SPT-12', 'MD-SPT-13'], $keys);
        self::assertSame(2, $this->entityManager->getRepository(Comment::class)->count(['issue' => $this->issue('MD-SPT-11')]), 'nothing written twice');
        self::assertSame(1, $this->entityManager->getRepository(Tracker::class)->count(['project' => $this->scene->shop, 'name' => 'Support']));
    }

    public function testAnIssueWhoseStepNoLongerExistsIsLeftOutRatherThanPlacedAtTheStart(): void
    {
        $import = $this->configured();
        $this->commands->dispatch(new StartImport((int) $import->getId()));
        // The configuration of Cairn changed after the checks of the start: the step chosen is gone.
        $mapping = $import->getMapping();
        $mapping['places']['bug']['bugs']['in_progress'] = 'gone';
        \Closure::bind(fn () => $this->mapping = $mapping, $import, Import::class)();
        $this->entityManager->flush();
        $this->work();

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        $refused = $this->leftOut($import);
        self::assertSame(['Its status "In progress" has no step in the workflow "Bug" any more.'], array_map(static fn ($record) => $record->getProblem(), $refused));
        self::assertSame(0, $this->entityManager->getRepository(Issue::class)->count(['importedRef' => '1003']));
    }

    public function testAnIssueThatBreaksUnexpectedlyIsLeftOutAndTheImportGoesOn(): void
    {
        $import = $this->configured();
        // Nothing can be stored: the issue that has files breaks, for no reason of the archive.
        self::getContainer()->set(AttachmentStorage::class, new AttachmentStorage(__FILE__.'/nowhere'));
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        $refused = $this->leftOut($import);
        self::assertSame(['The menu overlaps the logo'], array_map(static fn ($record) => $record->getLabel(), $refused));
        self::assertStringStartsWith('Unexpected error:', (string) $refused[0]->getProblem());
        self::assertSame(['1002', '1003'], array_map(static fn (Issue $issue): ?string => $issue->getImportedRef(), $this->entityManager->getRepository(Issue::class)->findBy(['importedRef' => ['1001', '1002', '1003']], ['number' => 'ASC'])));
    }

    public function testAnArchiveThatNamesAMissingFileIsRefusedByTheAnalysis(): void
    {
        $import = $this->analysed(static function (array $manifest, array $issues, array $files): array {
            unset($files['files/502']);

            return [$manifest, $issues, $files];
        });

        self::assertSame(ImportStatus::Failed, $import->getStatus());
        self::assertSame('issues.ndjson, issue 1001: the file files/502 is not in the archive.', $import->getError());

        // The archive is fixed and dropped again under its name: the analysis starts over from it.
        ArchiveBuilder::example($this->directory, 'example.zip');
        $this->commands->dispatch(new StartImport((int) $import->getId()));
        $this->work();
        $import = $this->reload($import);
        self::assertSame(ImportStatus::Configuring, $import->getStatus(), (string) $import->getError());
        self::assertSame(2, $import->getAnalysis()['trackers']['bug']['open']['files']);

        $this->commands->dispatch(new AbandonImport((int) $import->getId()));
        self::assertSame(ImportStatus::Abandoned, $this->reload($import)->getStatus());
        self::assertFileExists($this->directory.'/example.zip', 'the archive stays, to be fixed and imported again');
    }

    public function testTheWorkerChainsTheBatchesAndDropsAStaleMessage(): void
    {
        $import = $this->created();
        $sent = $this->sent(AdvanceImport::class);
        self::assertCount(1, $sent);
        $this->async->reset();

        $handler = self::getContainer()->get(AdvanceImportHandler::class);
        $handler(new AdvanceImport((int) $import->getId(), 'not-the-token'));
        self::assertSame([], $this->async->getSent(), 'a message of another start does nothing');

        $handler($sent[0]);
        self::assertCount(1, $this->sent(AdvanceImport::class), 'the next batch is queued');
    }

    public function testOneImportAtATimePerProjectAndPerArchive(): void
    {
        $this->created();
        ArchiveBuilder::example($this->directory, 'other.zip');

        $this->expectException(ImportException::class);
        $this->commands->dispatch(new CreateImport((int) $this->scene->user('julie.admin@cairn.test')->getId(), 'other.zip', (int) $this->scene->shop->getId()));
    }

    /**
     * @param array<string, mixed> $choices
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusedChoices')]
    public function testAChoiceTheArchiveOrCairnDoesNotAllowIsRefused(array $choices): void
    {
        $import = $this->analysed();

        $this->expectException(ImportException::class);
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), ...$choices));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideRefusedChoices(): iterable
    {
        yield 'a place of another workflow' => [['places' => ['bug' => ['bugs' => ['new' => 'planned']]]]];
        yield 'a workflow that does not exist' => [['trackers' => ['support' => ['target' => 'new:nothing', 'name' => 'Support']]]];
    }

    public function testARoleTheAccountCannotHoldIsLeftOutAndListed(): void
    {
        $import = $this->configured();
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), people: ['102' => ['active' => true, 'role' => 'developer']]));
        $this->launch($import);

        $import = $this->reload($import);
        self::assertSame(ImportStatus::Completed, $import->getStatus(), (string) $import->getError());
        $refused = $this->leftOut($import);
        self::assertSame(['Bob Client'], array_map(static fn ($record) => $record->getLabel(), $refused), 'a new account is external: no role for internal users');
        self::assertNull(self::getContainer()->get(AccessControl::class)->roleOn($this->user('bob@client.example'), $this->scene->shop));
    }

    public function testAnImportThatTakesNoIssueDoesNotStart(): void
    {
        $import = $this->analysed();
        $skip = ['target' => ImportMapping::SKIP, 'name' => ''];
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), trackers: ['bug' => $skip, 'feature' => $skip, 'support' => $skip]));

        $this->expectException(ImportException::class);
        $this->commands->dispatch(new StartImport((int) $import->getId()));
    }

    private function created(?callable $change = null): Import
    {
        ArchiveBuilder::example($this->directory, 'example.zip', $change);
        $import = $this->commands->dispatch(new CreateImport((int) $this->scene->user('julie.admin@cairn.test')->getId(), 'example.zip', (int) $this->scene->shop->getId()));
        \assert($import instanceof Import);
        $this->commands->dispatch(new StartImport((int) $import->getId()));

        return $import;
    }

    private function analysed(?callable $change = null): Import
    {
        $import = $this->created($change);
        $this->work();

        return $this->reload($import);
    }

    /** Alice is Nadia; Bob's account is active, and he is a client of the project. */
    private function configured(?callable $change = null): Import
    {
        $import = $this->analysed($change ?? static function (array $manifest, array $issues, array $files): array {
            $manifest['people'][0]['email'] = 'nadia.benali@cairn.test';

            return [$manifest, $issues, $files];
        });
        self::assertSame(ImportStatus::Configuring, $import->getStatus(), (string) $import->getError());
        $this->commands->dispatch(new ConfigureImport((int) $import->getId(), people: ['102' => ['active' => true, 'role' => 'client']]));

        return $this->reload($import);
    }

    private function launch(Import $import): void
    {
        $this->commands->dispatch(new StartImport((int) $import->getId()));
        $this->work();
    }

    private function reload(Import $import): Import
    {
        return $this->entityManager->getRepository(Import::class)->find($import->getId()) ?? throw new \LogicException('No import.');
    }

    /**
     * What the import left out. What it only says beside an item it
     * wrote (a field its workflow does not show…) has an id of its own,
     * with a `#`, and depends on the example configuration: left to the
     * test that is about it.
     *
     * @return list<ImportRecord>
     */
    private function leftOut(Import $import): array
    {
        return array_values(array_filter(self::getContainer()->get(ImportRecordRepository::class)->findRefused($import), static fn (ImportRecord $record): bool => !str_contains($record->getRemoteId(), '#')));
    }

    private function issue(string $key): Issue
    {
        return $this->entityManager->getRepository(Issue::class)->findOneBy(['key' => $key]) ?? throw new \LogicException('No issue '.$key);
    }

    private function user(string $email): User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException('No user '.$email);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function sent(string $class): array
    {
        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->async->getSent()),
            static fn (object $message): bool => $message instanceof $class,
        ));
    }
}
