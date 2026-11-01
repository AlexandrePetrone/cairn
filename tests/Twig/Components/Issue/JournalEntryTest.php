<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry as Entry;
use App\Entity\Tracker;
use App\Entity\User;
use App\Tests\Security\AccessFixtures;
use App\Twig\Components\Issue\JournalEntry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sentence of a journal line, built from what the entry recorded.
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
        $this->component = new JournalEntry(self::getContainer()->get(TranslatorInterface::class));
        $fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->claire = $fixtures->user('claire@client.test');
        $this->nadia = $fixtures->user('nadia@agency.test', internal: true);
        $this->issue = new Issue($fixtures->shop, new Tracker($fixtures->shop, 'Anomalie', 'bug'), 4, $this->claire, 'Export vide', '');
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
        yield 'assignee given' => ['assignee', null, 'Nadia Test', 'a changé l\'assigné : Personne → Nadia Test'];
        yield 'assignee taken away' => ['assignee', 'Nadia Test', null, 'a changé l\'assigné : Nadia Test → Personne'];
        yield 'criticality' => ['criticality', 'normal', 'blocking', 'a changé la criticité : Normale → Bloquante'];
        yield 'visibility' => ['visibility', 'public', 'private', 'a changé la visibilité : Visible par les externes → Invisible pour les externes'];
        yield 'title' => ['title', 'Export vide', 'Export CSV vide', 'a changé le titre : « Export vide » → « Export CSV vide »'];
    }

    public function testARewrittenDescriptionKeepsBothVersions(): void
    {
        $this->component->entry = Entry::changed($this->issue, $this->claire, 'description', 'Avant', 'Après');

        self::assertSame('a modifié la description', $this->component->getLabel());
        self::assertSame(['from' => 'Avant', 'to' => 'Après'], $this->component->getVersions());
    }

    public function testCommentEventsNameTheAuthorWhenItIsSomeoneElse(): void
    {
        $comment = new Comment($this->issue, $this->claire, 'Merci.');

        $this->component->entry = Entry::commentEdited($this->issue, $this->claire, $comment, 'Merci.', 'Merci !');
        self::assertSame('a modifié son commentaire', $this->component->getLabel());
        self::assertSame(['from' => 'Merci.', 'to' => 'Merci !'], $this->component->getVersions());

        $this->component->entry = Entry::commentDeleted($this->issue, $this->nadia, $comment);
        self::assertSame('a supprimé le commentaire de Claire Test', $this->component->getLabel());
        self::assertNull($this->component->getVersions());
        self::assertSame('Merci.', $this->component->getDeletedBody());
    }

    public function testTheOtherEventsReadTheirLabelsOfTheTime(): void
    {
        $this->component->entry = Entry::created($this->issue, $this->claire);
        self::assertSame('a créé la demande', $this->component->getLabel());

        $this->component->entry = Entry::attachmentAdded($this->issue, $this->claire, new \App\Entity\Attachment($this->issue->getProject(), $this->issue, $this->claire, 'capture.png', 'image/png', 12));
        self::assertSame('a ajouté le fichier capture.png', $this->component->getLabel());
    }
}
