<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Config;

use App\Message\Command\Config\DeleteRole;
use App\MessageHandler\Command\Config\ConfigurationLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * The commands of the configuration run one at a time: the lock is held
 * while the rest of the bus handles one, and for them only.
 */
final class ConfigurationLockTest extends TestCase
{
    public function testAConfigurationCommandHoldsTheLockWhileItIsHandled(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $free = static function () use ($locks): bool {
            $other = $locks->createLock('cairn.configuration');
            if (!$other->acquire()) {
                return false;
            }
            $other->release();

            return true;
        };
        /** @var \ArrayObject<int, bool> $held */
        $held = new \ArrayObject();

        (new ConfigurationLock($locks))->handle(new Envelope(new DeleteRole('client')), self::stack($held, $free));
        (new ConfigurationLock($locks))->handle(new Envelope(new \stdClass()), self::stack($held, $free));

        self::assertSame([false, true], $held->getArrayCopy(), 'taken for a command of the configuration, left alone for any other');
        self::assertTrue($free(), 'released once the command is handled');
    }

    public function testTheLockIsReleasedWhenTheCommandFails(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $failing = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new \DomainException('Refused.');
            }
        };

        try {
            (new ConfigurationLock($locks))->handle(new Envelope(new DeleteRole('client')), self::stackOf($failing));
            self::fail('The refusal goes up.');
        } catch (\DomainException) {
        }

        self::assertTrue($locks->createLock('cairn.configuration')->acquire());
    }

    /**
     * A stack whose next middleware notes whether the lock is free when the command reaches it.
     *
     * @param \ArrayObject<int, bool> $held
     * @param \Closure(): bool        $free
     */
    private static function stack(\ArrayObject $held, \Closure $free): StackInterface
    {
        return self::stackOf(new class($held, $free) implements MiddlewareInterface {
            /**
             * @param \ArrayObject<int, bool> $held
             * @param \Closure(): bool        $free
             */
            public function __construct(private readonly \ArrayObject $held, private readonly \Closure $free)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->held->append(($this->free)());

                return $envelope;
            }
        });
    }

    private static function stackOf(MiddlewareInterface $next): StackInterface
    {
        return new class($next) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }
}
