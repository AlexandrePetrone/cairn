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
 * criticality, release and visibility read as values and, for whoever may
 * change them, open a menu of the choices; the release shows once the
 * project has releases.
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

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Redraws with the issue as another component left it.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
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

    /**
     * Applies the choice made in the menu of a property: an id or nothing
     * for the assignee and the release, a level, `public` or `private`.
     */
    #[LiveAction]
    public function change(CommandBus $commands, #[LiveArg] string $property, #[LiveArg] string $value): void
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $this->issue->getKey();
        $criticality = Criticality::tryFrom($value);
        $command = match ($property) {
            'assignee' => new UpdateIssue($actorId, $key, assigneeId: self::idOrNobody($value)),
            'criticality' => null === $criticality ? null : new UpdateIssue($actorId, $key, criticality: $criticality),
            'release' => new UpdateIssue($actorId, $key, releaseId: self::idOrNobody($value)),
            'visibility' => new UpdateIssue($actorId, $key, private: 'private' === $value),
            default => null,
        };
        if (null === $command) {
            return;
        }

        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $key]));
    }

    private static function idOrNobody(string $value): int
    {
        return '' === $value ? UpdateIssue::NOBODY : (int) $value;
    }
}
