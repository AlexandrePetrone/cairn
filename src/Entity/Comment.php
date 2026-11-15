<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A comment on an issue. Private comments are hidden from external users.
 * Written by a person, or by an automation rule: it then has no author
 * and is signed with the name the rule had.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\ManyToOne]
    private ?User $author;

    /** The name of the automation rule that wrote the comment, when no person did. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $private;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the body was last rewritten; the journal keeps the versions. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct(Issue $issue, ?User $author, string $body, bool $private = false)
    {
        $this->issue = $issue;
        $this->author = $author;
        $this->body = $body;
        $this->private = $private;
        $this->createdAt = now();
    }

    /** A comment brought from another tracker, at its date. */
    public static function imported(Issue $issue, User $author, string $body, bool $private, \DateTimeImmutable $createdAt): self
    {
        $comment = new self($issue, $author, $body, $private);
        $comment->createdAt = $createdAt;

        return $comment;
    }

    /** A comment written by an automation rule, signed with its name. */
    public static function byRule(Issue $issue, string $rule, string $body, bool $private): self
    {
        $comment = new self($issue, null, $body, $private);
        $comment->signature = $rule;

        return $comment;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    /** Who the comment reads as written by: the person, or the rule. */
    public function getAuthorName(): string
    {
        return $this->author?->getFullName() ?? (string) $this->signature;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function edit(string $body): void
    {
        $this->body = trim($body);
        $this->editedAt = now();
    }
}
