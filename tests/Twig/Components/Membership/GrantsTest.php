<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Membership;

use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene: Thomas manages Maison Dupont, Claire is its client, Paul
 * reads it; Nadia develops on the shop only, Sophie is Travelia's client.
 */
final class GrantsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use MailerAssertionsTrait;

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
        self::assertStringContainsString('Travelia', $render, 'the picker lists her with her company');
        $render = (string) $component->call('chooseUser', ['user' => $sophie->getId()])->render();
        self::assertStringContainsString('aria-expanded="false" aria-haspopup="listbox"', $render);
        self::assertStringContainsString('Sophie Bernard</span>', $render, 'the picker shows the choice');
        self::assertStringNotContainsString('<option value="developer"', explode('data-model="newRole"', $render)[1], 'no internal role offered to an external user');

        $component->set('newRole', 'client')->call('grant');

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame('client', $this->roleOf($sophie, 'MD'));
    }

    public function testARefusalIsShownInPlace(): void
    {
        $component = $this->onWorkspace();
        $sophie = $this->user('sophie.bernard@travelia.test');

        $component->call('chooseUser', ['user' => $sophie->getId()])->set('newRole', 'developer')->call('grant');

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

        $render = (string) $component->call('chooseTarget', ['target' => 'project-'.$this->scene->intranet->getId()])->render();
        self::assertStringContainsString('MD-INT · Intranet</span>', $render, 'the picker shows the choice');
        $component->set('newRole', 'developer')->call('grant');

        self::assertCount(2, self::getContainer()->get(MembershipRepository::class)->findByUser($nadia));
    }

    public function testTheManagerOfAProjectWidensAnInheritedRoleOnIt(): void
    {
        $paul = $this->user('paul.roussel@maison-dupont.test');
        $component = $this->onProject('thomas.girard@cairn.test');
        $render = (string) $component->render();
        self::assertStringContainsString('Paul Roussel', $render, 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $render, 'held on the shop');

        $render = (string) $component->set('email', 'Paul.Roussel@maison-dupont.test')->render();
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringContainsString('<option value="client"', $offered);
        self::assertStringNotContainsString('<option value="client_readonly"', $offered, 'what he inherits widens nothing');

        $component->set('newRole', 'client')->call('grant');

        $membership = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $paul, 'project' => $this->scene->shop]);
        self::assertSame('client', $membership?->getRole());
        self::assertStringContainsString('inherits &quot;Client (read-only)&quot;', (string) $component->render());
    }

    public function testOnAProjectTheAddressTypedSaysWhatItLeadsTo(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test');

        $render = (string) $component->set('email', 'nadia.benali@cairn.test')->render();
        self::assertStringContainsString('already holds a role on this project', $render);
        self::assertStringNotContainsString('data-model="newRole"', $render);

        $render = (string) $component->set('email', 'claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('already inherits the broadest role', $render);

        $render = (string) $component->set('email', 'sophie.bernard@travelia.test')->render();
        self::assertStringContainsString('Sophie Bernard', $render);
        self::assertStringContainsString('Travelia', $render);
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringNotContainsString('<option value="developer"', $offered, 'external');
        $component->set('newRole', 'client')->call('grant');
        self::assertSame('client', self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $this->user('sophie.bernard@travelia.test'), 'project' => $this->scene->shop])?->getRole());

        self::assertStringNotContainsString('data-model="newRole"', (string) $component->render(), 'the form is emptied');

        $render = (string) $component->set('email', 'paul@')->render();
        self::assertStringNotContainsString('data-model="firstName"', $render, 'what CreateUser would refuse is no new account');
        self::assertStringNotContainsString('data-model="newRole"', $render);
    }

    public function testOnAProjectAnUnknownAddressGetsAnExternalAccountARoleAndAnInvitation(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test');

        $render = (string) $component->set('email', 'remi.colin@natura.test')->render();
        self::assertStringContainsString('an external account will be created', $render);
        self::assertStringContainsString('data-model="norender|firstName"', $render);
        self::assertStringContainsString('Maison Dupont', explode('data-controller="combobox"', $render)[1], 'the companies known are suggested');
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringNotContainsString('<option value="developer"', $offered, 'a new account is external');

        $component->set('firstName', 'Rémi')->set('lastName', 'Colin')->set('company', 'Natura')->set('newRole', 'client')->call('invite');

        $remi = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi.colin@natura.test']);
        self::assertNotNull($remi);
        self::assertFalse($remi->isInternal());
        self::assertFalse($remi->hasPassword());
        self::assertSame('Natura', $remi->getCompany());
        self::assertSame('client', self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $remi, 'project' => $this->scene->shop])?->getRole());
        self::assertEmailCount(1);
        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));

        $render = (string) $component->render();
        self::assertStringContainsString('Rémi Colin', $render);
        self::assertStringContainsString('Invited', $render, 'waiting for his password');
        self::assertStringContainsString('data-live-action-param="resend"', $render);

        $component->call('resend', ['user' => $remi->getId()]);
        $rendered = $component->render();
        self::assertStringNotContainsString('role="alert"', (string) $rendered);
        self::assertNotNull($component->getDispatchedBrowserEvent($rendered, 'toast:show'), 'invited again');
    }

    public function testOnAProjectAnInvitationNeedsANameAndTheRight(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test');
        $component->set('email', 'remi.colin@natura.test')->set('newRole', 'client')->call('invite');
        self::assertStringContainsString('first name and a last name', (string) $component->render());
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi.colin@natura.test']));

        $component = $this->onProject('nadia.benali@cairn.test');
        $component->set('email', 'remi.colin@natura.test')->set('firstName', 'Rémi')->set('lastName', 'Colin')->set('newRole', 'client')->call('invite');
        self::assertStringContainsString('role="alert"', (string) $component->render());
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi.colin@natura.test']), 'a developer creates nothing');
        self::assertEmailCount(0);
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

    public function testOnAProjectTheManagerDesignatesTheReferentOfARole(): void
    {
        $memberships = self::getContainer()->get(MembershipRepository::class);
        $nadia = $memberships->findOneBy(['user' => $this->user('nadia.benali@cairn.test'), 'project' => $this->scene->shop]);
        $lea = $memberships->findOneBy(['user' => $this->user('lea.fontaine@cairn.test'), 'project' => $this->scene->shop]);
        self::assertNotNull($nadia);
        self::assertNotNull($lea);
        $component = $this->onProject('thomas.girard@cairn.test');
        self::assertStringNotContainsString('>Referent<', (string) $component->render(), 'nobody is a referent by default');

        // each call is a request of its own: the memberships are read again after it
        $isReferent = static fn (int $id): bool => (bool) self::getContainer()->get(MembershipRepository::class)->find($id)?->isReferent();
        [$nadiaId, $leaId] = [(int) $nadia->getId(), (int) $lea->getId()];

        $component->call('setReferent', ['membership' => $nadiaId, 'referent' => 1]);
        self::assertTrue($isReferent($nadiaId));
        self::assertSame(1, substr_count((string) $component->render(), 'Referent</span>'));

        $component->call('setReferent', ['membership' => $leaId, 'referent' => 1]);
        self::assertFalse($isReferent($nadiaId), 'one referent per role');
        self::assertTrue($isReferent($leaId));

        $component->call('setReferent', ['membership' => $leaId, 'referent' => 0]);
        self::assertFalse($isReferent($leaId));
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
