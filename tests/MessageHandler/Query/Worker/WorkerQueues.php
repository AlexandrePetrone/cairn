<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Worker;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The two queues of the worker as they are outside the tests: `async` is
 * in memory here (config/packages/messenger.yaml), so its Doctrine
 * transport is built from the address the other environments give it.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait WorkerQueues
{
    protected static function asyncTransport(): TransportInterface
    {
        return self::getContainer()->get('messenger.transport.doctrine.factory')
            ->createTransport('doctrine://default?auto_setup=0', [], new PhpSerializer());
    }

    protected static function failedTransport(): TransportInterface
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        \assert($failed instanceof TransportInterface);

        return $failed;
    }
}
