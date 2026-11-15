<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Entity\IssueSubscription;
use App\Entity\Membership;
use App\Entity\User;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\DeleteUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DeleteUser: only an account that left no trace goes.
 */
final class DeleteUserTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    public function testAnAccountThatLeftNoTraceIsDeletedWithItsAccessAndWhatItFollows(): void
    {
        $paul = $this->fixtures->access->user('paul@client.test');
        $this->fixtures->access->grantOnProject($paul, $this->fixtures->access->shop, 'client');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new FollowIssue((int) $paul->getId(), $issue->getKey()));
        $paulId = (int) $paul->getId();

        $this->commands->dispatch(new DeleteUser($paulId));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(User::class)->find($paulId));
        self::assertSame(0, $this->entityManager->getRepository(Membership::class)->count(['user' => $paulId]));
        self::assertSame(0, $this->entityManager->getRepository(IssueSubscription::class)->count(['user' => $paulId]));
        self::assertInstanceOf(User::class, $this->commands->dispatch(new CreateUser('paul@client.test', 'Paul', 'Petit')), 'the address is free again');
    }

    public function testAnAccountThatWroteAnIssueIsDeactivatedNotDeleted(): void
    {
        $this->fixtures->createIssue($this->fixtures->client);

        $this->expectExceptionObject(UserException::hasHistory($this->fixtures->client->getFullName()));
        $this->commands->dispatch(new DeleteUser((int) $this->fixtures->client->getId()));
    }

    public function testTheLastActiveAdministratorIsNotDeleted(): void
    {
        $admin = $this->fixtures->access->user('root@agency.test', internal: true, admin: true);

        $this->expectExceptionObject(UserException::lastAdministrator());
        $this->commands->dispatch(new DeleteUser((int) $admin->getId()));
    }
}
