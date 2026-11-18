<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Issue\ListSubscribers;
use App\Message\QueryBus;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Who follows the issue, a card of its side panel: the people a change
 * will be written to. Redrawn on `issue:changed`.
 */
#[AsLiveComponent('Issue:Subscribers')]
final class Subscribers
{
    use DefaultActionTrait;
    use GuardsIssue;

    #[LiveProp]
    public Issue $issue;

    /** @var list<User>|null read once per render */
    private ?array $subscribers = null;

    public function __construct(
        private readonly QueryBus $queries,
    ) {
    }

    /**
     * @return list<User>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers ??= $this->queries->ask(new ListSubscribers($this->issue));
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->subscribers = null;
    }
}
