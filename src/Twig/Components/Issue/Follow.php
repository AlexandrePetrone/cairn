<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\CommandBus;
use App\Repository\IssueSubscriptionRepository;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The "Follow" / "Unfollow" button at the top of the issue page: the
 * only setting of the notifications. Redrawn on `issue:changed`, since a
 * comment or an assignment may have subscribed the viewer.
 */
#[AsLiveComponent('Issue:Follow')]
final class Follow
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    public function __construct(
        private readonly IssueSubscriptionRepository $subscriptions,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isFollowing(): bool
    {
        return null !== $this->subscriptions->findOne($this->issue, $this->viewer->get());
    }

    #[LiveAction]
    public function follow(CommandBus $commands): void
    {
        if ($this->tryCommand($commands, new FollowIssue((int) $this->viewer->get()->getId(), $this->issue->getKey()))) {
            $this->announce($this->translator->trans('issue.follow.followed'));
        }
    }

    #[LiveAction]
    public function unfollow(CommandBus $commands): void
    {
        if ($this->tryCommand($commands, new UnfollowIssue((int) $this->viewer->get()->getId(), $this->issue->getKey()))) {
            $this->announce($this->translator->trans('issue.follow.unfollowed'));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}
