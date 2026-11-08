<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Membership;

use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene: Thomas manages Maison Dupont, Claire is its client, Paul
 * reads it; Nadia develops on the shop only, Sophie is Travelia's client.
 */
final class GrantsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheMembersOfAWorkspaceAreListedAndOneIsAdded(): void
    {
        $component = $this->onWorkspace();
        $render = (string) $component->render();
        self::assertStringContainsString('Thomas Girard', $render);
        self::assertStringContainsString('Paul Roussel', $render);

        $sophie = $this->user('sophie.bernard@travelia.test');
        $render = (string) $component->set('newUserId', $sophie->getId())->render();
        self::assertStringNotContainsString('<option value="developer"', explode('data-model="newRole"', $render)[1], 'no internal role offered to an external user');

        $component->set('newRole', 'client')->call('grant');

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame('client', $this->roleOf($sophie, 'MD'));
    }

    public function testARefusalIsShownInPlace(): void
    {
        $component = $this->onWorkspace();
        $sophie = $this->user('sophie.bernard@travelia.test');

        $component->set('newUserId', $sophie->getId())->set('newRole', 'developer')->call('grant');

        self::assertStringContainsString('is for internal users only', (string) $component->render());
        self::assertNull($this->roleOf($sophie, 'MD'));
    }

    public function testARoleIsChangedThenRevokedAfterAConfirmation(): void
    {
        $paul = $this->user('paul.roussel@maison-dupont.test');
        $membership = self::getContainer()->get(MembershipRepository::class)->findByUser($paul)[0];
        $component = $this->onWorkspace();

        $component->set('roles.'.$membership->getId(), 'client')->call('changeRole', ['membership' => $membership->getId()]);
        self::assertSame('client', $this->roleOf($paul, 'MD'));

        $component->call('confirmRevoke', ['membership' => $membership->getId()]);
        self::assertStringContainsString('Revoke this access?', (string) $component->render());
        $component->call('revoke');

        self::assertNull($this->roleOf($paul, 'MD'));
    }

    public function testAUserGetsARoleOnAProject(): void
    {
        $nadia = $this->user('nadia.benali@cairn.test');
        $component = $this->createLiveComponent('Membership:Grants', ['user' => $nadia])->actingAs($this->user('julie.admin@cairn.test'));
        self::assertStringContainsString('MD-SPT', (string) $component->render());

        $component->set('newTarget', 'project-'.$this->scene->intranet->getId())->set('newRole', 'developer')->call('grant');

        self::assertCount(2, self::getContainer()->get(MembershipRepository::class)->findByUser($nadia));
    }

    public function testTheManagerOfAProjectWidensAnInheritedRoleOnIt(): void
    {
        $paul = $this->user('paul.roussel@maison-dupont.test');
        $component = $this->onProject('thomas.girard@cairn.test');
        $render = (string) $component->render();
        self::assertStringContainsString('Paul Roussel', $render, 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $render, 'held on the shop');

        $render = (string) $component->set('newUserId', $paul->getId())->render();
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringContainsString('<option value="client"', $offered);
        self::assertStringNotContainsString('<option value="client_readonly"', $offered, 'what he inherits widens nothing');

        $component->set('newRole', 'client')->call('grant');

        $membership = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $paul, 'project' => $this->scene->shop]);
        self::assertSame('client', $membership?->getRole());
        self::assertStringContainsString('inherits &quot;Client (read-only)&quot;', (string) $component->render());
    }

    public function testOnAProjectTheViewerActsWithTheirOwnRights(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $membership = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $lea, 'project' => $this->scene->shop]);
        self::assertNotNull($membership);
        $component = $this->onProject('nadia.benali@cairn.test');

        $component->call('confirmRevoke', ['membership' => $membership->getId()])->call('revoke');

        self::assertStringContainsString('role="alert"', (string) $component->render());
        self::assertNotNull(self::getContainer()->get(MembershipRepository::class)->find((int) $membership->getId()), 'a developer revokes nothing');
    }

    private function onProject(string $viewer): TestLiveComponent
    {
        return $this->createLiveComponent('Membership:Grants', ['project' => $this->scene->shop])->actingAs($this->user($viewer));
    }

    private function onWorkspace(): TestLiveComponent
    {
        return $this->createLiveComponent('Membership:Grants', ['workspace' => $this->scene->dupont])->actingAs($this->user('julie.admin@cairn.test'));
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }

    private function roleOf(User $user, string $workspaceKey): ?string
    {
        foreach (self::getContainer()->get(MembershipRepository::class)->findByUser($user) as $membership) {
            if ($membership->getWorkspace()?->getKey() === $workspaceKey) {
                return $membership->getRole();
            }
        }

        return null;
    }
}
