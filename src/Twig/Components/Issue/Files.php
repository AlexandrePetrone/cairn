<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Message\Command\Attachment\RemoveAttachment;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Twig\Components\HandlesAttachments;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The Files block of the issue page: the files of the issue itself (those
 * of a comment show under it). They go with its text: the issue's author
 * adds and removes them; each change is journaled and tells the page.
 */
#[AsLiveComponent('Issue:Files')]
final class Files
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use HandlesAttachments;

    #[LiveProp]
    public Issue $issue;

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<Attachment>
     */
    public function getFiles(): array
    {
        return $this->attachments->findByIssue($this->issue);
    }

    public function canEdit(): bool
    {
        return $this->access->canEditIssue($this->viewer->get(), $this->issue);
    }

    #[LiveAction]
    public function upload(Request $request, CommandBus $commands): void
    {
        [$sent, $this->error] = $this->sendFiles($request, $commands, $this->viewer->get(), $this->issue->getProject(), $this->issue->getKey());
        if ([] !== $sent) {
            $this->announce($this->translator->trans('attachment.added', ['%count%' => \count($sent)]));
        }
    }

    #[LiveAction]
    public function remove(CommandBus $commands, #[LiveArg] string $uuid, #[LiveArg] string $name): void
    {
        if ($this->tryCommand($commands, new RemoveAttachment((int) $this->viewer->get()->getId(), $uuid))) {
            $this->announce($this->translator->trans('attachment.removed', ['%name%' => $name]));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}
