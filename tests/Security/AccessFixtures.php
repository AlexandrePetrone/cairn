<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Tests\Config\Fixture\DefinitionFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One client workspace with two projects, persisted on demand. Users are
 * created per test so that each test states its own memberships.
 */
final class AccessFixtures
{
    public Workspace $workspace;
    public Project $shop;
    public Project $intranet;
    public Workspace $otherWorkspace;
    public Project $otherProject;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->workspace = new Workspace('Maison Dupont', 'MD');
        $this->shop = new Project($this->workspace, 'Online shop', 'SPT');
        $this->intranet = new Project($this->workspace, 'Intranet', 'INT');
        $this->otherWorkspace = new Workspace('Vallier Group', 'GV');
        $this->otherProject = new Project($this->otherWorkspace, 'Portal', 'PRT');

        foreach ([$this->workspace, $this->shop, $this->intranet, $this->otherWorkspace, $this->otherProject] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    public function user(string $email, bool $internal = false, bool $admin = false): User
    {
        $user = new User($email, ucfirst(strstr($email, '@', true) ?: $email), 'Test', $internal);
        if ($admin) {
            $user->makeInternal(admin: true);
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function grantOnWorkspace(User $user, Workspace $workspace, string $role): Membership
    {
        $this->entityManager->persist($membership = Membership::onWorkspace($user, $workspace, DefinitionFactory::roleRow($role, $this->entityManager)));
        $this->entityManager->flush();

        return $membership;
    }

    public function grantOnProject(User $user, Project $project, string $role): Membership
    {
        $this->entityManager->persist($membership = Membership::onProject($user, $project, DefinitionFactory::roleRow($role, $this->entityManager)));
        $this->entityManager->flush();

        return $membership;
    }
}
