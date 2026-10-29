<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * On MD-SPT-4: Thomas manages the Maison Dupont workspace, Claire is its
 * client, Sophie a client of another workspace.
 */
final class PropertiesTest extends KernelTestCase
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
        self::assertStringNotContainsString('Visibilité', $client, 'not even shown to external users');
    }

    public function testAManagerAssignsTheIssue(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->set('assigneeId', $lea->getId())->call('change', ['property' => 'assignee']);
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'toast:show'));
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    public function testThePanelFollowsAChangeMadeElsewhere(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');
        $claire = $this->user('claire.martin@maison-dupont.test');
        self::getContainer()->get(CommandBus::class)->dispatch(new ApplyTransition((int) $claire->getId(), 'MD-SPT-4', 'accept'));

        $component->emit('issue:changed');

        self::assertStringContainsString('Fermée', (string) $component->render());
    }

    public function testSomeoneOutsideTheProjectCannotBeAssigned(): void
    {
        $sophie = $this->user('sophie.bernard@travelia.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->set('assigneeId', $sophie->getId())->call('change', ['property' => 'assignee']);

        self::assertStringContainsString('pas pu être appliquée', (string) $component->render());
        self::assertNotSame('sophie.bernard@travelia.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    private function properties(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Properties', ['issue' => $this->issue])->actingAs($this->user($email));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]) ?? self::fail("no $email");
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
