<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\TagColour;
use App\Entity\Issue;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Config\InstallConfiguration;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\TagIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Release\CreateRelease;
use App\Message\Command\Tag\CreateTag;
use App\Message\CommandBus;
use App\Security\AccessControl;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\DependencyInjection\Container;

/**
 * The Maison Dupont shop as the page and component tests need it, built
 * through the real commands under its own configuration (ShopConfiguration). Thomas manages the workspace, Nadia and Léa
 * develop on the shop and Marc on the intranet only, Claire is the
 * client, Paul only reads, Sophie is the client of another workspace
 * whose project is archived. Julie administers the instance and holds no
 * role anywhere.
 *
 * Ten issues on the shop, numbered in this order: MD-SPT-1 and 2 are new
 * bugs, 3 is in progress, 4 waits for Claire's acceptance with a thread
 * (Nadia, Claire, then Thomas in private), 5 is closed, 6 and 9 are
 * internal tasks, 7, 8 and 10 are evolutions. Nothing is drawn at random.
 */
final class ShopScene
{
    public Workspace $dupont;
    public Project $shop;
    public Project $intranet;
    public Project $booking;
    public Tracker $bugs;
    public Tracker $evolutions;
    public Tracker $tasks;

    /** @var array<string, User> email => user */
    private array $users = [];

    /** @var array<string, Issue> key => issue */
    private array $issues = [];

    private readonly EntityManagerInterface $entityManager;
    private readonly CommandBus $commands;
    private readonly AccessControl $access;
    private readonly WorkflowProvider $workflows;

    /**
     * `ShopScene::load(self::getContainer())` in a kernel or web test.
     */
    public static function load(Container $container): self
    {
        return new self($container instanceof TestContainer ? $container : throw new \LogicException('The scene is built from the test container.'));
    }

    private function __construct(TestContainer $container)
    {
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->commands = $container->get(CommandBus::class);
        $this->access = $container->get(AccessControl::class);
        $this->workflows = $container->get(WorkflowProvider::class);

        $this->commands->dispatch(new InstallConfiguration(ShopConfiguration::definition()));

        $this->people();
        $this->places();
        $this->issues();
    }

    public function user(string $email): User
    {
        return $this->users[$email] ?? throw new \LogicException(\sprintf('No %s in the scene.', $email));
    }

    public function issue(string $key): Issue
    {
        return $this->issues[$key] ?? throw new \LogicException(\sprintf('No %s in the scene.', $key));
    }

    /**
     * A new bug on the intranet, which has no tracker until then: for the
     * tests of a selection across projects. Thomas, who manages the
     * workspace, reports it.
     */
    public function intranetBug(): Issue
    {
        $tracker = new Tracker($this->intranet, 'Bug', DefinitionFactory::workflowRow('bug', $this->entityManager), 0);
        $this->entityManager->persist($tracker);
        $this->entityManager->flush();

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->user('thomas.girard@cairn.test')->getId(),
            projectId: (int) $this->intranet->getId(),
            trackerId: (int) $tracker->getId(),
            title: 'The menu no longer opens',
            description: '',
            fields: ['environment' => 'prod'],
        ));
        \assert($issue instanceof Issue);
        $this->issues[$issue->getKey()] = $issue;

        return $issue;
    }

    /**
     * Another new bug on the shop, reported by Claire: for the tests that
     * need more issues than the scene holds.
     */
    public function bug(string $title): Issue
    {
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->user('claire.martin@maison-dupont.test')->getId(),
            projectId: (int) $this->shop->getId(),
            trackerId: (int) $this->bugs->getId(),
            title: $title,
            description: '',
            fields: ['environment' => 'prod'],
        ));
        \assert($issue instanceof Issue);
        $this->issues[$issue->getKey()] = $issue;

        return $issue;
    }

    /**
     * An open release of the shop, which has none until then, holding the
     * issues named: Thomas, who manages the workspace, creates it.
     *
     * @param list<string> $keys
     */
    public function release(string $name = '2.5.0', array $keys = []): Release
    {
        $thomas = (int) $this->user('thomas.girard@cairn.test')->getId();
        $release = $this->commands->dispatch(new CreateRelease($thomas, (int) $this->shop->getId(), $name, description: 'Checkout.'));
        \assert($release instanceof Release);
        foreach ($keys as $key) {
            $this->commands->dispatch(new UpdateIssue($thomas, $key, releaseId: $release->getId()));
        }

        return $release;
    }

    /**
     * A tag of the shop, which has none until then, on the issues named:
     * Thomas, who manages the workspace, creates it.
     *
     * @param list<string> $keys
     */
    public function tag(string $name = 'Back office', TagColour $colour = TagColour::Blue, array $keys = []): Tag
    {
        $thomas = (int) $this->user('thomas.girard@cairn.test')->getId();
        $tag = $this->commands->dispatch(new CreateTag($thomas, (int) $this->shop->getId(), $name, $colour));
        \assert($tag instanceof Tag);
        foreach ($keys as $key) {
            $this->commands->dispatch(new TagIssue($thomas, $key, (int) $tag->getId()));
        }

        return $tag;
    }

    /**
     * An open budget of the shop, which has none until then: the project
     * takes days as its unit and Thomas, who manages the workspace, creates
     * the budget.
     */
    public function budget(string $name = 'Order 2026-01', float $days = 10): Budget
    {
        $thomas = (int) $this->user('thomas.girard@cairn.test')->getId();
        $this->commands->dispatch(new UpdateProject((int) $this->shop->getId(), actorId: $thomas, budgetUnit: BudgetUnit::Days));
        $budget = $this->commands->dispatch(new CreateBudget($thomas, (int) $this->shop->getId(), $name, (int) round($days * 420)));
        \assert($budget instanceof Budget);

        return $budget;
    }

    /**
     * Thomas writes a line on a budget of the shop: so many days, for an
     * issue of the scene, under a label, or both.
     */
    public function line(Budget $budget, BudgetLineStatus $status, ?float $days = null, ?string $key = null, ?string $label = null): BudgetLine
    {
        $line = $this->commands->dispatch(new AddBudgetLine(
            (int) $this->user('thomas.girard@cairn.test')->getId(),
            (int) $budget->getId(),
            $status,
            null === $days ? null : (int) round($days * 420),
            null === $key ? null : $this->issue($key)->getId(),
            $label,
        ));
        \assert($line instanceof BudgetLine);

        return $line;
    }

    private function people(): void
    {
        foreach ([
            ['thomas.girard@cairn.test', 'Thomas', 'Girard', true, null],
            ['nadia.benali@cairn.test', 'Nadia', 'Benali', true, null],
            ['lea.fontaine@cairn.test', 'Léa', 'Fontaine', true, null],
            ['marc.petit@cairn.test', 'Marc', 'Petit', true, null],
            ['claire.martin@maison-dupont.test', 'Claire', 'Martin', false, 'Maison Dupont'],
            ['paul.roussel@maison-dupont.test', 'Paul', 'Roussel', false, 'Maison Dupont'],
            ['sophie.bernard@travelia.test', 'Sophie', 'Bernard', false, 'Travelia'],
        ] as [$email, $firstName, $lastName, $internal, $company]) {
            $this->users[$email] = new User($email, $firstName, $lastName, $internal, $company);
            $this->entityManager->persist($this->users[$email]);
        }
        $this->users['julie.admin@cairn.test'] = new User('julie.admin@cairn.test', 'Julie', 'Admin');
        $this->users['julie.admin@cairn.test']->makeInternal(admin: true);
        $this->entityManager->persist($this->users['julie.admin@cairn.test']);
    }

    private function places(): void
    {
        $this->dupont = new Workspace('Maison Dupont', 'MD');
        $this->shop = new Project($this->dupont, 'Online shop', 'SPT');
        $this->intranet = new Project($this->dupont, 'Intranet', 'INT');
        $travelia = new Workspace('Travelia', 'TR');
        $this->booking = new Project($travelia, 'Booking site', 'RES');
        $this->booking->archive();
        $this->bugs = new Tracker($this->shop, 'Bug', DefinitionFactory::workflowRow('bug', $this->entityManager), 0);
        $this->evolutions = new Tracker($this->shop, 'Feature', DefinitionFactory::workflowRow('evolution', $this->entityManager), 1);
        $this->tasks = new Tracker($this->shop, 'Internal task', DefinitionFactory::workflowRow('task', $this->entityManager), 2);
        $this->tasks->makePrivate();

        $memberships = [
            Membership::onWorkspace($this->user('thomas.girard@cairn.test'), $this->dupont, DefinitionFactory::roleRow('project_manager', $this->entityManager)),
            Membership::onWorkspace($this->user('claire.martin@maison-dupont.test'), $this->dupont, DefinitionFactory::roleRow('client', $this->entityManager)),
            Membership::onWorkspace($this->user('paul.roussel@maison-dupont.test'), $this->dupont, DefinitionFactory::roleRow('client_readonly', $this->entityManager)),
            Membership::onProject($this->user('nadia.benali@cairn.test'), $this->shop, DefinitionFactory::roleRow('developer', $this->entityManager)),
            Membership::onProject($this->user('lea.fontaine@cairn.test'), $this->shop, DefinitionFactory::roleRow('developer', $this->entityManager)),
            Membership::onProject($this->user('marc.petit@cairn.test'), $this->intranet, DefinitionFactory::roleRow('developer', $this->entityManager)),
            Membership::onWorkspace($this->user('sophie.bernard@travelia.test'), $travelia, DefinitionFactory::roleRow('client', $this->entityManager)),
        ];
        foreach ([$this->dupont, $this->shop, $this->intranet, $travelia, $this->booking, $this->bugs, $this->evolutions, $this->tasks, ...$memberships] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    private function issues(): void
    {
        // [tracker, title, target place, criticality, author, assignee]
        $definitions = [
            [$this->bugs, 'Endless spinner on the cart page', 'new', Criticality::Blocking, 'claire.martin@maison-dupont.test', null],
            [$this->bugs, 'Typo in the footer', 'new', Criticality::Low, 'claire.martin@maison-dupont.test', null],
            [$this->bugs, 'Shipping costs are not shown for Corsica', 'in_progress', Criticality::Normal, 'claire.martin@maison-dupont.test', 'nadia.benali@cairn.test'],
            [$this->bugs, 'The CSV export of orders is empty', 'to_test', Criticality::High, 'claire.martin@maison-dupont.test', 'nadia.benali@cairn.test'],
            [$this->bugs, 'Blurry product image on mobile', 'done', Criticality::Low, 'claire.martin@maison-dupont.test', 'lea.fontaine@cairn.test'],
            [$this->tasks, 'Upgrade Symfony to 8.1', 'in_progress', Criticality::Normal, 'thomas.girard@cairn.test', 'nadia.benali@cairn.test'],
            [$this->evolutions, 'Add payment in three instalments', 'new', Criticality::Normal, 'claire.martin@maison-dupont.test', null],
            [$this->evolutions, 'Monthly accounting export in FEC format', 'to_estimate', Criticality::High, 'thomas.girard@cairn.test', null],
            [$this->tasks, 'Purge the Varnish cache after deployment', 'done', Criticality::Low, 'thomas.girard@cairn.test', 'lea.fontaine@cairn.test'],
            [$this->evolutions, 'Search by supplier reference', 'planned', Criticality::Normal, 'claire.martin@maison-dupont.test', 'lea.fontaine@cairn.test'],
        ];
        $thomas = $this->user('thomas.girard@cairn.test');

        foreach ($definitions as [$tracker, $title, $target, $criticality, $author, $assignee]) {
            $workflow = $this->workflows->definition($tracker->getWorkflowId());
            $issue = $this->commands->dispatch(new CreateIssue(
                actorId: (int) $this->user($author)->getId(),
                projectId: (int) $this->shop->getId(),
                trackerId: (int) $tracker->getId(),
                title: $title,
                description: 'Reported by '.$this->user($author)->getFullName().'.',
                criticality: $criticality,
                fields: array_intersect_key(['environment' => 'prod', 'goal' => 'Save time for the teams.'], $workflow->fields),
            ));
            \assert($issue instanceof Issue);
            $this->issues[$issue->getKey()] = $issue;

            if (null !== $assignee) {
                $this->commands->dispatch(new UpdateIssue((int) $thomas->getId(), $issue->getKey(), assigneeId: $this->user($assignee)->getId()));
            }
            if ('MD-SPT-4' === $issue->getKey()) {
                $this->thread($issue);
            }
            foreach ($workflow->trailTo($target) as $place) {
                $this->advance($issue, $place);
            }
        }
    }

    private function thread(Issue $issue): void
    {
        $this->commands->dispatch(new AddComment((int) $this->user('nadia.benali@cairn.test')->getId(), $issue->getKey(), 'I am looking into it, I will get back to you today.'));
        $this->commands->dispatch(new AddComment((int) $this->user('claire.martin@maison-dupont.test')->getId(), $issue->getKey(), 'Thanks, it is blocking our teams.'));
        $this->commands->dispatch(new AddComment((int) $this->user('thomas.girard@cairn.test')->getId(), $issue->getKey(), 'To estimate before committing to a date.', private: true));
    }

    /**
     * Takes the issue one place further on its trail
     * (WorkflowDefinition::trailTo()): by the action of the client when her
     * role has one that leads there, otherwise by the manager, who moves
     * freely.
     */
    private function advance(Issue $issue, string $place): void
    {
        $claire = $this->user('claire.martin@maison-dupont.test');
        $action = $this->access->canSee($claire, $issue)
            ? array_find($this->access->transitionsFor($claire, $issue), static fn (TransitionDefinition $transition): bool => $transition->to === $place)
            : null;
        if (null !== $action) {
            $this->commands->dispatch(new ApplyTransition((int) $claire->getId(), $issue->getKey(), $action->id, $action->requiresComment ? 'Still reproducible on pre-production.' : null));

            return;
        }
        $this->commands->dispatch(new MoveIssue((int) $this->user('thomas.girard@cairn.test')->getId(), $issue->getKey(), $place));
    }
}
