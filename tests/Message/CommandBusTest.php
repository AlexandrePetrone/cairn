<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\CommandBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CommandBusTest extends TestCase
{
    public function testTheHandlerResultIsKeptWhenAMessageDispatchedAfterTheCommandFails(): void
    {
        $bus = new CommandBus(new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps)->with(new HandledStamp('created', 'handler'));

                throw new DelayedMessageHandlingException([new \RuntimeException('transport down')], $envelope);
            }
        });

        self::assertSame('created', $bus->dispatch(new \stdClass()));
    }

    public function testTheHandlerExceptionIsSurfaced(): void
    {
        $bus = new CommandBus(new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new HandlerFailedException(Envelope::wrap($message, $stamps), [new \DomainException('refused')]);
            }
        });

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('refused');
        $bus->dispatch(new \stdClass());
    }
}
