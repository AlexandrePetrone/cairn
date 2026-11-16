<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Config\ConfigLoader;
use App\Config\Definition\FieldDefinition;
use App\Config\Definition\FieldUsage;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Config\Sync\ConfigSynchronizer;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Enum\BudgetLineStatus;
use App\Entity\Enum\BudgetUnit;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Budget\AddBudgetLine;
use App\Message\Command\Budget\CreateBudget;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Release\CreateRelease;
use App\Message\CommandBus;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\DependencyInjection\Container;

/**
 * The Maison Dupont shop as the page and component tests need it, built
 * through the real commands under the example configuration (the one the
 * screens are written for). Thomas manages the workspace, Nadia and Léa
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

        $synchronizer = $container->get(ConfigSynchronizer::class);
        $synchronizer->apply($synchronizer->plan(self::configuration()), 'tests');

        $this->people();
        $this->places();
        $this->issues();
    }

    /**
     * The example configuration, with the two cases it does not show: a
     * field for internal users only (`estimate`, on bugs) and a workflow
     * without fields (tasks).
     */
    private static function configuration(): InstanceDefinition
    {
        $example = (new ConfigLoader(\dirname(__DIR__, 2).'/config/instance.example.php'))->load();
        $fields = [
            'bug' => [...array_values($example->workflows['bug']->fields), new FieldUsage('estimate')],
            'task' => [],
        ];

        return new InstanceDefinition(
            migrationPolicy: $example->migrationPolicy,
            roles: array_values($example->roles),
            permissions: $example->permissions,
            fields: [...array_values($example->fields), new FieldDefinition(id: 'estimate', label: 'Estimate (days)', type: FieldType::Number, internalOnly: true)],
            links: array_values($example->links),
            places: array_values($example->places),
            workflows: array_map(static fn (WorkflowDefinition $workflow) => new WorkflowDefinition(
                id: $workflow->id,
                label: $workflow->label,
                initial: $workflow->initial,
                places: $workflow->places,
                transitions: array_values($workflow->transitions),
                fields: $fields[$workflow->id] ?? array_values($workflow->fields),
                fallbackPlace: $workflow->fallbackPlace,
                migrations: $workflow->migrations,
            ), array_values($example->workflows)),
        );
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
        $tracker = new Tracker($this->intranet, 'Bug', 'bug', 0);
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
        $this->bugs = new Tracker($this->shop, 'Bug', 'bug', 0);
        $this->evolutions = new Tracker($this->shop, 'Feature', 'evolution', 1);
        $this->tasks = new Tracker($this->shop, 'Internal task', 'task', 2);
        $this->tasks->makePrivate();

        $memberships = [
            Membership::onWorkspace($this->user('thomas.girard@cairn.test'), $this->dupont, 'project_manager'),
            Membership::onWorkspace($this->user('claire.martin@maison-dupont.test'), $this->dupont, 'client'),
            Membership::onWorkspace($this->user('paul.roussel@maison-dupont.test'), $this->dupont, 'client_readonly'),
            Membership::onProject($this->user('nadia.benali@cairn.test'), $this->shop, 'developer'),
            Membership::onProject($this->user('lea.fontaine@cairn.test'), $this->shop, 'developer'),
            Membership::onProject($this->user('marc.petit@cairn.test'), $this->intranet, 'developer'),
            Membership::onWorkspace($this->user('sophie.bernard@travelia.test'), $travelia, 'client'),
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
            foreach ($workflow->pathTo($target) as $transition) {
                $this->commands->dispatch(new ApplyTransition(
                    actorId: (int) $this->actorFor($transition->id, $issue)->getId(),
                    issueKey: $issue->getKey(),
                    transitionId: $transition->id,
                    comment: $transition->requiresComment ? 'Still reproducible on pre-production.' : null,
                ));
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
     * Who would naturally trigger the transition: the client when her role
     * may, a developer for the work, the manager otherwise.
     */
    private function actorFor(string $transitionId, Issue $issue): User
    {
        $transition = $this->workflows->definition($issue->getTracker()->getWorkflowId())->transition($transitionId);
        foreach (['claire.martin@maison-dupont.test', 'nadia.benali@cairn.test', 'thomas.girard@cairn.test'] as $email) {
            if ($this->access->canTransition($this->user($email), $transition, $this->shop) && $this->access->canSee($this->user($email), $issue)) {
                return $this->user($email);
            }
        }

        throw new \LogicException(\sprintf('Nobody in the scene may apply "%s".', $transitionId));
    }
}
