<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\TransitionDefinition;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\CommandBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * What the viewer may do on an issue: comment, and apply the workflow
 * actions their role allows from the current place, the comment going
 * with the action. A success reloads the issue page, whose header, panel
 * and thread all change.
 */
#[AsLiveComponent('Issue:Actions')]
final class Actions extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    /** The comment being written; not `comment`, which canComment() would read. */
    public string $body = '';

    #[LiveProp(writable: true)]
    public bool $private = false;

    /** Why the last action did not go through, shown above the editor. */
    public ?string $error = null;

    public function __construct(
        private readonly WorkflowProvider $workflows,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
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

    public function canMakePrivate(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::CommentSetPrivate, $this->issue->getProject());
    }

    #[LiveAction]
    public function postComment(CommandBus $commands): ?Response
    {
        if ('' === trim($this->body)) {
            $this->error = $this->translator->trans('issue.comment.empty');

            return null;
        }

        return $this->run(
            fn () => $commands->dispatch(new AddComment((int) $this->viewer()->getId(), $this->issue->getKey(), $this->body, $this->private && $this->canMakePrivate())),
            $this->translator->trans('issue.comment.added'),
        );
    }

    #[LiveAction]
    public function apply(CommandBus $commands, #[LiveArg] string $transition): ?Response
    {
        $definition = null;
        foreach ($this->getTransitions() as $available) {
            if ($available->id === $transition) {
                $definition = $available;
            }
        }
        if (null === $definition) {
            return null;
        }
        if ($definition->requiresComment && '' === trim($this->body)) {
            $this->error = $definition->commentPrompt ?? $this->translator->trans('issue.transition.comment_required');

            return null;
        }

        return $this->run(
            fn () => $commands->dispatch(new ApplyTransition((int) $this->viewer()->getId(), $this->issue->getKey(), $definition->id, '' === trim($this->body) ? null : $this->body, $this->private && $this->canMakePrivate())),
            $this->translator->trans('issue.transition.applied', ['%key%' => $this->issue->getKey(), '%transition%' => $definition->label]),
        );
    }

    private function run(\Closure $dispatch, string $success): ?Response
    {
        try {
            $dispatch();
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.transition.refused');

            return null;
        }

        $this->addFlash('success', $success);

        return $this->redirectToRoute('app_issue_show', [
            'workspaceKey' => $this->issue->getProject()->getWorkspace()->getKey(),
            'projectKey' => $this->issue->getProject()->getKey(),
            'number' => $this->issue->getNumber(),
        ]);
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('Issue actions are only rendered for signed-in users.');
    }
}
