<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Issue;
use App\Message\Command\Config\InstallConfiguration;
use App\Message\CommandBus;
use App\Message\QueryBus;
use App\Repository\IssueFieldValueRepository;
use App\Tests\Config\Fixture\DefinitionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvents;
use Symfony\Component\Mime\Email;

/**
 * Shortcuts for kernel tests: a configuration in force (the small one of
 * DefinitionFactory, installed into the empty tables) and the two buses.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait KernelHelpers
{
    /**
     * What an issue holds of its fields, as the application reads it.
     *
     * @return array<string, mixed> field id => value
     */
    protected static function fieldValues(Issue $issue): array
    {
        return self::getContainer()->get(IssueFieldValueRepository::class)->valuesOf($issue);
    }

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
        // Not inside an assert(): PHP skips its expression when assertions are off, as on the CI.
        if (1 !== preg_match('~href="https?://[^/]+(/password/set/[A-Za-z0-9_-]+)"~', (string) $email->getHtmlBody(), $matches)) {
            throw new \LogicException('The mail carries no password link.');
        }

        return $matches[1];
    }

    protected static function applyConfiguration(): void
    {
        self::commandBus()->dispatch(new InstallConfiguration(DefinitionFactory::definition()));
    }
}
