<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Project;

use App\Entity\Enum\Criticality;
use App\Entity\Project;
use App\Entity\User;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Factory\WorkspaceFactory;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Security\AccessControl;
use App\Story\AppStory;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Query\Issue\IssueBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Test workflow `bug`: new is open, to_test is waiting, done is closed.
 */
final class ListVisibleProjectsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use KernelHelpers;

    public function testSqlAndPhpAgreeOnTheWholeDevelopmentDataset(): void
    {
        self::bootKernel();
        AppStory::load();
        $access = self::getContainer()->get(AccessControl::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $projects = $entityManager->getRepository(Project::class)->findAll();

        $partialViews = 0;
        foreach ($entityManager->getRepository(User::class)->findAll() as $user) {
            $expected = array_map(static fn (Project $project): ?int => $project->getId(), array_values(array_filter($projects, static fn (Project $project): bool => $access->canView($user, $project))));
            sort($expected);
            $actual = array_map(static fn (ProjectSummary $summary): ?int => $summary->project->getId(), self::queryBus()->ask(new ListVisibleProjects($user)));
            sort($actual);

            self::assertSame($expected, $actual, $user->getEmail());
            if ([] !== $expected && \count($expected) < \count($projects)) {
                ++$partialViews;
            }
        }
        self::assertGreaterThanOrEqual(3, $partialViews, 'the dataset must exercise partial visibility');
    }

    public function testCountsTheOpenIssuesTheViewerSees(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $builder = new IssueBuilder(self::getContainer()->get(EntityManagerInterface::class));
        $project = ProjectFactory::createOne();
        $tracker = TrackerFactory::createOne(['project' => $project]);
        $author = UserFactory::new()->internal()->create();
        $builder->issue($tracker, $author, 'Fresh');
        $builder->issue($tracker, $author, 'To test', place: 'to_test');
        $builder->issue($tracker, $author, 'Blocking', criticality: Criticality::Blocking);
        $builder->issue($tracker, $author, 'Finished', place: 'done', criticality: Criticality::Blocking);
        $builder->issue($tracker, $author, 'Private', place: 'to_test', private: true);
        $client = UserFactory::createOne();
        MembershipFactory::createOne(['user' => $client, 'project' => $project, 'role' => 'client']);

        self::assertEquals([new ProjectSummary($project, open: 4, waiting: 2, blocking: 1)], self::queryBus()->ask(new ListVisibleProjects($author)));
        self::assertEquals([new ProjectSummary($project, open: 3, waiting: 1, blocking: 1)], self::queryBus()->ask(new ListVisibleProjects($client)), 'private issues are not counted');
    }

    public function testRecentKeepsTheActiveProjectsThatMovedLast(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $builder = new IssueBuilder(self::getContainer()->get(EntityManagerInterface::class));
        $viewer = UserFactory::new()->internal()->create();
        // Names and keys are fixed: the search below matches the workspace too.
        $workspace = WorkspaceFactory::createOne(['name' => 'Agency', 'key' => 'AGY']);
        ProjectFactory::createOne(['name' => 'A quiet', 'key' => 'QUI', 'workspace' => $workspace]);
        $old = ProjectFactory::createOne(['name' => 'B old', 'key' => 'BOL', 'workspace' => $workspace]);
        $busy = ProjectFactory::createOne(['name' => 'C busy', 'key' => 'BUS', 'workspace' => $workspace]);
        $archived = ProjectFactory::createOne(['name' => 'D archived', 'key' => 'ARC', 'workspace' => $workspace]);
        $inArchivedWorkspace = ProjectFactory::createOne(['name' => 'E archived workspace', 'key' => 'EAW', 'workspace' => WorkspaceFactory::createOne(['name' => 'Former client', 'key' => 'FOR'])]);

        self::mockTime('2026-10-01 10:00');
        $builder->issue(TrackerFactory::createOne(['project' => $old]), $viewer, 'Old');
        self::mockTime('2026-10-02 10:00');
        $builder->issue(TrackerFactory::createOne(['project' => $busy]), $viewer, 'Recent');
        $builder->issue(TrackerFactory::createOne(['project' => $archived]), $viewer, 'Archived');
        $archived->archive();
        $inArchivedWorkspace->getWorkspace()->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $names = static fn (array $summaries): array => array_map(static fn (ProjectSummary $summary): string => $summary->project->getName(), $summaries);
        self::assertSame(['C busy', 'B old', 'A quiet'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, recent: 8))));
        self::assertSame(['C busy', 'B old'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, recent: 2))));
        self::assertContains('D archived', $names(self::queryBus()->ask(new ListVisibleProjects($viewer))), 'the full list keeps the archived ones');
        self::assertSame(['B old'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, q: 'old'))));
    }
}
