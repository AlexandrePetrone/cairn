<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\ChangeDescription;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The description of the issue page: read, or rewritten in place by the
 * issue's author. A save tells the page (`issue:changed`).
 */
#[AsLiveComponent('Issue:Description')]
final class Description extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editingDescription = false;

    /** The description being rewritten. */
    #[LiveProp(writable: true)]
    public string $description = '';

    /** Why the last save did not go through. */
    public ?string $error = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The issue's author, or whoever may edit the issues of others. */
    public function canEdit(): bool
    {
        return $this->access->canEditIssue($this->viewer(), $this->issue);
    }

    #[LiveAction]
    public function editDescription(): void
    {
        $this->editingDescription = $this->canEdit();
        $this->description = $this->issue->getDescription();
    }

    #[LiveAction]
    public function cancelDescription(): void
    {
        $this->editingDescription = false;
    }

    #[LiveAction]
    public function saveDescription(CommandBus $commands): void
    {
        try {
            $commands->dispatch(new ChangeDescription((int) $this->viewer()->getId(), $this->issue->getKey(), $this->description));
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.edit.refused');

            return;
        }

        $this->editingDescription = false;
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.edit.done', ['%key%' => $this->issue->getKey()])]);
    }

    /** Redraws with the issue as another component left it. */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The issue description is only rendered for signed-in users.');
    }
}
