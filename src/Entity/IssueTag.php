<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tag carried by an issue, both of the same project. Current state,
 * like a link: taking the tag off deletes the row, and the journal of the
 * issue keeps it.
 */
#[ORM\Entity(repositoryClass: IssueTagRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_issue_tag', columns: ['issue_id', 'tag_id'])]
#[ORM\Index(name: 'idx_issue_tag_tag', columns: ['tag_id'])]
class IssueTag
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
    private Tag $tag;

    public function __construct(Issue $issue, Tag $tag)
    {
        if ($issue->getProject() !== $tag->getProject()) {
            throw new \InvalidArgumentException('An issue carries the tags of its own project.');
        }
        $this->issue = $issue;
        $this->tag = $tag;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getTag(): Tag
    {
        return $this->tag;
    }
}
