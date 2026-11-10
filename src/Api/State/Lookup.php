<?php

declare(strict_types=1);

namespace App\Api\State;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\Project;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use App\Repository\AttachmentRepository;
use App\Repository\CommentRepository;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * What a URL of the API names, for the viewer of the request: a project
 * by its key (`MD-SPT`), an issue by its key (`MD-SPT-12`), a comment by
 * its id, a link by its id when the viewer sees both ends. What the viewer may not see does not exist: 404, as in the
 * pages.
 */
final class Lookup
{
    private const string PROJECT = '/^([A-Z]{2,5})-([A-Z]{2,5})$/';

    public function __construct(
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly QueryBus $queries,
        private readonly ProjectRepository $projects,
        private readonly IssueRepository $issues,
        private readonly CommentRepository $comments,
        private readonly IssueLinkRepository $links,
        private readonly AttachmentRepository $attachments,
    ) {
    }

    public function findProject(string $key): ?Project
    {
        if (1 !== preg_match(self::PROJECT, $key, $matches)) {
            return null;
        }
        $project = $this->projects->findOneByKeys($matches[1], $matches[2]);

        return null !== $project && $this->access->canView($this->viewer->get(), $project) ? $project : null;
    }

    public function project(string $key): Project
    {
        return $this->findProject($key) ?? throw new NotFoundHttpException(\sprintf('No project %s.', $key));
    }

    public function issue(string $key): Issue
    {
        $issue = $this->issues->findOneByKey($key);

        return null !== $issue && $this->access->canSee($this->viewer->get(), $issue) ? $issue : throw new NotFoundHttpException(\sprintf('No issue %s.', $key));
    }

    /**
     * The issue with its thread and its files, as its page reads them.
     */
    public function detail(string $key): IssueDetail
    {
        $issue = $this->issue($key);

        return $this->queries->ask(new ShowIssue($this->viewer->get(), (int) $issue->getProject()->getId(), $issue->getNumber()))
            ?? throw new NotFoundHttpException(\sprintf('No issue %s.', $key));
    }

    public function comment(int $id): Comment
    {
        $comment = $this->comments->find($id);

        return null !== $comment && $this->access->canSeeComment($this->viewer->get(), $comment)
            ? $comment
            : throw new NotFoundHttpException(\sprintf('No comment %d.', $id));
    }

    /**
     * A file the viewer may see: with what it goes with, or their own
     * while it waits.
     */
    public function attachment(string $uuid): Attachment
    {
        $attachment = Uuid::isValid($uuid) ? $this->attachments->findOneByUuid(Uuid::fromString($uuid)) : null;

        return null !== $attachment && $this->access->canSeeAttachment($this->viewer->get(), $attachment)
            ? $attachment
            : throw new NotFoundHttpException(\sprintf('No attachment %s.', $uuid));
    }

    public function link(int $id): IssueLink
    {
        $link = $this->links->find($id);
        $viewer = $this->viewer->get();

        return null !== $link && $this->access->canSee($viewer, $link->getSource()) && $this->access->canSee($viewer, $link->getTarget())
            ? $link
            : throw new NotFoundHttpException(\sprintf('No link %d.', $id));
    }
}
