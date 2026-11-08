<?php

declare(strict_types=1);

namespace App\Message;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Entry point for write commands: dispatches on the `command.bus` and
 * returns what the handler returned, or throws what the handler threw.
 * Every mutation of the system goes through here, from controllers, Live
 * components, webhooks or console.
 */
final class CommandBus
{
    use HandleTrait;

    public function __construct(
        #[Target('command.bus')]
        MessageBusInterface $commandBus,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->messageBus = $commandBus;
    }

    /**
     * @param object $command handled by exactly one handler
     *
     * @return mixed the handler's return value
     */
    public function dispatch(object $command): mixed
    {
        try {
            return $this->handle($command);
        } catch (HandlerFailedException $e) {
            // Surface the handler's own exception (access denied, domain
            // error) instead of Messenger's wrapper.
            throw array_values($e->getWrappedExceptions())[0] ?? $e;
        } catch (DelayedMessageHandlingException $e) {
            // The command went through and its transaction committed; what
            // failed is a message meant to leave after it (a notification
            // to queue). The caller gets its result, the logs the failure.
            $this->logger?->error('A message dispatched after a command could not leave: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);

            return $e->getEnvelope()?->last(HandledStamp::class)?->getResult();
        }
    }
}
