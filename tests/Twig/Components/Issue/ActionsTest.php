<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * MD-SPT-4 waits on « À recetter »: the client accepts or refuses, a
 * refusal needs a comment.
 */
final class ActionsTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
        $this->issue = self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4') ?? self::fail('no MD-SPT-4');
    }

    public function testTheActionsAreThoseOfTheViewersRole(): void
    {
        $client = (string) $this->actions('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('Valider', $client);
        self::assertStringContainsString('Refuser', $client);
        self::assertStringNotContainsString('Commenter en privé', $client);

        $developer = (string) $this->actions('nadia.benali@cairn.test')->render();
        self::assertStringNotContainsString('Valider', $developer, 'acceptance is for the client and the manager');
        self::assertStringContainsString('Commenter', $developer);

        $readOnly = (string) $this->actions('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('Commenter', $readOnly);
        self::assertStringNotContainsString('Valider', $readOnly);
    }

    public function testARefusalNeedsItsComment(): void
    {
        $component = $this->actions('claire.martin@maison-dupont.test');

        $component->call('apply', ['transition' => 'refuse']);
        self::assertStringContainsString('un commentaire est obligatoire pour refuser', (string) $component->render());
        self::assertSame('to_test', $this->reloaded()->getPlace());

        $component->set('body', 'Toujours vide en production.')->call('apply', ['transition' => 'refuse']);
        self::assertSame(302, $component->response()->getStatusCode());
        self::assertSame('/MD/SPT/issues/4', $component->response()->headers->get('Location'));

        self::assertSame('in_progress', $this->reloaded()->getPlace());
        $entry = self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Toujours vide en production.', $entry?->getComment()?->getBody());
    }

    public function testAClientCommentIsPublicWhateverTheFlag(): void
    {
        $component = $this->actions('claire.martin@maison-dupont.test');

        $component->set('body', 'Merci, je regarde demain.')->set('private', true)->call('postComment');

        self::assertSame(302, $component->response()->getStatusCode());
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Merci, je regarde demain.', $comment?->getBody());
        self::assertFalse($comment->isPrivate());
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $component = $this->actions('nadia.benali@cairn.test');

        $component->set('body', '   ')->call('postComment');

        self::assertStringContainsString('Le commentaire est vide.', (string) $component->render());
    }

    private function actions(string $email): TestLiveComponent
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $this->createLiveComponent('Issue:Actions', ['issue' => $this->issue])->actingAs($user);
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
