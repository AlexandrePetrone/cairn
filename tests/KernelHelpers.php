<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use App\Message\CommandBus;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvents;
use Symfony\Component\Mime\Email;

/**
 * Shortcuts for kernel tests: a configuration in force (the test instance
 * file of CAIRN_CONFIG in .env.test, applied as the first snapshot) and the
 * two buses.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait KernelHelpers
{
    /**
     * The buses have no consumer in the container yet (controllers will),
     * so they are built from the `command.bus` and `query.bus` services.
     */
    protected static function commandBus(): CommandBus
    {
        return new CommandBus(self::getContainer()->get('command.bus'));
    }

    protected static function queryBus(): QueryBus
    {
        return new QueryBus(self::getContainer()->get('query.bus'));
    }

    /**
     * The path of the link in the last mail sent, for the invitation and
     * reset flows: `/password/set/{token}`.
     */
    protected static function passwordLink(): string
    {
        $events = self::getContainer()->get('mailer.message_logger_listener')->getEvents();
        \assert($events instanceof MessageEvents);
        $email = $events->getMessages()[0] ?? null;
        \assert($email instanceof Email, 'a mail was sent');
        \assert(1 === preg_match('~href="https?://[^/]+(/password/set/[A-Za-z0-9_-]+)"~', (string) $email->getHtmlBody(), $matches), 'the mail carries one link');

        return $matches[1];
    }

    protected static function applyConfiguration(): void
    {
        $container = self::getContainer();
        $synchronizer = $container->get(ConfigSynchronizer::class);
        $synchronizer->apply($synchronizer->plan($container->get(ConfigLoader::class)->load()));
    }
}
