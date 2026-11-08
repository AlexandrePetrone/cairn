<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Message\Command\Issue\FollowIssue;
use App\Notification\SubscriptionRules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Following is personal: no journal entry, no event.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class FollowIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly SubscriptionRules $subscriptions,
    ) {
    }

    public function __invoke(FollowIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->subscriptions->subscribe($issue, $actor);
    }
}
