<?php

declare(strict_types=1);

namespace App\Message;

use App\Message\Query\Query;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Entry point for screen reads: dispatches on the `query.bus` and returns
 * what the handler returned, typed by the query. Controllers and Live
 * components ask here instead of querying the database themselves.
 */
final class QueryBus
{
    use HandleTrait;

    public function __construct(
        #[Target('query.bus')]
        MessageBusInterface $queryBus,
    ) {
        $this->messageBus = $queryBus;
    }

    /**
     * @template TResult
     *
     * @param Query<TResult> $query handled by exactly one handler
     *
     * @return TResult
     */
    public function ask(Query $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $e) {
            throw array_values($e->getWrappedExceptions())[0] ?? $e;
        }
    }
}
