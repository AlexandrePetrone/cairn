<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Message\Command\Issue\UnfollowIssue;
use App\Notification\SubscriptionRules;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UnfollowIssueHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly SubscriptionRules $subscriptions,
    ) {
    }

    public function __invoke(UnfollowIssue $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);

        $this->subscriptions->unsubscribe($issue, $actor);
    }
}
