<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\UserFactory;
use App\Message\Query\Project\ListProjectMembers;
use App\Security\AccessControl;
use App\Story\AppStory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The SQL of the handler must agree with AccessControl::roleOn() for
 * every project and every user.
 */
final class ListProjectMembersTest extends KernelTestCase
{
    use Factories;
    use KernelHelpers;

    public function testSqlAndPhpAgreeOnTheWholeDevelopmentDataset(): void
    {
        self::bootKernel();
        AppStory::load();
        $access = self::getContainer()->get(AccessControl::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $users = $entityManager->getRepository(User::class)->findAll();
        $projects = $entityManager->getRepository(Project::class)->findAll();
        self::assertNotEmpty($projects);

        foreach ($projects as $project) {
            $expected = self::ids(...array_filter($users, static fn (User $user): bool => $user->isActive() && null !== $access->roleOn($user, $project)));

            self::assertSame($expected, self::ids(...self::queryBus()->ask(new ListProjectMembers($project))), $project->getIssuePrefix());
            self::assertGreaterThanOrEqual(3, \count($expected), $project->getIssuePrefix().' has a manager, a developer and a client');
            self::assertLessThan(\count($users), \count($expected), $project->getIssuePrefix().' does not list everyone');
        }
    }

    public function testMembersComeFromTheProjectAndItsWorkspaceWithAKnownRoleSortedByName(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $project = ProjectFactory::createOne();

        $direct = UserFactory::new()->internal()->create(['lastName' => 'Benali', 'firstName' => 'Nadia']);
        MembershipFactory::createOne(['user' => $direct, 'project' => $project, 'role' => 'developer']);
        $inherited = UserFactory::createOne(['lastName' => 'Martin', 'firstName' => 'Claire']);
        MembershipFactory::createOne(['user' => $inherited, 'workspace' => $project->getWorkspace(), 'role' => 'client']);
        $both = UserFactory::new()->internal()->create(['lastName' => 'Girard', 'firstName' => 'Thomas']);
        MembershipFactory::createOne(['user' => $both, 'workspace' => $project->getWorkspace(), 'role' => 'developer']);
        MembershipFactory::createOne(['user' => $both, 'project' => $project, 'role' => 'project_manager']);
        $former = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $former, 'project' => $project, 'role' => 'role_removed_from_configuration']);
        $elsewhere = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $elsewhere, 'project' => ProjectFactory::createOne(), 'role' => 'client']);
        $deactivated = UserFactory::createOne(['active' => false]);
        MembershipFactory::createOne(['user' => $deactivated, 'project' => $project, 'role' => 'client']);
        UserFactory::new()->internal()->create();

        $members = self::queryBus()->ask(new ListProjectMembers($project));

        self::assertSame(['Nadia Benali', 'Thomas Girard', 'Claire Martin'], array_map(static fn (User $user): string => $user->getFullName(), $members));
    }

    /**
     * @return list<int>
     */
    private static function ids(User ...$users): array
    {
        $ids = array_map(static fn (User $user): int => (int) $user->getId(), $users);
        sort($ids);

        return $ids;
    }
}
