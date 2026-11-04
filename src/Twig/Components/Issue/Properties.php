<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\User;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The properties of an issue in the side panel of its page: assignee,
 * criticality, release and visibility become choices for whoever may
 * change them; the release shows once the project has releases.
 * A change is its own command; the other components of the page are told
 * (`issue:changed`), and this one follows their changes (a transition
 * moves the issue to another place).
 */
#[AsLiveComponent('Issue:Properties')]
final class Properties
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public ?int $assigneeId = null;

    #[LiveProp(writable: true)]
    public ?int $releaseId = null;

    #[LiveProp(writable: true)]
    public Criticality $criticality = Criticality::DEFAULT;

    /**
     * `public` or `private`: a string, because the live controller writes a
     * prop back into its select as text, and a boolean would match no option.
     */
    #[LiveProp(writable: true)]
    public string $visibility = 'public';

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(Issue $issue): void
    {
        $this->issue = $issue;
        $this->refresh();
    }

    /**
     * Aligns the choices with the issue as another component left it.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->assigneeId = $this->issue->getAssignee()?->getId();
        $this->releaseId = $this->issue->getRelease()?->getId();
        $this->criticality = $this->issue->getCriticality();
        $this->visibility = $this->issue->isPrivate() ? 'private' : 'public';
    }

    public function canAssign(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueAssign, $this->issue->getProject());
    }

    public function canSetCriticality(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetCriticality, $this->issue->getProject());
    }

    public function canSetRelease(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetRelease, $this->issue->getProject());
    }

    public function hasReleases(): bool
    {
        return $this->releases->hasReleases($this->issue->getProject());
    }

    /**
     * The releases the issue can go to: the open ones, and the one it is
     * in even when delivered or abandoned, so that the choice shows it.
     *
     * @return list<Release>
     */
    public function getReleaseChoices(): array
    {
        $choices = $this->releases->findByProject($this->issue->getProject(), openOnly: true);
        $current = $this->issue->getRelease();
        if (null !== $current && !$current->isOpen()) {
            $choices[] = $current;
        }

        return $choices;
    }

    /**
     * An issue of a private tracker has no visibility to choose.
     */
    public function canSetVisibility(): bool
    {
        return !$this->issue->getTracker()->isPrivate()
            && $this->access->isGranted($this->viewer->get(), Permission::IssueSetPrivate, $this->issue->getProject());
    }

    /**
     * @return list<User>
     */
    public function getMembers(): array
    {
        return $this->queries->ask(new ListProjectMembers($this->issue->getProject()));
    }

    #[LiveAction]
    public function change(CommandBus $commands, #[LiveArg] string $property): void
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $this->issue->getKey();
        $command = match ($property) {
            'assignee' => new UpdateIssue($actorId, $key, assigneeId: $this->assigneeId ?? UpdateIssue::NOBODY),
            'criticality' => new UpdateIssue($actorId, $key, criticality: $this->criticality),
            'release' => new UpdateIssue($actorId, $key, releaseId: $this->releaseId ?? UpdateIssue::NOBODY),
            'visibility' => new UpdateIssue($actorId, $key, private: 'private' === $this->visibility),
            default => null,
        };
        if (null === $command) {
            return;
        }

        if (!$this->tryCommand($commands, $command)) {
            $this->refresh();

            return;
        }

        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $key]));
    }
}
