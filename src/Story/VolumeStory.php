<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * A project with many issues, to try the lists with several pages: the
 * Natura catalogue (NC-CAT), 200 issues at every place of the three
 * workflows, without threads so that it loads fast. Loaded after AppStory
 * by `castor fixtures`, whose people and workspaces it uses; the tests
 * that load AppStory do not load it. Nothing is drawn at random: the
 * variety follows the index of the issue.
 */
#[AsFixture(name: 'volume')]
final class VolumeStory extends Story
{
    private const int ISSUES = 200;

    private const array BUG_PROBLEMS = ['Error 500', 'Broken display', 'Slowness', 'Wrong total', 'Button does nothing', 'Missing translation', 'Duplicate', 'Wrong sort order'];
    private const array EVOLUTION_WISHES = ['Add an export', 'Filter by brand', 'Show the history', 'Send a notification', 'Import from a file', 'Customise the columns', 'Add a shortcut'];
    private const array TASK_CHORES = ['Update the dependencies', 'Clean the logs', 'Review an index', 'Add a test', 'Document the API'];
    private const array SCREENS = ['the product page', 'the order list', 'the dashboard', 'the customer page', 'the promotions', 'the stock', 'the suppliers', 'the invoicing', 'the returns', 'the customer reviews'];

    /** @var array<string, User> */
    private array $users = [];

    public function __construct(
        private readonly CommandBus $commands,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function build(): void
    {
        foreach (['yann' => 'yann.leroy@cairn.test', 'lea' => 'lea.fontaine@cairn.test', 'nadia' => 'nadia.benali@cairn.test', 'remi' => 'remi.colin@natura.test'] as $key => $email) {
            $this->users[$key] = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException(\sprintf('Load AppStory first: no %s.', $email));
        }
        $natura = $this->entityManager->getRepository(Workspace::class)->findOneBy(['key' => 'NC']) ?? throw new \LogicException('Load AppStory first: no NC workspace.');

        $project = ProjectFactory::createOne(['workspace' => $natura, 'name' => 'B2B catalogue', 'key' => 'CAT', 'description' => 'Catalogue of the resellers, many issues to try the lists.']);
        foreach (['lea', 'nadia'] as $developer) {
            MembershipFactory::createOne(['user' => $this->users[$developer], 'project' => $project, 'role' => 'developer']);
        }
        $trackers = [
            'bug' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('bug')->label, 'workflowId' => 'bug', 'position' => 0]),
            'evolution' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('evolution')->label, 'workflowId' => 'evolution', 'position' => 1]),
            'task' => TrackerFactory::createOne(['project' => $project, 'name' => $this->workflows->definition('task')->label, 'workflowId' => 'task', 'position' => 2, 'private' => true]),
        ];

        $now = (new NativeClock())->now();
        $dates = AppStory::creationDates($now, self::ISSUES);
        try {
            for ($index = 0; $index < self::ISSUES; ++$index) {
                // Half bugs, a third evolutions, the rest internal tasks.
                $workflowId = match (true) {
                    $index % 20 < 10 => 'bug',
                    $index % 20 < 17 => 'evolution',
                    default => 'task',
                };
                $this->createIssue($project, $trackers[$workflowId]->getId(), $workflowId, $index, $dates[$index], $now);
            }
            $this->entityManager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }

    private function createIssue(Project $project, ?int $trackerId, string $workflowId, int $index, \DateTimeImmutable $createdAt, \DateTimeImmutable $now): void
    {
        $workflow = $this->workflows->definition($workflowId);
        $clock = new MockClock($createdAt);
        Clock::set($clock);

        $author = 'task' === $workflowId || 0 === $index % 4 ? $this->users['yann'] : $this->users['remi'];
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $author->getId(),
            projectId: (int) $project->getId(),
            trackerId: (int) $trackerId,
            title: self::title($workflowId, $index),
            description: 'Issue generated to try the lists.',
            criticality: [Criticality::Normal, Criticality::Low, Criticality::High, Criticality::Normal, Criticality::Blocking, Criticality::Low, Criticality::Normal][$index % 7],
            fields: AppStory::fieldsFor($workflow, $index),
        ));
        \assert($issue instanceof Issue);

        $assignee = [null, 'lea', 'nadia', 'yann', 'lea', null, 'nadia'][$index % 7];
        if (null !== $assignee) {
            $this->commands->dispatch(new UpdateIssue((int) $this->users['yann']->getId(), $issue->getKey(), assigneeId: $this->users[$assignee]->getId()));
        }

        // Every place of the workflow in turn, so that each one has issues:
        // the manager moves the issue there directly, whatever the actions
        // of the workflow, which this story does not follow.
        $places = $workflow->places;
        $target = $places[intdiv($index, 3) % \count($places)];
        if ($target !== $workflow->initial) {
            $clock->modify(\sprintf('+%d hours', 1 + $index % 20));
            if ($clock->now() > $now) {
                Clock::set(new MockClock($now->modify('-1 hour')));
            }
            $this->commands->dispatch(new MoveIssue((int) $this->users['yann']->getId(), $issue->getKey(), $target));
        }
    }

    private static function title(string $workflowId, int $index): string
    {
        $screen = self::SCREENS[intdiv($index, 3) % \count(self::SCREENS)];

        return match ($workflowId) {
            'bug' => self::BUG_PROBLEMS[$index % \count(self::BUG_PROBLEMS)].' on '.$screen,
            'evolution' => self::EVOLUTION_WISHES[$index % \count(self::EVOLUTION_WISHES)].' in '.$screen,
            default => self::TASK_CHORES[$index % \count(self::TASK_CHORES)].' ('.$screen.')',
        };
    }
}
