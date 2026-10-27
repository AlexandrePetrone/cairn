<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Issue;

use App\Entity\Issue;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\CommandBus;
use App\Message\Issue\CreateIssue;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The access fixtures plus two trackers on the shop project (a public one
 * and a private one, both on the `bug` workflow) and the usual actors.
 */
final class IssueFixtures
{
    public AccessFixtures $access;
    public Tracker $bugs;
    public Tracker $tasks;
    public User $manager;
    public User $developer;
    public User $client;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommandBus $commands,
    ) {
        $this->access = new AccessFixtures($entityManager);

        $this->bugs = new Tracker($this->access->shop, 'Anomalie', 'bug');
        $this->tasks = new Tracker($this->access->shop, 'Tâche interne', 'bug', 1);
        $this->tasks->makePrivate();
        $entityManager->persist($this->bugs);
        $entityManager->persist($this->tasks);
        $entityManager->flush();

        $this->manager = $this->access->user('thomas@agency.test', internal: true);
        $this->access->grantOnWorkspace($this->manager, $this->access->workspace, 'project_manager');
        $this->developer = $this->access->user('nadia@agency.test', internal: true);
        $this->client = $this->access->user('claire@client.test');
        $this->access->grantOnWorkspace($this->client, $this->access->workspace, 'client');
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function createIssue(User $actor, ?Tracker $tracker = null, string $title = 'Export CSV vide', array $fields = ['environment' => 'prod']): Issue
    {
        $tracker ??= $this->bugs;

        return $this->commands->dispatch(new CreateIssue(
            actorId: (int) $actor->getId(),
            projectId: (int) $tracker->getProject()->getId(),
            trackerId: (int) $tracker->getId(),
            title: $title,
            description: 'Le fichier téléchargé est vide.',
            fields: $fields,
        ));
    }
}
