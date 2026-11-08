<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\FollowIssue;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The mails a notification produces, read by handing the handler the
 * messages the commands left on the in-memory transport. Mails are sent
 * on the spot in the test environment (SendEmailMessage routed sync, to
 * the null mailer), so their body can be read. Thomas (manager) and
 * Claire (author) follow every issue of the fixtures; Nadia develops.
 */
final class NotifyIssueChangeHandlerTest extends KernelTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private InMemoryTransport $async;
    private NotifyIssueChangeHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->handler = self::getContainer()->get(NotifyIssueChangeHandler::class);
    }

    public function testTheCreationIsToldToTheOtherSubscribersAndStartsTheThread(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->handleLast();

        self::assertEmailCount(1, message: 'the author does not receive their own creation');
        $email = $this->email(0);
        self::assertEmailAddressContains($email, 'to', 'thomas@agency.test');
        self::assertEmailHeaderSame($email, 'subject', \sprintf('[%s] Export CSV vide', $issue->getKey()));
        self::assertEmailHeaderSame($email, 'Message-ID', \sprintf('<issue-%d@localhost>', $issue->getId()));
        self::assertEmailHtmlBodyContains($email, 'a créé la demande');
        self::assertEmailHtmlBodyContains($email, 'Le fichier téléchargé est vide.', 'the description comes along');
        self::assertEmailHtmlBodyContains($email, \sprintf('http://localhost/MD/SPT/issues/%d', $issue->getNumber()));
    }

    public function testAPrivateCommentGoesToInternalSubscribersOnly(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new FollowIssue((int) $this->fixtures->developer->getId(), $issue->getKey()));
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $issue->getKey(), 'Entre nous : **urgent**.', private: true));

        $this->handleLast();

        self::assertEmailCount(1, message: 'Claire is external, Thomas acted');
        $email = $this->email(0);
        self::assertEmailAddressContains($email, 'to', 'nadia@agency.test');
        self::assertEmailHtmlBodyContains($email, '<strong>urgent</strong>');
        self::assertEmailHtmlBodyContains($email, 'a commenté');
        self::assertEmailHeaderSame($email, 'In-Reply-To', \sprintf('<issue-%d@localhost>', $issue->getId()));
    }

    public function testAnActionMadeWithAPrivateCommentIsToldToInternalSubscribersOnly(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'Je prends, le client ne lit pas ceci.', commentPrivate: true));

        $this->handleLast();

        // The journal line of such an action is private too: the client reads the new place on the issue.
        self::assertEmailCount(1);
        $toThomas = $this->emailTo('thomas@agency.test');
        self::assertEmailHtmlBodyContains($toThomas, 'a appliqué « Démarrer »');
        self::assertEmailHtmlBodyContains($toThomas, 'le client ne lit pas ceci');
    }

    public function testAnActionWithAPublicCommentIsToldToEverySubscriber(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'Je prends.'));

        $this->handleLast();

        self::assertEmailCount(2);
        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), 'a appliqué « Démarrer »');
        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), 'Je prends.');
        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), 'Je prends.');
    }

    public function testAReferenceToAnInvisibleIssueStaysTextForAnExternalReader(): void
    {
        $task = $this->fixtures->createIssue($this->fixtures->manager, $this->fixtures->tasks, title: 'Tâche interne');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('Voir #%s.', $task->getKey())));

        $this->handleLast();

        self::assertEmailCount(2);
        $link = \sprintf('href="http://localhost/MD/SPT/issues/%d"', $task->getNumber());
        self::assertEmailHtmlBodyContains($this->emailTo('claire@client.test'), '#'.$task->getKey());
        self::assertEmailHtmlBodyNotContains($this->emailTo('claire@client.test'), $link);
        self::assertEmailHtmlBodyContains($this->emailTo('thomas@agency.test'), $link);
    }

    private function handleLast(): void
    {
        $sent = $this->async->getSent();
        $envelope = end($sent);
        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(NotifyIssueChange::class, $message);
        ($this->handler)($message);
    }

    private function email(int $index): Email
    {
        $email = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function emailTo(string $address): Email
    {
        foreach (self::getMailerMessages() as $email) {
            if ($email instanceof Email && \in_array($address, array_map(static fn ($to): string => $to->getAddress(), $email->getTo()), true)) {
                return $email;
            }
        }
        self::fail(\sprintf('No mail to %s.', $address));
    }
}
