<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\User;
use App\Event\IssueCreated;
use App\Markdown\MarkdownRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds and sends the mail that tells one subscriber what happened to an
 * issue: the journal lines of the command, the comment that came with it,
 * the description of the issue so that the mail reads on its own, a link
 * to the issue. One template for everybody; what the recipient may
 * not see was left out by the caller, and the comment is rendered for
 * them, so that an issue reference they may not see stays text, with
 * absolute URLs. The mails of an issue form one thread: `References` and `In-Reply-To` name
 * the id the creation mail carried as `Message-ID`.
 */
final class IssueMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly MarkdownRenderer $markdown,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<JournalEntry> $entries the lines the recipient may see
     * @param class-string       $event   the domain event that caused the mail
     */
    public function send(User $recipient, Issue $issue, User $actor, array $entries, ?Comment $comment, string $event): void
    {
        $project = $issue->getProject();
        $url = $this->urls->generate('app_issue_show', [
            'workspaceKey' => $project->getWorkspace()->getKey(),
            'projectKey' => $project->getKey(),
            'number' => $issue->getNumber(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->to(new Address($recipient->getEmail(), $recipient->getFullName()))
            ->subject($this->translator->trans('email.issue.subject', ['%key%' => $issue->getKey(), '%title%' => $issue->getTitle()]))
            ->htmlTemplate('email/issue_changed.html.twig')
            ->context([
                'recipient' => $recipient,
                'issue' => $issue,
                'actor' => $actor,
                'entries' => $entries,
                'comment' => $comment,
                'commentHtml' => null === $comment ? null : $this->markdown->render($comment->getBody(), $recipient, absoluteUrls: true),
                'descriptionHtml' => '' === trim($issue->getDescription()) ? null : $this->markdown->render($issue->getDescription(), $recipient, absoluteUrls: true),
                'issueUrl' => $url,
            ]);

        $threadId = \sprintf('issue-%d@%s', $issue->getId(), $this->urls->getContext()->getHost());
        $headers = $email->getHeaders();
        if (IssueCreated::class === $event) {
            $headers->addIdHeader('Message-ID', $threadId);
        } else {
            $headers->addIdHeader('In-Reply-To', $threadId);
            $headers->addIdHeader('References', $threadId);
        }

        $this->mailer->send($email);
    }
}
