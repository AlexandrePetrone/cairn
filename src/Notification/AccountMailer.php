<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\User;
use App\Security\PasswordTokens;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails about an account: the invitation to a new one and the
 * reset link of "Forgot password?". Both carry one link to the page that
 * sets the password, absolute, with the token the handler issued.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function sendInvitation(User $user, string $token): void
    {
        $this->mailer->send($this->email($user, 'email.invitation.subject', 'email/invitation.html.twig', [
            'url' => $this->url($token),
            'days' => intdiv(PasswordTokens::INVITATION_LIFETIME, 86400),
        ]));
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $this->mailer->send($this->email($user, 'email.password_reset.subject', 'email/password_reset.html.twig', [
            'url' => $this->url($token),
        ]));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function email(User $user, string $subject, string $template, array $context): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject($this->translator->trans($subject))
            ->htmlTemplate($template)
            ->context(['user' => $user] + $context);
    }

    private function url(string $token): string
    {
        return $this->urls->generate('app_password_set', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
