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
        $builder->issue($tracker, $author, 'Nouvelle');
        $builder->issue($tracker, $author, 'En recette', place: 'to_test');
        $builder->issue($tracker, $author, 'Bloquante', criticality: Criticality::Blocking);
        $builder->issue($tracker, $author, 'Terminée', place: 'done', criticality: Criticality::Blocking);
        $builder->issue($tracker, $author, 'Privée', place: 'to_test', private: true);
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
        ProjectFactory::createOne(['name' => 'A calme']);
        $old = ProjectFactory::createOne(['name' => 'B ancien']);
        $busy = ProjectFactory::createOne(['name' => 'C actif']);
        $archived = ProjectFactory::createOne(['name' => 'D archivé']);
        $inArchivedWorkspace = ProjectFactory::createOne(['name' => 'E espace archivé', 'workspace' => WorkspaceFactory::createOne()]);

        self::mockTime('2026-10-01 10:00');
        $builder->issue(TrackerFactory::createOne(['project' => $old]), $viewer, 'Ancienne');
        self::mockTime('2026-10-02 10:00');
        $builder->issue(TrackerFactory::createOne(['project' => $busy]), $viewer, 'Récente');
        $builder->issue(TrackerFactory::createOne(['project' => $archived]), $viewer, 'Archivée');
        $archived->archive();
        $inArchivedWorkspace->getWorkspace()->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $names = static fn (array $summaries): array => array_map(static fn (ProjectSummary $summary): string => $summary->project->getName(), $summaries);
        self::assertSame(['C actif', 'B ancien', 'A calme'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, recent: 8))));
        self::assertSame(['C actif', 'B ancien'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, recent: 2))));
        self::assertContains('D archivé', $names(self::queryBus()->ask(new ListVisibleProjects($viewer))), 'the full list keeps the archived ones');
        self::assertSame(['B ancien'], $names(self::queryBus()->ask(new ListVisibleProjects($viewer, q: 'ancien'))));
    }
}
