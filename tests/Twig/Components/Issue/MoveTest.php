<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The « ⋯ › Déplacer… » menu on MD-SPT-4 of the scene (an « Anomalie »
 * on « À recetter »): Thomas, project manager, may force a place and
 * change the tracker; Nadia, developer, only the latter; clients neither.
 */
final class MoveTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testTheMenuShowsToWhoeverMayMoveTheIssue(): void
    {
        foreach (['claire.martin@maison-dupont.test' => false, 'paul.roussel@maison-dupont.test' => false, 'nadia.benali@cairn.test' => true, 'thomas.girard@cairn.test' => true] as $email => $expected) {
            $render = (string) $this->move($email)->render();
            self::assertSame($expected, str_contains($render, 'data-live-action-param="open"'), $email);
        }
    }

    public function testTheTrackersOfferedFollowTheRights(): void
    {
        $developer = (string) $this->move('nadia.benali@cairn.test')->call('open')->render();
        self::assertStringContainsString('role="dialog"', $developer);
        self::assertStringContainsString('Évolution', $developer);
        self::assertStringContainsString('Tâche interne', $developer, 'private, but Nadia is internal');
        self::assertStringNotContainsString('>Anomalie<', $developer, 'the current tracker needs force_place');
        self::assertStringContainsString('<option value="new" selected>Nouveau</option>', $developer, 'the initial place of the chosen workflow');

        $manager = (string) $this->move('thomas.girard@cairn.test')->call('open')->render();
        self::assertStringContainsString('>Anomalie<', $manager);
        self::assertStringContainsString('<option value="to_test" selected>À recetter</option>', $manager, 'the current place');
    }

    public function testAPlaceNoActionLeadsToIsForcedWithAWarning(): void
    {
        $component = $this->move('thomas.girard@cairn.test')->call('open');

        $component->set('placeId', 'done');
        self::assertStringNotContainsString('Aucune action ne mène', (string) $component->render(), '« Valider » leads there');

        $component->set('placeId', 'qualified');
        self::assertStringContainsString('Aucune action ne mène de À recetter à Qualifiée', (string) $component->render());

        $component->call('confirm');
        $render = $component->render();

        self::assertSame('qualified', $this->reloaded()->getPlace());
        self::assertStringNotContainsString('role="dialog"', (string) $render);
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4 déplacée vers Qualifiée.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
        $entry = self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame(JournalEntryType::Moved, $entry?->getType());
    }

    public function testTheChosenPlaceSurvivesTheTrackerBeingSentAgain(): void
    {
        // The browser sends the text value of the tracker select as updated on every request.
        $component = $this->move('thomas.girard@cairn.test')->call('open');
        $current = (string) $this->issue->getTracker()->getId();

        $component->set('trackerId', $current)->set('placeId', 'qualified')->set('trackerId', $current);
        $render = (string) $component->render();
        self::assertStringContainsString('<option value="qualified" selected>', $render);
        self::assertStringContainsString('Aucune action ne mène', $render);

        $component->set('trackerId', (string) $this->scene->evolutions->getId());
        self::assertStringContainsString('<option value="new" selected>Nouveau</option>', (string) $component->render(), 'another tracker starts from its first place');
    }

    public function testNothingIsOfferedWhileTheIssueStaysWhereItIs(): void
    {
        $component = $this->move('thomas.girard@cairn.test')->call('open');

        self::assertMatchesRegularExpression('/<button[^>]*disabled[^>]*data-live-action-param="confirm"/', (string) $component->render(), 'the current place is selected: nothing to move');
        $component->call('confirm');
        self::assertSame('to_test', $this->reloaded()->getPlace(), 'the handler refuses it all the same');

        $component->set('placeId', 'done');
        self::assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*data-live-action-param="confirm"/', (string) $component->render());
    }

    public function testTheTrackerChangesWithAPlaceOfTheNewWorkflow(): void
    {
        $component = $this->move('nadia.benali@cairn.test')->call('open');

        $component->set('trackerId', $this->scene->evolutions->getId());
        self::assertStringContainsString('À chiffrer', (string) $component->render());

        $component->set('placeId', 'to_estimate')->call('confirm');

        $issue = $this->reloaded();
        self::assertSame('Évolution', $issue->getTracker()->getName());
        self::assertSame('to_estimate', $issue->getPlace());
        self::assertSame(['message' => 'MD-SPT-4 déplacée vers À chiffrer.'], $component->getDispatchedBrowserEvent($component->render(), 'toast:show')['payload'] ?? null);
    }

    public function testAnArchivedProjectOffersNoMove(): void
    {
        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertStringNotContainsString('data-live-action-param="open"', (string) $this->move('thomas.girard@cairn.test')->render());
    }

    private function move(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Move', ['issue' => $this->issue])->actingAs($this->scene->user($email));
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
