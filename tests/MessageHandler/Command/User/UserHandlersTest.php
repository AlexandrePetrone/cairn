<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Repository\MembershipRepository;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * UpdateUser. Test roles: project_manager and developer are internal
 * only, client is not.
 */
final class UserHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testOnlyWhatIsGivenChanges(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), lastName: 'Dupont'));

        self::assertSame('Claire Dupont', $claire->getFullName());
        self::assertSame('claire@client.test', $claire->getEmail());
        self::assertFalse($claire->isInternal());
        self::assertTrue($claire->isActive());
    }

    public function testTheEmailStaysUnique(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->user('paul@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), email: 'Claire.Dupont@Client.test'));
        self::assertSame('claire.dupont@client.test', $claire->getEmail());

        $this->expectException(UserException::class);
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), email: 'PAUL@client.test'));
    }

    public function testAnInternalUserBecomesExternalOnlyWithoutInternalRoles(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($nadia, $this->fixtures->workspace, 'developer');

        try {
            $this->commands->dispatch(new UpdateUser((int) $nadia->getId(), firstName: 'Nadia B.', internal: false));
            self::fail('A developer cannot become external.');
        } catch (UserException $e) {
            self::assertStringContainsString('Maison Dupont', $e->getMessage());
        }
        self::assertTrue($nadia->isInternal());
        self::assertSame('Nadia', $nadia->getFirstName(), 'nothing changes when one rule refuses');

        self::getContainer()->get(MembershipRepository::class)->findByUser($nadia)[0]->changeRole('client');
        $this->commands->dispatch(new UpdateUser((int) $nadia->getId(), internal: false));
        self::assertFalse($nadia->isInternal());
    }

    public function testAnAdministratorIsInternal(): void
    {
        $this->fixtures->user('admin@agency.test', admin: true);
        $claire = $this->fixtures->user('claire@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), admin: true));
        self::assertTrue($claire->isAdmin());
        self::assertTrue($claire->isInternal());

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), internal: false));
        self::assertFalse($claire->isAdmin(), 'an external user is no administrator');

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), internal: false, admin: true));
    }

    public function testTheLastActiveAdministratorStaysOne(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        foreach ([new UpdateUser((int) $admin->getId(), admin: false), new UpdateUser((int) $admin->getId(), active: false)] as $command) {
            try {
                $this->commands->dispatch($command);
                self::fail('The last administrator must remain.');
            } catch (UserException $e) {
                self::assertStringContainsString('last active administrator', $e->getMessage());
            }
        }

        $this->fixtures->user('other-admin@agency.test', admin: true);
        $this->commands->dispatch(new UpdateUser((int) $admin->getId(), active: false));
        self::assertFalse($admin->isActive());
    }

    public function testADeactivatedAccountKeepsItsMemberships(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), active: false));
        self::assertFalse($claire->isActive());
        self::assertCount(1, self::getContainer()->get(MembershipRepository::class)->findByUser($claire));

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), active: true));
        self::assertTrue($claire->isActive());
    }

    public function testAnAdministratorSetsThePassword(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), plainPassword: 'a password'));

        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($claire, 'a password'));
    }
}
