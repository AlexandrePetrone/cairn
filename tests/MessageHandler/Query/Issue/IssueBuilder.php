<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Tracker;
use App\Entity\User;
use App\Tests\Config\Fixture\DefinitionFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists issues directly, without going through the commands, for read
 * tests that need precise states. Places come from the test `bug` workflow.
 */
final class IssueBuilder
{
    private int $number = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function issue(Tracker $tracker, User $author, string $title, string $place = 'new', Criticality $criticality = Criticality::Normal, ?User $assignee = null, bool $private = false): Issue
    {
        $issue = new Issue($tracker->getProject(), $tracker, DefinitionFactory::place($place), ++$this->number, $author, $title, '');
        $issue->setCriticality($criticality);
        $issue->assignTo($assignee);
        if ($private) {
            $issue->makePrivate();
        }
        $this->entityManager->persist($issue);
        $this->entityManager->flush();

        return $issue;
    }
}
