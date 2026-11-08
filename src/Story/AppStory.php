<?php

declare(strict_types=1);

namespace App\Story;

use App\Config\ConfigLoader;
use App\Config\Definition\TransitionDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\Sync\ConfigSynchronizer;
use App\Entity\Attachment;
use App\Entity\CodeRepository;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\Forge;
use App\Entity\Enum\MergeRequestState;
use App\Entity\Enum\ReleaseStatus;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Factory\MembershipFactory;
use App\Factory\ProjectFactory;
use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Factory\WorkspaceFactory;
use App\Forge\Event\CommitPayload;
use App\Forge\Event\MergeRequestPayload;
use App\Message\Command\Attachment\AddAttachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Forge\LinkRepository;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Release\CreateRelease;
use App\Message\Command\Release\UpdateRelease;
use App\Message\CommandBus;
use App\Security\AccessControl;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Development data, close to the mockups: four workspaces, their projects
 * and trackers, internal and client users, issues spread over the last
 * two months at every place of the bug, evolution and task workflows, the
 * releases of two projects and a few links, one to an internal task and
 * one across projects.
 *
 * Load it with `castor fixtures`. It applies config/instance.example.php,
 * whose workflows it is written for, and creates issues through the real
 * commands under a mocked clock, so numbering, journal and dates are
 * consistent. Nothing is drawn at random: the variety (who comments,
 * delays, field values) follows the index of the issue in its project, so
 * that two loads give the same database and tests can rely on it. Every
 * user's password is `cairn`.
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
        $this->synchronizer->apply($this->synchronizer->plan((new ConfigLoader($this->configPath))->load()), 'fixtures');

        $this->createUsers();
        $now = (new NativeClock())->now();

        try {
            foreach ($this->projects() as $definition) {
                $project = $definition['project'];
                $trackers = $this->createTrackers($project);
                $dates = self::creationDates($now, \count($definition['issues']));
                $issues = [];
                foreach ($definition['issues'] as $index => $issue) {
                    $issues[] = $this->createIssue($project, $trackers[$issue[0]], $issue, $definition['people'], $dates[$index], $now, $index);
                }
                foreach ($definition['releases'] ?? [] as $release) {
                    $this->createRelease($project, $this->users[$definition['people']['manager']], $release, $issues, $now);
                }
                if ($definition['archived'] ?? false) {
                    $project->archive();
                }
            }
            $this->createLinks($now);
            $this->createCode($now);
            $this->entityManager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }

    private function createUsers(): void
    {
        $people = [
            'admin' => ['admin@cairn.test', 'Julien', 'Jacottet', 'admin'],
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
     * @return iterable<array{project: Project, people: array{manager: string, developers: list<string>, client: ?string}, issues: list<array{0: string, 1: string, 2: string, 3: Criticality, 4?: ?string, 5?: bool}>, releases?: list<array{0: string, 1: int, 2: string, 3: ReleaseStatus, 4: list<int>}>, archived?: bool}>
     */
    private function projects(): iterable
    {
        // Managers and clients hold their role on the workspace, developers on each project.
        $dupont = $this->workspace('Maison Dupont', 'MD', notes: 'Contact principal : Claire Martin (direction e-commerce). Paul Roussel (compta) ne valide pas les recettes : lecture seule.', manager: 'thomas', clients: ['claire' => 'client', 'paul' => 'client_readonly']);
        $vallier = $this->workspace('Groupe Vallier', 'GV', notes: 'Deux projets, un seul interlocuteur DSI.', manager: 'thomas', clients: ['ines' => 'client']);
        $natura = $this->workspace('Natura Cosmétiques', 'NC', notes: 'Refonte livrée en novembre, back-office en TMA.', manager: 'yann', clients: ['remi' => 'client']);
        $travelia = $this->workspace('Travelia', 'TR', notes: 'Contrat terminé en août 2026.', manager: 'yann', clients: ['sophie' => 'client']);

        // [tracker, title, target place, criticality, assignee, private]
        yield ['project' => $this->project($dupont, 'Site e-commerce', 'SPT', 'Boutique en ligne et tunnel de commande.', developers: ['nadia', 'lea']), 'people' => ['manager' => 'thomas', 'developers' => ['nadia', 'lea'], 'client' => 'claire'], 'issues' => [
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
        ], 'releases' => [
            // [name, planned in days from now, description, status, indexes of its issues]
            ['2.4.0', -10, 'Corrections d\'affichage et avis clients.', ReleaseStatus::Delivered, [5, 6, 13]],
            ['2.5.0', 14, 'Tunnel de commande et export comptable.', ReleaseStatus::Open, [1, 3, 4, 9, 11, 15]],
            ['2.6.0', 45, 'Paiement en trois fois et recherche fournisseur.', ReleaseStatus::Open, [8, 10]],
            ['2.3.1', -40, 'Correctif annulé, repris dans la 2.4.0.', ReleaseStatus::Abandoned, []],
        ]];
        yield ['project' => $this->project($dupont, 'Intranet', 'INT', 'Outil interne des magasins.', developers: ['marc']), 'people' => ['manager' => 'thomas', 'developers' => ['marc'], 'client' => 'claire'], 'issues' => [
            ['bug', 'Le planning n\'affiche pas les jours fériés', 'qualified', Criticality::Normal, 'marc'],
            ['bug', 'Connexion impossible depuis le magasin de Lyon', 'in_progress', Criticality::Blocking, 'marc'],
            ['evolution', 'Annuaire des collaborateurs', 'to_estimate', Criticality::Low],
            ['evolution', 'Demandes de congés en ligne', 'delivered', Criticality::Normal, 'marc'],
            ['task', 'Renouveler le certificat TLS', 'done', Criticality::High, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Portail fournisseurs', 'PRT', 'Dépôt et suivi des factures fournisseurs.', developers: ['lea', 'marc']), 'people' => ['manager' => 'thomas', 'developers' => ['lea', 'marc'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Le dépôt de facture échoue au-delà de 5 Mo', 'to_test', Criticality::High, 'marc'],
            ['bug', 'Mauvais format de date dans l\'export', 'new', Criticality::Normal],
            ['bug', 'Le mot de passe oublié n\'envoie pas de mail', 'done', Criticality::Blocking, 'lea'],
            ['evolution', 'Signature électronique des bons de commande', 'planned', Criticality::High, 'lea'],
            ['evolution', 'Tableau de bord des paiements', 'in_progress', Criticality::Normal, 'marc'],
            ['evolution', 'Relance automatique des factures en retard', 'new', Criticality::Normal],
            ['task', 'Migrer le stockage vers S3', 'doing', Criticality::Normal, 'marc'],
        ]];
        yield ['project' => $this->project($vallier, 'Application mobile', 'APP', 'Application des commerciaux terrain.', developers: ['nadia']), 'people' => ['manager' => 'thomas', 'developers' => ['nadia'], 'client' => 'ines'], 'issues' => [
            ['bug', 'Crash au démarrage sur Android 12', 'qualified', Criticality::Blocking, 'nadia'],
            ['bug', 'La synchronisation hors ligne perd les notes', 'in_progress', Criticality::High, 'nadia'],
            ['evolution', 'Mode sombre', 'to_estimate', Criticality::Low],
            ['evolution', 'Scanner de codes-barres', 'to_test', Criticality::Normal, 'nadia'],
        ]];
        yield ['project' => $this->project($natura, 'Back-office', 'BO', 'Gestion du catalogue et des commandes.', developers: ['lea']), 'people' => ['manager' => 'yann', 'developers' => ['lea'], 'client' => 'remi'], 'issues' => [
            ['bug', 'Les variantes de couleur se dupliquent à l\'import', 'in_progress', Criticality::High, 'lea'],
            ['bug', 'Le tri par date de commande est inversé', 'to_test', Criticality::Normal, 'lea'],
            ['bug', 'Libellé tronqué dans la fiche client', 'new', Criticality::Low],
            ['evolution', 'Import des stocks par fichier', 'delivered', Criticality::Normal, 'lea'],
            ['evolution', 'Historique des prix', 'to_estimate', Criticality::Normal],
            ['task', 'Supprimer les comptes de test', 'todo', Criticality::Low, 'lea', true],
        ], 'releases' => [
            ['1.3.0', 20, 'Import du catalogue et tri des commandes.', ReleaseStatus::Open, [0, 1, 5]],
        ]];
        yield ['project' => $this->project($travelia, 'Site de réservation', 'RES', 'Réservation de séjours.', developers: ['marc']), 'people' => ['manager' => 'yann', 'developers' => ['marc'], 'client' => 'sophie'], 'archived' => true, 'issues' => [
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

    /**
     * @param list<string> $developers user keys granted the developer role on the project
     */
    private function project(Workspace $workspace, string $name, string $key, string $description, array $developers): Project
    {
        $project = ProjectFactory::createOne(['workspace' => $workspace, 'name' => $name, 'key' => $key, 'description' => $description]);
        foreach ($developers as $developer) {
            MembershipFactory::createOne(['user' => $this->users[$developer], 'project' => $project, 'role' => 'developer']);
        }

        return $project;
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
    private function createIssue(Project $project, Tracker $tracker, array $definition, array $people, \DateTimeImmutable $createdAt, \DateTimeImmutable $now, int $index): Issue
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
            fields: self::fieldsFor($workflow, $index),
        ));
        \assert($issue instanceof Issue);

        if (null !== $assignee) {
            $this->commands->dispatch(new UpdateIssue((int) $this->users[$people['manager']]->getId(), $issue->getKey(), assigneeId: $this->users[$assignee]->getId()));
        }

        // Two issues out of three get a thread.
        if (2 !== $index % 3) {
            $this->comment($issue, $people, $clock, $index);
        }

        foreach ($workflow->pathTo($targetPlace) as $step => $transition) {
            $clock->modify(\sprintf('+%d hours +%d minutes', 2 + ($index * 7 + $step * 11) % 38, ($index * 13) % 60));
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

        return $issue;
    }

    /**
     * Links between issues, made by the manager of Maison Dupont.
     */
    private function createLinks(\DateTimeImmutable $now): void
    {
        Clock::set(new MockClock($now->modify('-30 minutes')));
        $thomas = (int) $this->users['thomas']->getId();
        foreach ([
            ['MD-SPT-4', 'blocks', 'MD-SPT-10'],
            ['MD-SPT-1', 'related', 'MD-SPT-16'],
            ['MD-SPT-8', 'duplicates', 'MD-SPT-2'],
            ['MD-INT-2', 'related', 'MD-SPT-1'],
        ] as [$key, $type, $otherKey]) {
            $this->commands->dispatch(new AddLink($thomas, $key, $type, $otherKey));
        }
    }

    /**
     * The shop's repository, linked by Thomas without a webhook (the dev
     * token is empty), and what GitLab would have told about MD-SPT-4 and
     * MD-SPT-3: a push on main, a merge request opened then merged, a
     * commit alone on a branch.
     */
    private function createCode(\DateTimeImmutable $now): void
    {
        $shop = $this->entityManager->getRepository(Project::class)->findOneBy(['key' => 'SPT']) ?? throw new \LogicException('The shop is missing.');
        Clock::set(new MockClock($now->modify('-3 days')));
        $repository = $this->commands->dispatch(new LinkRepository((int) $shop->getId(), Forge::GitLab, 'maison-dupont/shop', (int) $this->users['thomas']->getId()));
        \assert($repository instanceof CodeRepository);
        $id = (int) $repository->getId();
        $base = 'https://gitlab.example/maison-dupont/shop/-/';
        $commit = static fn (string $sha, string $title, string $author): CommitPayload => new CommitPayload($sha, $title, $title, $base.'commit/'.$sha, $author);
        $mergeRequest = static fn (MergeRequestState $state): MergeRequestPayload => new MergeRequestPayload(431, "fix(export): paginer l'export CSV des commandes", '', $base.'merge_requests/431', 'fix/MD-SPT-4-export-csv', $state, 'Nadia Benali');

        Clock::set(new MockClock($now->modify('-2 days 14:00')));
        $this->commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', $mergeRequest(MergeRequestState::Opened)));
        Clock::set(new MockClock($now->modify('-2 days 16:20')));
        $this->commands->dispatch(new RecordCommits($id, 'MD-SPT-4', 'main', $base.'tree/main', [
            $commit('b6568db1bc1dcd7f8b4d5a946b0b91f9dacd7327', "fix(export): paginer l'export CSV des commandes (MD-SPT-4)", 'Nadia Benali'),
            $commit('da1560886d4f094c3e6c9ef40349f7d38b5d27d7', 'test(export): cas des 8 000 commandes MD-SPT-4', 'Nadia Benali'),
            $commit('1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b', 'chore: changelog MD-SPT-4', 'Léa Fontaine'),
        ]));
        $this->commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', $mergeRequest(MergeRequestState::Merged)));
        Clock::set(new MockClock($now->modify('-1 day 10:12')));
        $this->commands->dispatch(new RecordCommits($id, 'MD-SPT-3', 'feat/md-spt-3-filtres', $base.'tree/feat/md-spt-3-filtres', [
            $commit('9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d', 'feat(catalogue): filtres par taille, premier jet (MD-SPT-3)', 'Léa Fontaine'),
        ]));
    }

    /**
     * Creates a release planned relative to now and schedules its issues
     * in it, then delivers or abandons it.
     *
     * @param array{0: string, 1: int, 2: string, 3: ReleaseStatus, 4: list<int>} $definition
     * @param list<Issue>                                                         $issues     the issues of the project, in creation order
     */
    private function createRelease(Project $project, User $manager, array $definition, array $issues, \DateTimeImmutable $now): void
    {
        [$name, $days, $description, $status, $members] = $definition;
        $actorId = (int) $manager->getId();
        // A past release was prepared before its date, a future one is being prepared now.
        Clock::set(new MockClock($now->modify($days < 0 ? \sprintf('%+d days', $days - 3) : '-1 hour')));

        $release = $this->commands->dispatch(new CreateRelease($actorId, (int) $project->getId(), $name, $now->modify(\sprintf('%+d days', $days)), $description));
        \assert($release instanceof Release);
        foreach ($members as $index) {
            $this->commands->dispatch(new UpdateIssue($actorId, $issues[$index]->getKey(), releaseId: $release->getId()));
        }
        if (ReleaseStatus::Open !== $status) {
            Clock::set(new MockClock($now->modify(\sprintf('%+d days', $days))));
            $this->commands->dispatch(new UpdateRelease($actorId, (int) $release->getId(), status: $status));
        }
    }

    /**
     * @param array{manager: string, developers: list<string>, client: ?string} $people
     */
    private function comment(Issue $issue, array $people, MockClock $clock, int $index): void
    {
        $clock->modify(\sprintf('+%d hours +%d minutes', 1 + $index % 6, ($index * 17) % 60));
        $developer = $people['developers'][$index % \count($people['developers'])];
        $this->commands->dispatch(new AddComment((int) $this->users[$developer]->getId(), $issue->getKey(), 'Je regarde, je reviens vers vous dans la journée.', attachments: [$this->upload($this->users[$developer], $issue, 'trace.log')]));

        if (null !== $people['client'] && !$issue->isPrivate()) {
            $clock->modify(\sprintf('+%d hours +%d minutes', 1 + ($index * 5) % 6, ($index * 23) % 60));
            $this->commands->dispatch(new AddComment((int) $this->users[$people['client']]->getId(), $issue->getKey(), 'Merci, c\'est bloquant pour nos équipes.'));
            if ($issue->getAuthor() === $this->users[$people['client']]) {
                $this->upload($issue->getAuthor(), $issue, 'capture.png', toIssue: true);
            }
        }
        // One thread out of three ends with a private note of the manager.
        if (0 === $index % 3) {
            $manager = $this->users[$people['manager']];
            $this->commands->dispatch(new AddComment((int) $manager->getId(), $issue->getKey(), 'À chiffrer avant de s\'engager sur une date.', private: true, attachments: [$this->upload($manager, $issue, 'chiffrage.pdf')]));
        }
    }

    /**
     * Sends one of the sample files of `files/`, pending (its uuid, for the
     * comment that takes it) or straight to the issue.
     */
    private function upload(User $actor, Issue $issue, string $name, bool $toIssue = false): string
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'cairn');
        copy(__DIR__.'/files/'.$name, $copy);
        $file = new UploadedFile($copy, $name, null, null, true);
        $attachment = $this->commands->dispatch($toIssue
            ? new AddAttachment((int) $actor->getId(), $issue->getKey(), $file)
            : new UploadAttachment((int) $actor->getId(), (int) $issue->getProject()->getId(), $file));
        \assert($attachment instanceof Attachment);

        return $attachment->getUuid()->toRfc4122();
    }

    /**
     * Creation dates spread over the history, oldest first, so that issue
     * numbers follow the calendar: evenly spaced, each one shifted by a few
     * hours so that the spacing does not look mechanical.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function creationDates(\DateTimeImmutable $now, int $count): array
    {
        $span = (self::HISTORY_DAYS - 3) * 24 * 60;
        $dates = [];
        for ($index = 0; $index < $count; ++$index) {
            $minutesAgo = 3 * 24 * 60 + intdiv($span * ($count - $index), $count + 1) + ($index * 97) % 600;
            $dates[] = $now->modify("-{$minutesAgo} minutes");
        }

        return $dates;
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
    public static function fieldsFor(WorkflowDefinition $workflow, int $index): array
    {
        $values = [
            'environment' => ['prod', 'preprod', 'staging'][$index % 3],
            'url' => 'https://www.example.test/'.['panier', 'compte', 'catalogue', 'commande'][$index % 4],
            'browser' => ['Chrome 130 sur macOS', 'Safari sur iPhone', 'Firefox sur Windows'][($index + 1) % 3],
            'goal' => 'Faire gagner du temps aux équipes qui traitent les commandes au quotidien.',
            'due_date' => '2026-12-15',
        ];

        return array_intersect_key($values, $workflow->fields);
    }
}
