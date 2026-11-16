<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\CodeReference;
use App\Entity\Comment;
use App\Entity\Enum\Forge;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\MergeRequestState;
use App\Entity\Issue;
use App\Entity\JournalEntry as Entry;
use App\Entity\Tracker;
use App\Entity\User;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\Security\AccessFixtures;
use App\Twig\Components\Issue\JournalEntry;
use App\Twig\JournalLabelExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sentence of a journal line, built from what the entry recorded by
 * the `journal_label` filter the component delegates to.
 */
final class JournalEntryTest extends KernelTestCase
{
    private JournalEntry $component;
    private Issue $issue;
    private User $claire;
    private User $nadia;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->component = new JournalEntry(new JournalLabelExtension(self::getContainer()->get(TranslatorInterface::class)));
        $fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->claire = $fixtures->user('claire@client.test');
        $this->nadia = $fixtures->user('nadia@agency.test', internal: true);
        $this->issue = new Issue($fixtures->shop, new Tracker($fixtures->shop, 'Bug', 'bug'), DefinitionFactory::place('new'), 4, $this->claire, 'Empty export', '');
    }

    #[DataProvider('changes')]
    public function testAChangeIsToldWithItsValuesTranslated(string $field, ?string $from, ?string $to, string $expected): void
    {
        $this->component->entry = Entry::changed($this->issue, $this->nadia, $field, $from, $to);

        self::assertSame($expected, $this->component->getLabel());
        self::assertNull($this->component->getVersions());
        self::assertNull($this->component->getDeletedBody());
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function changes(): iterable
    {
        yield 'assignee given' => ['assignee', null, 'Nadia Test', 'changed the assignee: Nobody → Nadia Test'];
        yield 'assignee taken away' => ['assignee', 'Nadia Test', null, 'changed the assignee: Nadia Test → Nobody'];
        yield 'criticality' => ['criticality', 'normal', 'blocking', 'changed the criticality: Normal → Blocking'];
        yield 'visibility' => ['visibility', 'public', 'private', 'changed the visibility: All members → Internal users only'];
        yield 'title' => ['title', 'Empty export', 'Empty CSV export', 'changed the title: "Empty export" → "Empty CSV export"'];
    }

    public function testARewrittenDescriptionKeepsBothVersions(): void
    {
        $this->component->entry = Entry::changed($this->issue, $this->claire, 'description', 'Before', 'After');

        self::assertSame('rewrote the description', $this->component->getLabel());
        self::assertSame(['from' => 'Before', 'to' => 'After'], $this->component->getVersions());
    }

    public function testCommentEventsNameTheAuthorWhenItIsSomeoneElse(): void
    {
        $comment = new Comment($this->issue, $this->claire, 'Thanks.');

        $this->component->entry = Entry::commentEdited($this->issue, $this->claire, $comment, 'Thanks.', 'Thanks!');
        self::assertSame('edited their comment', $this->component->getLabel());
        self::assertSame(['from' => 'Thanks.', 'to' => 'Thanks!'], $this->component->getVersions());

        $this->component->entry = Entry::commentDeleted($this->issue, $this->nadia, $comment);
        self::assertSame('deleted the comment of Claire Test', $this->component->getLabel());
        self::assertNull($this->component->getVersions());
        self::assertSame('Thanks.', $this->component->getDeletedBody());
    }

    public function testTheOtherEventsReadTheirLabelsOfTheTime(): void
    {
        $this->component->entry = Entry::created($this->issue, $this->claire);
        self::assertSame('created the issue', $this->component->getLabel());

        $this->component->entry = Entry::attachmentAdded($this->issue, $this->claire, new \App\Entity\Attachment($this->issue->getProject(), $this->issue, $this->claire, 'capture.png', 'image/png', 12));
        self::assertSame('added the file capture.png', $this->component->getLabel());

        $workflow = DefinitionFactory::bugWorkflow();
        $this->component->entry = Entry::moved($this->issue, $this->nadia, DefinitionFactory::place('new'), DefinitionFactory::place('in_progress'));
        self::assertSame('moved the issue without an action: New → In progress', $this->component->getLabel());
    }

    public function testAPushIsOnePrivateEntryWithoutAuthorListingItsCommits(): void
    {
        $commits = [
            CodeReference::commit($this->issue, Forge::GitLab, 'maison-dupont/shop', 'a1b2c3d4e5f6', 'fix(export): paginate', 'https://gitlab.test/c/a1b2c3d4e5f6', 'main', 'Nadia Benali'),
            CodeReference::commit($this->issue, Forge::GitLab, 'maison-dupont/shop', 'e4f5a6b7c8d9', 'test(export): 8 000 orders', 'https://gitlab.test/c/e4f5a6b7c8d9', 'main', 'Léa Fontaine'),
        ];
        $this->component->entry = Entry::commitsPushed($this->issue, 'main', 'https://gitlab.test/tree/main', $commits);

        self::assertNull($this->component->entry->getAuthor());
        self::assertTrue($this->component->entry->isPrivate());
        self::assertTrue($this->component->entry->getType()->isCode());
        self::assertSame('noted 2 commits on main in maison-dupont/shop', $this->component->getLabel());
        self::assertSame(['a1b2c3d4', 'e4f5a6b7'], array_column($this->component->entry->getData()['commits'], 'sha'));
        self::assertSame('gitlab', $this->component->entry->text('forge'));
        self::assertNull($this->component->getVersions());
    }

    public function testAMergeRequestEntryKeepsItsStateOfTheTime(): void
    {
        $mergeRequest = CodeReference::mergeRequest($this->issue, Forge::GitLab, 'maison-dupont/shop', '431', 'fix(export): paginate', 'https://gitlab.test/mr/431', 'fix/MD-SPT-4-export', 'Nadia Benali', MergeRequestState::Opened);
        $this->component->entry = Entry::mergeRequest($this->issue, $mergeRequest, JournalEntryType::MergeRequestReopened);

        self::assertSame('noted that the merge request !431 “fix(export): paginate” in maison-dupont/shop was reopened', $this->component->getLabel());
        self::assertSame('opened', $this->component->entry->text('state'));
        self::assertSame('fix/MD-SPT-4-export', $this->component->entry->text('branch'));
        self::assertTrue($this->component->entry->isPrivate());
    }
}
