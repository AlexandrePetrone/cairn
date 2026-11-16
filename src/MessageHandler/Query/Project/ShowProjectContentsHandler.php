<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Project;

use App\Entity\Attachment;
use App\Entity\AutomationRule;
use App\Entity\Budget;
use App\Entity\CodeRepository;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Membership;
use App\Entity\Release;
use App\Entity\Tracker;
use App\Message\Query\Project\ProjectContents;
use App\Message\Query\Project\ShowProjectContents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts everything, whoever asks: no Visibility here, the page is the
 * administration's and what is deleted is deleted for everyone.
 */
#[AsMessageHandler(bus: 'query.bus')]
final class ShowProjectContentsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ShowProjectContents $query): ProjectContents
    {
        $project = $query->project;
        $comments = $this->entityManager->createQuery(\sprintf('SELECT COUNT(c.id) FROM %s c JOIN c.issue i WHERE i.project = :project', Comment::class))
            ->setParameter('project', $project)
            ->getSingleScalarResult();

        return new ProjectContents(
            $project,
            issues: $this->count(Issue::class, $query),
            comments: (int) $comments,
            files: $this->count(Attachment::class, $query),
            trackers: $this->count(Tracker::class, $query),
            releases: $this->count(Release::class, $query),
            members: $this->count(Membership::class, $query),
            repositories: $this->count(CodeRepository::class, $query),
            rules: $this->count(AutomationRule::class, $query),
            budgets: $this->count(Budget::class, $query),
        );
    }

    /**
     * @param class-string $class an entity that belongs to a project
     */
    private function count(string $class, ShowProjectContents $query): int
    {
        return $this->entityManager->getRepository($class)->count(['project' => $query->project]);
    }
}
