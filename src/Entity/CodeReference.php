<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\CodeReferenceKind;
use App\Entity\Enum\Forge;
use App\Entity\Enum\MergeRequestState;
use App\Repository\CodeReferenceRepository;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * A commit or a merge request of a forge that mentions the issue. One row
 * per object and per issue: a commit pushed again, or a merge request
 * delivered again, finds its row. A merge request keeps its latest state;
 * the journal keeps each change. Internal users only. The repository is
 * kept by its path, not by the linked repository row: unlinking a
 * repository leaves the history alone.
 */
#[ORM\Entity(repositoryClass: CodeReferenceRepository::class)]
#[ORM\Table(name: 'code_reference')]
#[ORM\UniqueConstraint(name: 'uniq_code_reference', columns: ['issue_id', 'forge', 'repository_path', 'kind', 'external_id'])]
class CodeReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Issue $issue;

    #[ORM\Column(length: 10, enumType: Forge::class)]
    private Forge $forge;

    #[ORM\Column(length: 255)]
    private string $repositoryPath;

    #[ORM\Column(length: 20, enumType: CodeReferenceKind::class)]
    private CodeReferenceKind $kind;

    /** The sha of a commit, the number of a merge request. */
    #[ORM\Column(length: 64)]
    private string $externalId;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 500)]
    private string $url;

    /** The branch pushed, or the source branch of the merge request. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $branch;

    #[ORM\Column(length: 120)]
    private string $authorName;

    #[ORM\Column(length: 10, nullable: true, enumType: MergeRequestState::class)]
    private ?MergeRequestState $state;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Issue $issue, Forge $forge, string $repositoryPath, CodeReferenceKind $kind, string $externalId, string $title, string $url, ?string $branch, string $authorName, ?MergeRequestState $state)
    {
        $this->issue = $issue;
        $this->forge = $forge;
        $this->repositoryPath = $repositoryPath;
        $this->kind = $kind;
        $this->externalId = $externalId;
        $this->title = $title;
        $this->url = $url;
        $this->branch = $branch;
        $this->authorName = $authorName;
        $this->state = $state;
        $this->updatedAt = now();
    }

    public static function commit(Issue $issue, Forge $forge, string $repositoryPath, string $sha, string $title, string $url, ?string $branch, string $authorName): self
    {
        return new self($issue, $forge, $repositoryPath, CodeReferenceKind::Commit, $sha, $title, $url, $branch, $authorName, null);
    }

    public static function mergeRequest(Issue $issue, Forge $forge, string $repositoryPath, string $number, string $title, string $url, ?string $branch, string $authorName, MergeRequestState $state): self
    {
        return new self($issue, $forge, $repositoryPath, CodeReferenceKind::MergeRequest, $number, $title, $url, $branch, $authorName, $state);
    }

    /** The forge told us about the object again. */
    public function refresh(string $title, string $url, ?string $branch, ?MergeRequestState $state): void
    {
        $this->title = $title;
        $this->url = $url;
        $this->branch = $branch;
        $this->state = $state;
        $this->updatedAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getForge(): Forge
    {
        return $this->forge;
    }

    public function getRepositoryPath(): string
    {
        return $this->repositoryPath;
    }

    public function isMergeRequest(): bool
    {
        return CodeReferenceKind::MergeRequest === $this->kind;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /** The first characters of a commit's sha, as forges show it. */
    public function getShortSha(): string
    {
        return substr($this->externalId, 0, 8);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getState(): ?MergeRequestState
    {
        return $this->state;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
