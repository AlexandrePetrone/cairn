<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * Someone follows an issue: they receive a mail for what happens to it.
 * Subscriptions are made by the rules of Notification/SubscriptionRules
 * (author, assignee, commenters, mentioned people, roles that follow new
 * issues) or by the person themselves, and are the current state, like a
 * membership: unsubscribing deletes the row. Following is not a right:
 * what a subscriber may see is decided again when the mail is built.
 */
#[ORM\Entity(repositoryClass: IssueSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_subscription', columns: ['issue_id', 'user_id'])]
class IssueSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $since;

    public function __construct(Issue $issue, User $user)
    {
        $this->issue = $issue;
        $this->user = $user;
        $this->since = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSince(): \DateTimeImmutable
    {
        return $this->since;
    }
}
