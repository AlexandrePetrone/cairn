<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Security\AccessControl;
use App\Twig\Components\HandlesAttachments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
final class Files extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use HandlesAttachments;

    #[LiveProp]
    public Issue $issue;

    /** Why the last action did not go through. */
    public ?string $error = null;

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AccessControl $access,
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
        return $this->access->canEditIssue($this->viewer(), $this->issue);
    }

    #[LiveAction]
    public function upload(Request $request, CommandBus $commands): void
    {
        [$sent, $this->error] = $this->sendFiles($request, $commands, $this->viewer(), $this->issue->getProject(), $this->issue->getKey());
        if ([] !== $sent) {
            $this->changed($this->translator->trans('attachment.added', ['%count%' => \count($sent)]));
        }
    }

    #[LiveAction]
    public function remove(CommandBus $commands, #[LiveArg] string $uuid, #[LiveArg] string $name): void
    {
        $this->error = $this->removeFile($commands, $this->viewer(), $uuid);
        if (null === $this->error) {
            $this->changed($this->translator->trans('attachment.removed', ['%name%' => $name]));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    private function changed(string $message): void
    {
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $message]);
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The issue files are only rendered for signed-in users.');
    }
}
