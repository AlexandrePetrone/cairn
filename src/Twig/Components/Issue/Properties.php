<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Issue\AssignIssue;
use App\Message\Command\Issue\ChangeCriticality;
use App\Message\Command\Issue\ChangeVisibility;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\Security\AccessControl;
use App\Security\Permission;
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
 * The properties of an issue in the side panel of its page: assignee,
 * criticality and visibility become choices for whoever may change them.
 * A change is its own command, then the page reloads.
 */
#[AsLiveComponent('Issue:Properties')]
final class Properties extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public ?int $assigneeId = null;

    #[LiveProp(writable: true)]
    public Criticality $criticality = Criticality::DEFAULT;

    /**
     * `public` or `private`: a string, because the live controller writes a
     * prop back into its select as text, and a boolean would match no option.
     */
    #[LiveProp(writable: true)]
    public string $visibility = 'public';

    /** Why the last change did not go through. */
    public ?string $error = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly QueryBus $queries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(Issue $issue): void
    {
        $this->issue = $issue;
        $this->assigneeId = $issue->getAssignee()?->getId();
        $this->criticality = $issue->getCriticality();
        $this->visibility = $issue->isPrivate() ? 'private' : 'public';
    }

    public function canAssign(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::IssueAssign, $this->issue->getProject());
    }

    public function canSetCriticality(): bool
    {
        return $this->access->isGranted($this->viewer(), Permission::IssueSetCriticality, $this->issue->getProject());
    }

    /**
     * An issue of a private tracker has no visibility to choose.
     */
    public function canSetVisibility(): bool
    {
        return !$this->issue->getTracker()->isPrivate()
            && $this->access->isGranted($this->viewer(), Permission::IssueSetPrivate, $this->issue->getProject());
    }

    /**
     * @return list<User>
     */
    public function getMembers(): array
    {
        return $this->queries->ask(new ListProjectMembers($this->issue->getProject()));
    }

    #[LiveAction]
    public function change(CommandBus $commands, #[LiveArg] string $property): ?Response
    {
        $actorId = (int) $this->viewer()->getId();
        $key = $this->issue->getKey();
        $command = match ($property) {
            'assignee' => new AssignIssue($actorId, $key, $this->assigneeId),
            'criticality' => new ChangeCriticality($actorId, $key, $this->criticality),
            'visibility' => new ChangeVisibility($actorId, $key, 'private' === $this->visibility),
            default => null,
        };
        if (null === $command) {
            return null;
        }

        try {
            $commands->dispatch($command);
        } catch (IssueException|AccessDeniedException) {
            $this->error = $this->translator->trans('issue.change.refused');

            return null;
        }

        $this->addFlash('success', $this->translator->trans('issue.change.done', ['%key%' => $key]));

        return $this->redirectToRoute('app_issue_show', [
            'workspaceKey' => $this->issue->getProject()->getWorkspace()->getKey(),
            'projectKey' => $this->issue->getProject()->getKey(),
            'number' => $this->issue->getNumber(),
        ]);
    }

    private function viewer(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw new \LogicException('Issue properties are only rendered for signed-in users.');
    }
}
