<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Config\ConfigRegistry;
use App\Entity\User;
use App\Message\Command\User\UpdateUser;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The rules hold on the account as the command leaves it: the email stays
 * unique, an external user keeps no internal-only role (the change is
 * refused while they hold one, the administrator decides what to grant
 * instead), the last active administrator stays one.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UpdateUserHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(UpdateUser $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);

        $admin = $command->admin ?? ($user->isAdmin() && false !== $command->internal);
        $internal = $admin || ($command->internal ?? $user->isInternal());
        $active = $command->active ?? $user->isActive();

        if (null !== $command->email) {
            $owner = $this->users->loadUserByIdentifier($command->email);
            if (null !== $owner && $owner->getId() !== $user->getId()) {
                throw UserException::emailTaken($owner->getEmail());
            }
        }
        if ($user->isAdmin() && $user->isActive() && (!$admin || !$active) && $this->users->countActiveAdministrators() <= 1) {
            throw UserException::lastAdministrator();
        }
        if ($user->isInternal() && !$internal) {
            $this->assertNoInternalRole($user);
        }

        if (null !== $command->email) {
            $user->changeEmail($command->email);
        }
        if (null !== $command->firstName || null !== $command->lastName) {
            $user->rename($command->firstName ?? $user->getFirstName(), $command->lastName ?? $user->getLastName());
        }
        $internal ? $user->makeInternal($admin) : $user->makeExternal();
        $active ? $user->reactivate() : $user->deactivate();
        if (null !== $command->plainPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
        }
    }

    private function assertNoInternalRole(User $user): void
    {
        $definition = $this->config->definition();
        $where = [];
        foreach ($this->memberships->findByUser($user) as $membership) {
            if ($definition->hasRole($membership->getRole()) && $definition->role($membership->getRole())->internalOnly) {
                $where[] = $membership->getProject()?->getName() ?? $membership->getWorkspace()?->getName() ?? '';
            }
        }
        if ([] !== $where) {
            throw UserException::holdsInternalRoles($user->getFullName(), $where);
        }
    }
}
