<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueLinkRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A link between two issues, of a type of the configuration: it reads with
 * the type's label from the source, with its inverse label from the
 * target. A link is the current state, like a membership: removing it
 * deletes the row, and the journal of both issues keeps it.
 */
#[ORM\Entity(repositoryClass: IssueLinkRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_link', columns: ['source_id', 'target_id', 'type_id'])]
class IssueLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $source;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $target;

    /** The type of the link, an element of the configuration: only its key is read from here. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'type_id', nullable: false)]
    private LinkType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Issue $source, LinkType $type, Issue $target, User $createdBy)
    {
        if ($source === $target) {
            throw new \InvalidArgumentException('An issue is not linked to itself.');
        }
        $this->source = $source;
        $this->type = $type;
        $this->target = $target;
        $this->createdBy = $createdBy;
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Issue
    {
        return $this->source;
    }

    public function getTarget(): Issue
    {
        return $this->target;
    }

    public function getTypeId(): string
    {
        return $this->type->getId();
    }

    /**
     * The issue at the other end, seen from one of the two.
     */
    public function otherThan(Issue $issue): Issue
    {
        return $issue === $this->source ? $this->target : $this->source;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
