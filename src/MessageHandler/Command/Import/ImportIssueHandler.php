<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Project;
use App\Entity\User;
use App\Import\TextRewriter;
use App\Message\Command\Import\ImportedFile;
use App\Message\Command\Import\ImportIssue;
use App\MessageHandler\Command\Issue\IssueHandlerSupport;
use App\Notification\SubscriptionRules;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Repository\ReleaseRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Workflow\WorkflowProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Writes an imported issue without `IssueHandlerSupport::record()`, which
 * would date the issue of today and dispatch a domain event: the import
 * keeps the dates of the archive and notifies nobody. The journal line is
 * written here, the subscriptions through the rules' own `subscribe()`,
 * which keeps out whoever cannot see the issue.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ImportIssueHandler
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly TrackerRepository $trackers,
        private readonly UserRepository $users,
        private readonly ReleaseRepository $releases,
        private readonly IssueRepository $issues,
        private readonly WorkflowProvider $workflows,
        private readonly IssueHandlerSupport $support,
        private readonly AttachmentStorage $storage,
        private readonly SubscriptionRules $subscriptions,
        private readonly TextRewriter $rewriter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ImportIssue $command): Issue
    {
        $project = $this->projects->find($command->projectId) ?? throw ImportException::notFound('Project', $command->projectId);
        $tracker = $this->trackers->find($command->trackerId);
        if (null === $tracker || $tracker->getProject() !== $project) {
            throw ImportException::notFound('Tracker', $command->trackerId);
        }
        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        $place = ($workflow->hasPlace($command->placeId) ? $this->workflows->findPlace($command->placeId) : null) ?? throw ImportException::invalidChoice($workflow->label, $command->placeId);
        if (null !== $this->issues->findOneBy(['project' => $project, 'number' => $command->number])) {
            throw ImportException::numberTaken($project->getIssuePrefix().'-'.$command->number);
        }
        $release = null === $command->releaseId ? null : $this->releases->find($command->releaseId);
        if (null !== $release && $release->getProject() !== $project) {
            throw ImportException::notFound('Release', $command->releaseId);
        }

        // The files first: their addresses go into the texts that name them.
        $urls = [];
        $issueFiles = [];
        foreach ($command->files as $file) {
            $issueFiles[] = $attachment = $this->attachment($project, $file);
            $urls[$file->id] = $this->url($attachment);
        }
        $commentFiles = [];
        foreach ($command->comments as $index => $comment) {
            foreach ($comment->files as $file) {
                $commentFiles[$index][] = $attachment = $this->attachment($project, $file);
                $urls[$file->id] = $this->url($attachment);
            }
        }

        $author = $this->user($command->authorId);
        $assignee = null === $command->assigneeId ? null : $this->user($command->assigneeId);
        $issue = Issue::imported(
            $project,
            $tracker,
            $this->workflows->row($place),
            $command->number,
            $author,
            $command->title,
            $this->rewriter->files($command->description, $urls),
            $command->criticality,
            $assignee,
            $release,
            $command->private,
            $command->importedRef,
            $command->createdAt,
            $command->updatedAt,
            $command->closedAt,
        );
        $this->support->arrive($issue);
        $this->entityManager->persist($issue);
        $this->support->writeFieldValues($issue, $command->fieldValues);
        foreach ($issueFiles as $attachment) {
            $attachment->attachTo($issue);
        }

        foreach ($command->comments as $index => $imported) {
            $comment = Comment::imported($issue, $this->user($imported->authorId), $this->rewriter->files($imported->body, $urls), $imported->private, $imported->createdAt);
            $this->entityManager->persist($comment);
            foreach ($commentFiles[$index] ?? [] as $attachment) {
                $attachment->attachTo($issue, $comment);
            }
        }

        $this->entityManager->persist(JournalEntry::imported($issue, $command->source, $command->importedRef, $command->notes));

        $this->subscriptions->subscribe($issue, $author);
        if (null !== $assignee) {
            $this->subscriptions->subscribe($issue, $assignee);
        }
        foreach ($command->watcherIds as $watcherId) {
            $watcher = $this->users->find($watcherId);
            if (null !== $watcher) {
                $this->subscriptions->subscribe($issue, $watcher);
            }
        }

        return $issue;
    }

    private function attachment(Project $project, ImportedFile $file): Attachment
    {
        $stored = new File($file->path);
        $attachment = Attachment::imported(
            $project,
            $this->user($file->uploaderId),
            Attachment::safeFilename($file->name, (string) $stored->guessExtension()),
            (string) $stored->getMimeType(),
            (int) $stored->getSize(),
            $file->createdAt,
        );
        $this->entityManager->persist($attachment);
        $this->storage->store($stored, $attachment);

        return $attachment;
    }

    /** The address a text points to an attachment with, as the editor writes it. */
    private function url(Attachment $attachment): string
    {
        return '/attachments/'.$attachment->getUuid()->toRfc4122().'/'.$attachment->getFilename();
    }

    private function user(int $id): User
    {
        return $this->users->find($id) ?? throw ImportException::notFound('User', $id);
    }
}
