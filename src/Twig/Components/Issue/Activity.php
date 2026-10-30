<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\ChangeDescription;
use App\Message\CommandBus;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Description, thread and editor of the issue page: comment, and apply the
 * workflow actions of the viewer's role, the comment going with the
 * action. A success redraws the thread in place, tells the other
 * components of the page (`issue:changed`) and shows a toast; the comment
 * being written survives what the other components do.
 */
#[AsLiveComponent('Issue:Activity')]
final class Activity extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    /** The comment being written; not `comment`, which canComment() would read. */
    #[LiveProp(writable: true)]
    public string $body = '';

    #[LiveProp(writable: true)]
    public bool $private = false;

    #[LiveProp(writable: true)]
    public bool $editingDescription = false;

    /** The description being rewritten. */
    #[LiveProp(writable: true)]
    public string $description = '';

    /** Why the last action did not go through, shown above the editor. */
    public ?string $error = null;

    public function __construct(
        private readonly WorkflowProvider $workflows,
        private readonly AccessControl $access,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getDetail(): IssueDetail
    {
        return $this->queries->ask(new ShowIssue($this->viewer(), (int) $this->issue->getProject()->getId(), $this->issue->getNumber()))
            ?? throw $this->createNotFoundException('The issue is no longer visible.');
    }

    /**
     * @return list<TransitionDefinition>
     */
    public function getTransitions(): array
    {
        $viewer = $this->viewer();
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());

        return array_values(array_filter(
            $workflow->transitionsFrom((string) $this->issue->getPlace()),
            fn (TransitionDefinition $transition): bool => $this->access->canTransition($viewer, $transition, $this->issue->getProject()),
        ));
    }

    public function canComment(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::CommentCreate, $this->issue->getProject());
    }

    public function canEdit(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::IssueEdit, $this->issue->getProject());
    }

    public function canMakePrivate(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::CommentSetPrivate, $this->issue->getProject());
    }

    #[LiveAction]
    public function postComment(CommandBus $commands): void
    {
        if ('' === trim($this->body)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return;
        }

        $this->run(
            fn () => $commands->dispatch(new AddComment((int) $this->viewer()->getId(), $this->issue->getKey(), $this->body, $this->private && $this->canMakePrivate())),
            $this->translator->trans('issue.comment.added'),
        );
    }

    #[LiveAction]
    public function apply(CommandBus $commands, #[LiveArg] string $transition): void
    {
        $definition = null;
        foreach ($this->getTransitions() as $available) {
            if ($available->id === $transition) {
                $definition = $available;
            }
        }
        if (null === $definition) {
            return;
        }
        if ($definition->requiresComment && '' === trim($this->body)) {
            $this->error = $definition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

            return;
        }

        $this->run(
            fn () => $commands->dispatch(new ApplyTransition((int) $this->viewer()->getId(), $this->issue->getKey(), $definition->id, '' === trim($this->body) ? null : $this->body, $this->private && $this->canMakePrivate())),
            $this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $definition->label]),
        );
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

    /**
     * Redraws with the issue as another component left it; the comment
     * being written is kept.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    private function run(\Closure $dispatch, string $success): void
    {
        try {
            $dispatch();
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.transition.refused');

            return;
        }

        $this->body = '';
        $this->private = false;
        $this->emit('issue:changed');
        $this->dispatchBrowserEvent('toast:show', ['message' => $success]);
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('The issue activity is only rendered for signed-in users.');
    }
}
