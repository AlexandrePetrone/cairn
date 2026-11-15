<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Project;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Factory\ProjectFactory;
use App\Factory\UserFactory;
use App\Message\Query\Project\ListTrackers;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The privacy of trackers is decided here and nowhere else: an external
 * user never gets a private tracker, whoever asks.
 */
final class ListTrackersHandlerTest extends KernelTestCase
{
    use Factories;
    use KernelHelpers;

    private User $internal;
    private User $external;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->internal = UserFactory::new()->internal()->create();
        $this->external = UserFactory::createOne();
    }

    public function testAnExternalUserGetsThePublicActiveTrackersInDisplayOrder(): void
    {
        $project = $this->project();

        self::assertSame(['Bug', 'Feature'], $this->names($this->external, $project));
    }

    public function testAnInternalUserGetsThePrivateOnesToo(): void
    {
        $project = $this->project();

        self::assertSame(['Bug', 'Internal task', 'Feature'], $this->names($this->internal, $project));
    }

    public function testArchivedTrackersComeOnlyWhenAskedFor(): void
    {
        $project = $this->project();

        self::assertSame(['Bug', 'Feature', 'Support'], $this->names($this->external, $project, archived: true));
        self::assertSame(['Bug', 'Internal task', 'Feature', 'Support'], $this->names($this->internal, $project, archived: true));
    }

    public function testTheTrackersOfAnotherProjectStayOut(): void
    {
        $this->project();
        $other = ProjectFactory::createOne();

        self::assertSame([], $this->names($this->internal, $other, archived: true));
    }

    /**
     * Bug (public), Internal task (private), Feature (public) and
     * Support (public, archived), in that order.
     */
    private function project(): Project
    {
        $project = ProjectFactory::createOne();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tasks = new Tracker($project, 'Internal task', 'task', 1);
        $tasks->makePrivate();
        $support = new Tracker($project, 'Support', 'bug', 3);
        $support->archive();
        foreach ([new Tracker($project, 'Feature', 'evolution', 2), $tasks, new Tracker($project, 'Bug', 'bug', 0), $support] as $tracker) {
            $entityManager->persist($tracker);
        }
        $entityManager->flush();

        return $project;
    }

    /**
     * @return list<string>
     */
    private function names(User $viewer, Project $project, bool $archived = false): array
    {
        return array_map(static fn (Tracker $tracker): string => $tracker->getName(), self::queryBus()->ask(new ListTrackers($viewer, $project, $archived)));
    }
}
