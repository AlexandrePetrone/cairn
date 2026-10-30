<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\ChangeTitle;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The top of the issue page, redrawn when another component of the page
 * changes the issue (`issue:changed`): privacy and place hint follow. The
 * title is edited here by whoever may edit the issue.
 */
#[AsLiveComponent('Issue:Header')]
final class Header extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editingTitle = false;

    /** The title being written. */
    #[LiveProp(writable: true)]
    public string $title = '';

    /** Why the last save did not go through. */
    public ?string $error = null;

    public function __construct(
        private readonly WorkflowProvider $workflows,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getPlace(): ?PlaceDefinition
    {
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());
        $place = (string) $this->issue->getPlace();

        return $workflow->hasPlace($place) ? $workflow->place($place) : null;
    }

    public function canEdit(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::IssueEdit, $this->issue->getProject());
    }

    #[LiveAction]
    public function editTitle(): void
    {
        $this->editingTitle = $this->canEdit();
        $this->title = $this->issue->getTitle();
    }

    #[LiveAction]
    public function cancelTitle(): void
    {
        $this->editingTitle = false;
    }

    #[LiveAction]
    public function saveTitle(CommandBus $commands): void
    {
        try {
            $commands->dispatch(new ChangeTitle((int) $this->viewer()->getId(), $this->issue->getKey(), $this->title));
        } catch (ValidationFailedException) {
            $this->error = $this->translator->trans('issue.edit.title_invalid');

            return;
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.edit.refused');

            return;
        }

        $this->editingTitle = false;
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('issue.edit.done', ['%key%' => $this->issue->getKey()])]);
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The issue header is only rendered for signed-in users.');
    }
}
