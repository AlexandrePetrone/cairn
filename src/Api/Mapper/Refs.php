<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\AttachmentResource;
use App\Api\Resource\Value\FileRef;
use App\Api\Resource\Value\IssueRef;
use App\Api\Resource\Value\UserRef;
use App\Entity\Attachment;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\User;
use App\Security\Viewer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What every resource names the same way: a person, a file, another
 * issue, with their addresses.
 */
final class Refs
{
    public function __construct(
        private readonly Viewer $viewer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * A person as the viewer sees them: their email is for the agency.
     */
    public function user(User $user): UserRef
    {
        return new UserRef((int) $user->getId(), $user->getFullName(), $user->isInternal(), $this->viewer->get()->isInternal() ? $user->getEmail() : null);
    }

    public function file(Attachment $attachment): FileRef
    {
        $uuid = (string) $attachment->getUuid();

        return new FileRef(
            $uuid,
            $attachment->getFilename(),
            $attachment->getMediaType(),
            $attachment->getSize(),
            $this->urls->generate(AttachmentResource::CONTENT_ROUTE, ['uuid' => $uuid], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('app_attachment_show', ['uuid' => $uuid, 'filename' => $attachment->getFilename()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    public function issue(Issue $issue): IssueRef
    {
        return new IssueRef($issue->getKey(), $issue->getTitle(), PlaceStatus::Closed === $issue->getStatus(), $this->issueUrl($issue));
    }

    /**
     * The page of the issue in the application.
     */
    public function issueUrl(Issue $issue): string
    {
        $project = $issue->getProject();

        return $this->urls->generate('app_issue_show', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey(), 'number' => $issue->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
