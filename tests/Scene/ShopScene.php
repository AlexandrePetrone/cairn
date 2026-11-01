<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\AssignIssue;
use App\Message\Command\Issue\CreateIssue;
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
 * whose project is archived.
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
        $synchronizer->apply($synchronizer->plan((new ConfigLoader(\dirname(__DIR__, 2).'/config/instance.example.php'))->load()), 'tests');

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

    private function people(): void
    {
        foreach ([
            ['thomas.girard@cairn.test', 'Thomas', 'Girard', true],
            ['nadia.benali@cairn.test', 'Nadia', 'Benali', true],
            ['lea.fontaine@cairn.test', 'Léa', 'Fontaine', true],
            ['marc.petit@cairn.test', 'Marc', 'Petit', true],
            ['claire.martin@maison-dupont.test', 'Claire', 'Martin', false],
            ['paul.roussel@maison-dupont.test', 'Paul', 'Roussel', false],
            ['sophie.bernard@travelia.test', 'Sophie', 'Bernard', false],
        ] as [$email, $firstName, $lastName, $internal]) {
            $this->users[$email] = new User($email, $firstName, $lastName, $internal);
            $this->entityManager->persist($this->users[$email]);
        }
    }

    private function places(): void
    {
        $this->dupont = new Workspace('Maison Dupont', 'MD');
        $this->shop = new Project($this->dupont, 'Site e-commerce', 'SPT');
        $this->intranet = new Project($this->dupont, 'Intranet', 'INT');
        $travelia = new Workspace('Travelia', 'TR');
        $this->booking = new Project($travelia, 'Site de réservation', 'RES');
        $this->booking->archive();
        $this->bugs = new Tracker($this->shop, 'Anomalie', 'bug', 0);
        $this->evolutions = new Tracker($this->shop, 'Évolution', 'evolution', 1);
        $this->tasks = new Tracker($this->shop, 'Tâche interne', 'task', 2);
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
            [$this->bugs, 'Spinner infini sur la page panier', 'new', Criticality::Blocking, 'claire.martin@maison-dupont.test', null],
            [$this->bugs, 'Typo dans le pied de page', 'new', Criticality::Low, 'claire.martin@maison-dupont.test', null],
            [$this->bugs, 'Les frais de port ne s\'affichent pas en Corse', 'in_progress', Criticality::Normal, 'claire.martin@maison-dupont.test', 'nadia.benali@cairn.test'],
            [$this->bugs, 'L\'export CSV des commandes est vide', 'to_test', Criticality::High, 'claire.martin@maison-dupont.test', 'nadia.benali@cairn.test'],
            [$this->bugs, 'Image produit floue sur mobile', 'done', Criticality::Low, 'claire.martin@maison-dupont.test', 'lea.fontaine@cairn.test'],
            [$this->tasks, 'Mettre à jour Symfony en 8.1', 'doing', Criticality::Normal, 'thomas.girard@cairn.test', 'nadia.benali@cairn.test'],
            [$this->evolutions, 'Ajouter le paiement en trois fois', 'new', Criticality::Normal, 'claire.martin@maison-dupont.test', null],
            [$this->evolutions, 'Export comptable mensuel au format FEC', 'to_estimate', Criticality::High, 'thomas.girard@cairn.test', null],
            [$this->tasks, 'Purger le cache Varnish après déploiement', 'done', Criticality::Low, 'thomas.girard@cairn.test', 'lea.fontaine@cairn.test'],
            [$this->evolutions, 'Recherche par référence fournisseur', 'planned', Criticality::Normal, 'claire.martin@maison-dupont.test', 'lea.fontaine@cairn.test'],
        ];
        $thomas = $this->user('thomas.girard@cairn.test');

        foreach ($definitions as [$tracker, $title, $target, $criticality, $author, $assignee]) {
            $workflow = $this->workflows->definition($tracker->getWorkflowId());
            $issue = $this->commands->dispatch(new CreateIssue(
                actorId: (int) $this->user($author)->getId(),
                projectId: (int) $this->shop->getId(),
                trackerId: (int) $tracker->getId(),
                title: $title,
                description: 'Constaté par '.$this->user($author)->getFullName().'.',
                criticality: $criticality,
                fields: array_intersect_key(['environment' => 'prod', 'goal' => 'Faire gagner du temps aux équipes.'], $workflow->fields),
            ));
            \assert($issue instanceof Issue);
            $this->issues[$issue->getKey()] = $issue;

            if (null !== $assignee) {
                $this->commands->dispatch(new AssignIssue((int) $thomas->getId(), $issue->getKey(), $this->user($assignee)->getId()));
            }
            if ('MD-SPT-4' === $issue->getKey()) {
                $this->thread($issue);
            }
            foreach ($workflow->pathTo($target) as $transition) {
                $this->commands->dispatch(new ApplyTransition(
                    actorId: (int) $this->actorFor($transition->id, $issue)->getId(),
                    issueKey: $issue->getKey(),
                    transitionId: $transition->id,
                    comment: $transition->requiresComment ? 'Toujours reproductible en préproduction.' : null,
                ));
            }
        }
    }

    private function thread(Issue $issue): void
    {
        $this->commands->dispatch(new AddComment((int) $this->user('nadia.benali@cairn.test')->getId(), $issue->getKey(), 'Je regarde, je reviens vers vous dans la journée.'));
        $this->commands->dispatch(new AddComment((int) $this->user('claire.martin@maison-dupont.test')->getId(), $issue->getKey(), 'Merci, c\'est bloquant pour nos équipes.'));
        $this->commands->dispatch(new AddComment((int) $this->user('thomas.girard@cairn.test')->getId(), $issue->getKey(), 'À chiffrer avant de s\'engager sur une date.', private: true));
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
