<?php

declare(strict_types=1);

namespace App\Story;

use App\Config\ConfigLoader;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\Sync\ConfigSynchronizer;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Factory\WorkspaceFactory;
use App\Message\CommandBus;
use App\Message\Issue\AddComment;
use App\Message\Issue\ApplyTransition;
use App\Message\Issue\CreateIssue;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Development data, close to the mockups: four workspaces, their projects
 * and trackers, internal and client users, and issues spread over the last
 * two months at every place of the bug, evolution and task workflows.
 *
 * Load it with `castor fixtures`. It applies config/instance.example.php,
 * whose workflows it is written for, and creates issues through the real
 * commands under a mocked clock, so numbering, journal and dates are
 * consistent. Every user's password is `cairn`.
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    /** Spread of issue creation dates, in days before now. */
    private const int HISTORY_DAYS = 60;

    /** @var array<string, User> */
    private array $users = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/instance.example.php')]
        private readonly string $configPath,
        private readonly ConfigSynchronizer $synchronizer,
        private readonly CommandBus $commands,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function build(): void
    {
        mt_srand(20261028);
        $this->synchronizer->apply($this->synchronizer->plan((new ConfigLoader($this->configPath))->load()), 'fixtures');

        $this->createUsers();
        $now = (new NativeClock())->now();

        try {
            foreach ($this->projects() as $definition) {
                $project = $definition['project'];
                $trackers = $this->createTrackers($project);
                $dates = self::creationDates($now, \count($definition['issues']));
                foreach ($definition['issues'] as $index => $issue) {
                    $this->createIssue($project, $trackers[$issue[0]], $issue, $definition['people'], $dates[$index], $now, $index);
                }
                if ($definition['archived'] ?? false) {
                    $project->archive();
                }
            }
            $this->entityManager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }

    private function createUsers(): void
    {
        $people = [
            'admin' => ['admin@cairn.test', 'Julie', 'Morel', 'admin'],
            'thomas' => ['thomas.girard@cairn.test', 'Thomas', 'Girard', 'internal'],
            'yann' => ['yann.leroy@cairn.test', 'Yann', 'Leroy', 'internal'],
            'nadia' => ['nadia.benali@cairn.test', 'Nadia', 'Benali', 'internal'],
            'lea' => ['lea.fontaine@cairn.test', 'Léa', 'Fontaine', 'internal'],
            'marc' => ['marc.petit@cairn.test', 'Marc', 'Petit', 'internal'],
            'claire' => ['claire.martin@maison-dupont.test', 'Claire', 'Martin', 'external'],
            'paul' => ['paul.roussel@maison-dupont.test', 'Paul', 'Roussel', 'external'],
            'ines' => ['ines.garnier@vallier.test', 'Inès', 'Garnier', 'external'],
            'remi' => ['remi.colin@natura.test', 'Rémi', 'Colin', 'external'],
            'sophie' => ['sophie.bernard@travelia.test', 'Sophie', 'Bernard', 'external'],
        ];
        foreach ($people as $key => [$email, $firstName, $lastName, $kind]) {
            $factory = UserFactory::new(['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName]);
            $this->users[$key] = match ($kind) {
                'admin' => $factory->admin()->create(),
                'internal' => $factory->internal()->create(),
                default => $factory->create(),
            };
        }
    }

    /**
     * @return iterable<array{project: Project, people: array{manager: string, developers: list<string>, client: ?string}, issues: list<array{0: string, 1: string, 2: string, 3: Criticality, 4?: ?string, 5?: bool}>, archived?: bool}>
     */
    private function projects(): iterable
    {
        $dupont = $this->workspace('Maison Dupont', 'MD', notes: 'Contact principal : Claire Martin (direction e-commerce). Paul Roussel (compta) ne valide pas les recettes : lecture seule.', manager: 'thomas', clients: ['claire' => 'client', 'paul' => 'client_readonly']);
        $vallier = $this->workspace('Groupe Vallier', 'GV', notes: 'Deux projets, un seul interlocuteur DSI.', manager: 'thomas', clients: ['ines' => 'client']);
        $natura = $this->workspace('Natura Cosmétiques', 'NC', notes: 'Refonte livrée en novembre, back-office en TMA.', manager: 'yann', clients: ['remi' => 'client']);
        $travelia = $this->workspace('Travelia', 'TR', notes: 'Contrat terminé en août 2026.', manager: 'yann', clients: ['sophie' => 'client']);

        // [tracker, title, target place, criticality, assignee, private]
        yield ['project' => $this->project($dupont, 'Site e-commerce', 'SPT', 'Boutique en ligne et tunnel de commande.'), 'people' => ['manager' => 'thomas', 'developers' => ['nadia', 'lea'], 'client' => 'claire'], 'issues' => [
            ['bug', 'Spinner infini sur la page panier', 'new', Criticality::Blocking],
            ['bug', 'Le code promo n\'est pas appliqué aux produits soldés', 'qualified', Criticality::High, 'lea'],
            ['bug', 'Typo dans le pied de page', 'new', Criticality::Low],
            ['bug', 'L\'export CSV des commandes est vide', 'to_test', Criticality::High, 'nadia'],
            ['bug', 'Les frais de port ne s\'affichent pas en Corse', 'in_progress', Criticality::Normal, 'nadia'],
            ['bug', 'Image produit floue sur mobile', 'done', Criticality::Low, 'lea'],
            ['bug', 'Erreur 500 à la validation d\'une adresse sans numéro', 'done', Criticality::High, 'nadia'],
            ['bug', 'Le filtre par taille ignore les produits épuisés', 'rejected', Criticality::Normal],
            ['evolution', 'Ajouter le paiement en trois fois', 'to_estimate', Criticality::Normal, 'thomas'],
            ['evolution', 'Export comptable mensuel au format FEC', 'in_progress', Criticality::High, 'nadia'],
            ['evolution', 'Recherche par référence fournisseur', 'planned', Criticality::Normal, 'lea'],
            ['evolution', 'Nouvelle bannière de la page d\'accueil', 'to_test', Criticality::Normal, 'lea'],
            ['evolution', 'Programme de fidélité', 'new', Criticality::Low],
            ['evolution', 'Avis clients sur les fiches produit', 'delivered', Criticality::Normal, 'nadia'],
            ['evolution', 'Liste de souhaits partagée', 'declined', Criticality::Low],
            ['task', 'Mettre à jour Symfony en 8.1', 'doing', Criticality::Normal, 'nadia'],
            ['task', 'Purger le cache Varnish après déploiement', 'done', Criticality::Low, 'lea'],
            ['task', 'Revoir les index de la table des commandes', 'todo', Criticality::Normal],
        ]];
        yield ['project' => $this->project($dupont, 'Intranet', 'INT', 'Outil interne des magasins.'), 'people' => ['manager' => 'thomas', 'developers' => ['marc'], 'client' => 'claire'], 'issues' => [
            ['bug', 'Le planning n\'affiche pas les jours fériés', 'qualified', Criticality::Normal, 'marc'],
            ['bug', 'Connexion impossible depuis le magasin de Lyon', 'in_progress', Criticality::Blocking, 'marc'],
            ['evolution', 'Annuaire des collaborateurs', 'to_estimate', Criticality::Low],
            ['evolution', 'Demandes de congés en ligne', 'delivered', Criticality::Normal, 'marc'],
            ['task', 'Renouveler le certificat TLS', 'done', Criticality::High, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Portail fournisseurs', 'PRT', 'Dépôt et suivi des factures fournisseurs.'), 'people' => ['manager' => 'thomas', 'developers' => ['lea', 'marc'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Le dépôt de facture échoue au-delà de 5 Mo', 'to_test', Criticality::High, 'marc'],
            ['bug', 'Mauvais format de date dans l\'export', 'new', Criticality::Normal],
            ['bug', 'Le mot de passe oublié n\'envoie pas de mail', 'done', Criticality::Blocking, 'lea'],
            ['evolution', 'Signature électronique des bons de commande', 'planned', Criticality::High, 'lea'],
            ['evolution', 'Tableau de bord des paiements', 'in_progress', Criticality::Normal, 'marc'],
            ['evolution', 'Relance automatique des factures en retard', 'new', Criticality::Normal],
            ['task', 'Migrer le stockage vers S3', 'doing', Criticality::Normal, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Application mobile', 'APP', 'Application des commerciaux terrain.'), 'people' => ['manager' => 'thomas', 'developers' => ['nadia'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Crash au démarrage sur Android 12', 'qualified', Criticality::Blocking, 'nadia'],
            ['bug', 'La synchronisation hors ligne perd les notes', 'in_progress', Criticality::High, 'nadia'],
            ['evolution', 'Mode sombre', 'to_estimate', Criticality::Low],
            ['evolution', 'Scanner de codes-barres', 'to_test', Criticality::Normal, 'nadia'],
        ]];
        yield ['project' => $this->project($natura, 'Back-office', 'BO', 'Gestion du catalogue et des commandes.'), 'people' => ['manager' => 'yann', 'developers' => ['lea'], 'client' => 'remi'], 'issues' => [
            ['bug', 'Les variantes de couleur se dupliquent à l\'import', 'in_progress', Criticality::High, 'lea'],
            ['bug', 'Le tri par date de commande est inversé', 'to_test', Criticality::Normal, 'lea'],
            ['bug', 'Libellé tronqué dans la fiche client', 'new', Criticality::Low],
            ['evolution', 'Import des stocks par fichier', 'delivered', Criticality::Normal, 'lea'],
            ['evolution', 'Historique des prix', 'to_estimate', Criticality::Normal],
            ['task', 'Supprimer les comptes de test', 'todo', Criticality::Low, 'lea', true],
        ]];
        yield ['project' => $this->project($travelia, 'Site de réservation', 'RES', 'Réservation de séjours.'), 'people' => ['manager' => 'yann', 'developers' => ['marc'], 'client' => 'sophie'], 'archived' => true, 'issues' => [
            ['bug', 'Double réservation possible sur le dernier créneau', 'done', Criticality::Blocking, 'marc'],
            ['evolution', 'Paiement en plusieurs devises', 'delivered', Criticality::Normal, 'marc'],
            ['evolution', 'Comparateur de séjours', 'declined', Criticality::Low],
        ]];
    }

    /**
     * @param array<string, string> $clients user key => role on the workspace
     */
    private function workspace(string $name, string $key, string $notes, string $manager, array $clients): Workspace
    {
        $workspace = WorkspaceFactory::createOne(['name' => $name, 'key' => $key, 'notes' => $notes]);
        MembershipFactory::createOne(['user' => $this->users[$manager], 'workspace' => $workspace, 'role' => 'project_manager']);
        foreach ($clients as $user => $role) {
            MembershipFactory::createOne(['user' => $this->users[$user], 'workspace' => $workspace, 'role' => $role]);
        }

        return $workspace;
    }

    private function project(Workspace $workspace, string $name, string $key, string $description): Project
    {
        return ProjectFactory::createOne(['workspace' => $workspace, 'name' => $name, 'key' => $key, 'description' => $description]);
    }

    /**
     * @return array<string, Tracker> workflow id => tracker
     */
    private function createTrackers(Project $project): array
    {
        return [
            'bug' => TrackerFactory::createOne(['project' => $project, 'name' => 'Anomalie', 'workflowId' => 'bug', 'position' => 0, 'description' => 'Quelque chose ne fonctionne plus comme avant.']),
            'evolution' => TrackerFactory::createOne(['project' => $project, 'name' => 'Évolution', 'workflowId' => 'evolution', 'position' => 1, 'description' => 'Une nouvelle fonctionnalité ou une amélioration.']),
            'task' => TrackerFactory::createOne(['project' => $project, 'name' => 'Tâche interne', 'workflowId' => 'task', 'position' => 2, 'description' => 'Travail technique sans demande client.', 'private' => true]),
        ];
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: Criticality, 4?: ?string, 5?: bool} $definition
     * @param array{manager: string, developers: list<string>, client: ?string}             $people
     */
    private function createIssue(Project $project, Tracker $tracker, array $definition, array $people, \DateTimeImmutable $createdAt, \DateTimeImmutable $now, int $index): void
    {
        [, $title, $targetPlace, $criticality] = $definition;
        $assignee = $definition[4] ?? null;
        $workflow = $this->workflows->definition($tracker->getWorkflowId());

        // Clients report bugs and evolutions; the agency writes internal tasks.
        $author = $tracker->isPrivate() || null === $people['client'] || 0 === $index % 4 ? $this->users[$people['manager']] : $this->users[$people['client']];
        $clock = new MockClock($createdAt);
        Clock::set($clock);

        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $author->getId(),
            projectId: (int) $project->getId(),
            trackerId: (int) $tracker->getId(),
            title: $title,
            description: 'Constaté par '.$author->getFullName().'. Voir les détails ci-dessous.',
            criticality: $criticality,
            private: $definition[5] ?? false,
            fields: self::fieldsFor($workflow),
        ));
        \assert($issue instanceof Issue);

        if (null !== $assignee) {
            $issue->assignTo($this->users[$assignee]);
            $this->entityManager->flush();
        }

        if (mt_rand(0, 2) > 0) {
            $this->comment($issue, $people, $clock);
        }

        foreach ($this->pathTo($workflow, $targetPlace) as $transition) {
            $clock->modify(\sprintf('+%d hours +%d minutes', mt_rand(2, 40), mt_rand(0, 59)));
            if ($clock->now() > $now) {
                $clock = new MockClock($now->modify('-1 hour'));
                Clock::set($clock);
            }
            $this->commands->dispatch(new ApplyTransition(
                actorId: (int) $this->actorFor($transition, $project, $people)->getId(),
                issueKey: $issue->getKey(),
                transitionId: $transition->id,
                comment: $transition->requiresComment ? 'Toujours reproductible en préproduction, voir la capture.' : null,
            ));
        }
    }

    /**
     * @param array{manager: string, developers: list<string>, client: ?string} $people
     */
    private function comment(Issue $issue, array $people, MockClock $clock): void
    {
        $clock->modify(\sprintf('+%d hours +%d minutes', mt_rand(1, 6), mt_rand(0, 59)));
        $developer = $people['developers'][mt_rand(0, \count($people['developers']) - 1)];
        $this->commands->dispatch(new AddComment((int) $this->users[$developer]->getId(), $issue->getKey(), 'Je regarde, je reviens vers vous dans la journée.'));

        if (null !== $people['client'] && !$issue->isPrivate()) {
            $clock->modify(\sprintf('+%d hours +%d minutes', mt_rand(1, 6), mt_rand(0, 59)));
            $this->commands->dispatch(new AddComment((int) $this->users[$people['client']]->getId(), $issue->getKey(), 'Merci, c\'est bloquant pour nos équipes.'));
        }
        if (0 === mt_rand(0, 2)) {
            $this->commands->dispatch(new AddComment((int) $this->users[$people['manager']]->getId(), $issue->getKey(), 'À chiffrer avant de s\'engager sur une date.', private: true));
        }
    }

    /**
     * Creation dates within the history, oldest first, so that issue
     * numbers follow the calendar.
     *
     * @return list<\DateTimeImmutable>
     */
    private static function creationDates(\DateTimeImmutable $now, int $count): array
    {
        $minutesAgo = array_map(static fn (): int => mt_rand(3 * 24 * 60, self::HISTORY_DAYS * 24 * 60), range(1, $count));
        rsort($minutesAgo);

        return array_map(static fn (int $minutes): \DateTimeImmutable => $now->modify("-{$minutes} minutes"), $minutesAgo);
    }

    /**
     * Shortest sequence of transitions from the initial place to `$target`.
     *
     * @return list<TransitionDefinition>
     */
    private function pathTo(WorkflowDefinition $workflow, string $target): array
    {
        $paths = [$workflow->initial => []];
        $queue = [$workflow->initial];
        while ([] !== $queue) {
            $place = array_shift($queue);
            if ($place === $target) {
                return $paths[$place];
            }
            foreach ($workflow->transitionsFrom($place) as $transition) {
                if (!isset($paths[$transition->to])) {
                    $paths[$transition->to] = [...$paths[$place], $transition];
                    $queue[] = $transition->to;
                }
            }
        }

        throw new \LogicException(\sprintf('Place "%s" cannot be reached in workflow "%s".', $target, $workflow->id));
    }

    /**
     * The person who would naturally trigger the transition: the client for
     * acceptance, a developer for the work, the manager otherwise.
     *
     * @param array{manager: string, developers: list<string>, client: ?string} $people
     */
    private function actorFor(TransitionDefinition $transition, Project $project, array $people): User
    {
        $candidates = [$people['client'], ...$people['developers'], $people['manager'], 'admin'];
        foreach ($candidates as $key) {
            if (null !== $key && $this->access->canTransition($this->users[$key], $transition, $project)) {
                return $this->users[$key];
            }
        }

        throw new \LogicException(\sprintf('Nobody may apply transition "%s".', $transition->id));
    }

    /**
     * Plausible values for the fields of the workflow, required ones included.
     *
     * @return array<string, mixed>
     */
    private static function fieldsFor(WorkflowDefinition $workflow): array
    {
        $values = [
            'environment' => ['prod', 'preprod', 'staging'][mt_rand(0, 2)],
            'url' => 'https://www.example.test/'.['panier', 'compte', 'catalogue', 'commande'][mt_rand(0, 3)],
            'browser' => ['Chrome 130 sur macOS', 'Safari sur iPhone', 'Firefox sur Windows'][mt_rand(0, 2)],
            'goal' => 'Faire gagner du temps aux équipes qui traitent les commandes au quotidien.',
            'due_date' => '2026-12-15',
        ];

        return array_intersect_key($values, $workflow->fields);
    }
}
