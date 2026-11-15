<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * What one command did to an issue, waiting to be told to one subscriber.
 * The rows of a person on a project leave together, in one mail, once
 * things calmed down (Notification/ProjectMailSender), and are deleted
 * with it: this is a queue, not history. Ids only, like the message that
 * wrote it: what the recipient may read is decided again when the mail is
 * built, and what disappeared in the meantime is left out.
 */
#[ORM\Entity(repositoryClass: PendingNotificationRepository::class)]
class PendingNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    /** Null when an automation rule acted: `rule` then names it. */
    #[ORM\ManyToOne]
    private ?User $actor;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $rule;

    /** @var list<int> the journal entries the command wrote */
    #[ORM\Column(type: Types::JSON)]
    private array $entryIds;

    /** Not a foreign key: a comment deleted before the mail leaves is left out. */
    #[ORM\Column(nullable: true)]
    private ?int $commentId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<int> $entryIds
     */
    public function __construct(User $recipient, Issue $issue, ?User $actor, array $entryIds, ?int $commentId, ?string $rule = null)
    {
        $this->recipient = $recipient;
        $this->issue = $issue;
        $this->actor = $actor;
        $this->entryIds = $entryIds;
        $this->commentId = $commentId;
        $this->rule = $rule;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    /**
     * @return list<int>
     */
    public function getEntryIds(): array
    {
        return array_values(array_filter($this->entryIds, is_int(...)));
    }

    public function getCommentId(): ?int
    {
        return $this->commentId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
