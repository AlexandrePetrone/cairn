<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * On MD-SPT-4 of the scene, a "Bug" on "To test": Thomas manages the
 * Maison Dupont workspace, so may accept and refuse; like Nadia, developer,
 * he may force a place and change the tracker. Claire is the client,
 * Sophie a client of another workspace.
 */
final class PropertiesTest extends KernelTestCase
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

    public function testEachPropertyIsAChoiceForWhoeverMayChangeIt(): void
    {
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringContainsString('data-live-property-param="assignee"', $manager);
        self::assertStringContainsString('data-live-property-param="criticality"', $manager);
        self::assertStringContainsString('data-live-property-param="visibility"', $manager);

        $client = (string) $this->properties('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('data-live-property-param="criticality"', $client);
        self::assertStringNotContainsString('data-live-property-param="assignee"', $client);
        self::assertStringNotContainsString('data-live-property-param="visibility"', $client);
        self::assertStringNotContainsString('Visibility', $client, 'not even shown to external users');
    }

    public function testAManagerAssignsTheIssue(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()]);
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'toast:show'));
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    public function testTheMenuOffersTheMembersOfTheProjectAndNobody(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $sophie = $this->user('sophie.bernard@travelia.test');
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();

        self::assertStringContainsString('data-live-value-param="'.$lea->getId().'"', $manager);
        self::assertStringNotContainsString('data-live-value-param="'.$sophie->getId().'"', $manager, 'not a member of the project');
        self::assertStringContainsString('data-live-property-param="assignee" data-live-value-param=""', $manager, 'nobody');
    }

    public function testThePanelFollowsAChangeMadeElsewhere(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');
        $claire = $this->user('claire.martin@maison-dupont.test');
        self::getContainer()->get(CommandBus::class)->dispatch(new ApplyTransition((int) $claire->getId(), 'MD-SPT-4', 'accept'));

        $component->emit('issue:changed');

        self::assertStringContainsString('To release', (string) $component->render());
    }

    public function testTheReleaseShowsOnceTheProjectHasReleases(): void
    {
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->properties('thomas.girard@cairn.test')->render());

        $release = $this->scene->release();
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->properties('claire.martin@maison-dupont.test')->render(), 'a client reads it');
        $component = $this->properties('nadia.benali@cairn.test');
        self::assertStringContainsString('data-live-property-param="release"', (string) $component->render());

        $component->call('change', ['property' => 'release', 'value' => (string) $release->getId()]);
        self::assertSame('2.5.0', $this->reloaded()->getRelease()?->getName());
        self::assertStringContainsString('2.5.0', (string) $this->properties('claire.martin@maison-dupont.test')->render());
    }

    public function testSomeoneOutsideTheProjectCannotBeAssigned(): void
    {
        $sophie = $this->user('sophie.bernard@travelia.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('change', ['property' => 'assignee', 'value' => (string) $sophie->getId()]);

        self::assertStringContainsString('could not be applied', (string) $component->render());
        self::assertNotSame('sophie.bernard@travelia.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    public function testTheStepIsAMenuOfWhereTheViewerMayTakeTheIssue(): void
    {
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringContainsString('data-live-action-param="transition" data-live-id-param="accept"', $manager, 'an action leads to "To release"');
        self::assertStringContainsString('data-live-action-param="transition" data-live-id-param="refuse"', $manager);
        self::assertStringContainsString('data-live-action-param="move" data-live-place-param="qualified">', $manager, 'no action leads there: outside the workflow');
        self::assertStringNotContainsString('data-live-action-param="move" data-live-place-param="to_release">', $manager, 'the workflow first');
        self::assertStringNotContainsString('data-live-place-param="to_test">', $manager, 'the current place is not a move');

        $developer = (string) $this->properties('nadia.benali@cairn.test')->render();
        self::assertStringNotContainsString('data-live-action-param="transition"', $developer, 'no action of hers from "To test"');
        self::assertStringContainsString('data-live-action-param="move" data-live-place-param="to_release">', $developer, 'accepting is not hers: outside the workflow');
        self::assertStringContainsString('Change category…', $developer);
        self::assertStringContainsString(\sprintf('data-live-place-param="to_estimate" data-live-tracker-param="%d"', $this->scene->evolutions->getId()), $developer);
        self::assertStringContainsString('Internal task', $developer, 'private, but Nadia is internal');

        $client = (string) $this->properties('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('data-live-action-param="transition" data-live-id-param="accept"', $client, 'her action leads to "To release"');
        self::assertStringNotContainsString('data-live-id-param="refuse"', $client, 'refusing asks her for a comment: from the response box');
        self::assertStringNotContainsString('data-live-action-param="move"', $client, 'she does not leave the workflow');
        self::assertStringNotContainsString('Change category…', $client);
    }

    public function testAnActionOfTheWorkflowIsAppliedFromTheMenu(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('transition', ['id' => 'refuse']);
        $render = $component->render();

        self::assertSame('in_progress', $this->reloaded()->getPlace(), 'an internal user is not held to the comment');
        self::assertSame(JournalEntryType::Transitioned, $this->lastEntry()?->getType());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4: "Refuse" applied.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testAPlaceNoActionLeadsToIsForced(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('move', ['place' => 'qualified']);
        $render = $component->render();

        self::assertSame('qualified', $this->reloaded()->getPlace());
        self::assertSame(JournalEntryType::Moved, $this->lastEntry()?->getType());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4 moved to Qualified.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testForcingAPlaceTakesTheRight(): void
    {
        $component = $this->properties('claire.martin@maison-dupont.test');

        $component->call('move', ['place' => 'qualified']);

        self::assertStringContainsString('could not be applied', (string) $component->render());
        self::assertSame('to_test', $this->reloaded()->getPlace());
    }

    public function testTheMenuGivesAClientHerActionsAndNothingMore(): void
    {
        $component = $this->properties('claire.martin@maison-dupont.test');

        $component->call('transition', ['id' => 'refuse']);
        self::assertStringContainsString('could not be applied', (string) $component->render(), 'it asks her for a comment, which the response box takes');
        self::assertSame('to_test', $this->reloaded()->getPlace());

        $component->call('move', ['place' => 'to_estimate', 'tracker' => $this->scene->evolutions->getId()]);
        self::assertSame('Bug', $this->reloaded()->getTracker()->getName());

        $component->call('transition', ['id' => 'accept']);
        self::assertSame('to_release', $this->reloaded()->getPlace(), 'her action, from the menu as from the response box');
    }

    public function testTheTrackerChangesWithAPlaceOfTheNewWorkflow(): void
    {
        $component = $this->properties('nadia.benali@cairn.test');

        $component->call('move', ['place' => 'to_estimate', 'tracker' => $this->scene->evolutions->getId()]);

        $issue = $this->reloaded();
        self::assertSame('Feature', $issue->getTracker()->getName());
        self::assertSame('to_estimate', $issue->getPlace());
        self::assertSame(['message' => 'MD-SPT-4 moved to To estimate.'], $component->getDispatchedBrowserEvent($component->render(), 'toast:show')['payload'] ?? null);
    }

    public function testAnArchivedProjectOffersNoMove(): void
    {
        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $render = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringNotContainsString('data-controller="panels"', $render);
        self::assertStringContainsString('To test', $render);
    }

    private function lastEntry(): ?JournalEntry
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
    }

    private function properties(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Properties', ['issue' => $this->issue])->actingAs($this->user($email));
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
