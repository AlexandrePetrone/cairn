<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ImportStatus;
use App\Repository\ImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * The import of an archive (a zip in Cairn's import format, exported from
 * another tracker) into a project. Its archive is analysed (the manifest
 * and what its issues use are kept here), an administrator configures it
 * (`mapping`), then the worker runs it in batches, each one moving the
 * phase and its position on. A batch only acts with the `runToken` of the
 * last start: a message left from an earlier start, or delivered twice,
 * does nothing. What it wrote and what it left out are its ImportRecords.
 * Never deleted: the history of the imports stays.
 */
#[ORM\Entity(repositoryClass: ImportRepository::class)]
#[ORM\Table(name: 'project_import')]
class Import
{
    /** Without a batch for that long, an import in progress is taken as stopped and may be resumed. */
    public const string STALE_AFTER = '10 minutes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The name of the zip in the imports directory. */
    #[ORM\Column(length: 120)]
    private string $file;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(length: 20, enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Analysing;

    /** Whether the analysis went to its end: a start then runs instead of analysing. */
    #[ORM\Column]
    private bool $analysed = false;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phase = null;

    /** Where the phase is: an index in a list, or a position in the issues file. */
    #[ORM\Column]
    private int $position = 0;

    /** How many items the phase went through, out of `total`. */
    #[ORM\Column]
    private int $done = 0;

    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $runToken = null;

    /** @var array<string, mixed> ImportManifest::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $manifest = [];

    /** @var array<string, mixed> ImportAnalysis::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $analysis = [];

    /** @var array<string, mixed> ImportMapping::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $mapping = [];

    /** The number of the first imported issue; the others follow, in the order of the archive. */
    #[ORM\Column(nullable: true)]
    private ?int $firstNumber = null;

    #[ORM\Column]
    private int $commentsWritten = 0;

    #[ORM\Column]
    private int $filesWritten = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The last batch, or the last change: tells a stopped import from a running one. */
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Why it failed, in the language of the instance. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(User $createdBy, string $file, Project $project)
    {
        $this->createdBy = $createdBy;
        $this->file = $file;
        $this->project = $project;
        $this->createdAt = now();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function isAnalysed(): bool
    {
        return $this->analysed;
    }

    public function getPhase(): ?string
    {
        return $this->phase;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDone(): int
    {
        return $this->done;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getRunToken(): ?string
    {
        return $this->runToken;
    }

    /**
     * Starts the analysis, from the beginning each time, or once it is
     * done starts or resumes the run, from the phase and position where
     * it stopped.
     *
     * @param string $firstPhase where the work starts when it never started
     */
    public function start(string $token, string $firstPhase): void
    {
        if ($this->status->isFinished()) {
            throw new \LogicException('An import that is over does not start again.');
        }
        if (!$this->analysed) {
            // An analysis always starts over: the archive may have been fixed since.
            $this->manifest = [];
            $this->analysis = [];
        }
        if (!$this->analysed || null === $this->phase) {
            $this->move($firstPhase, 0, 0, 0);
        }
        $this->status = $this->analysed ? ImportStatus::Running : ImportStatus::Analysing;
        if ($this->analysed) {
            $this->startedAt ??= now();
        }
        $this->runToken = $token;
        $this->error = null;
        $this->finishedAt = null;
        $this->touch();
    }

    /** A batch moved the work on. */
    public function move(string $phase, int $position, int $done, int $total): void
    {
        $this->phase = $phase;
        $this->position = $position;
        $this->done = $done;
        $this->total = $total;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalysis(): array
    {
        return $this->analysis;
    }

    /**
     * @param array<string, mixed>      $analysis
     * @param array<string, mixed>|null $manifest when it was just read
     */
    public function recordAnalysis(array $analysis, ?array $manifest = null): void
    {
        $this->analysis = $analysis;
        $this->manifest = $manifest ?? $this->manifest;
        $this->touch();
    }

    /**
     * The analysis is done: the administrator configures the import.
     *
     * @param array<string, mixed> $mapping the guessed configuration
     */
    public function finishAnalysis(array $mapping): void
    {
        $this->analysed = true;
        $this->mapping = $mapping;
        $this->status = ImportStatus::Configuring;
        $this->phase = null;
        $this->runToken = null;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMapping(): array
    {
        return $this->mapping;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function configure(array $mapping): void
    {
        if (ImportStatus::Configuring !== $this->status) {
            throw new \LogicException('An import is configured between its analysis and its run.');
        }
        $this->mapping = $mapping;
        $this->touch();
    }

    public function getFirstNumber(): ?int
    {
        return $this->firstNumber;
    }

    /** The numbers of the imported issues are reserved in one block, once. */
    public function reserveNumbers(int $firstNumber): void
    {
        if (null !== $this->firstNumber) {
            throw new \LogicException('The numbers of an import are reserved once.');
        }
        $this->firstNumber = $firstNumber;
        $this->touch();
    }

    public function getCommentsWritten(): int
    {
        return $this->commentsWritten;
    }

    public function getFilesWritten(): int
    {
        return $this->filesWritten;
    }

    /** An issue was written with that many comments and files. */
    public function countWritten(int $comments, int $files): void
    {
        $this->commentsWritten += $comments;
        $this->filesWritten += $files;
    }

    public function complete(): void
    {
        $this->status = ImportStatus::Completed;
        $this->phase = null;
        $this->runToken = null;
        $this->finishedAt = now();
        $this->touch();
    }

    public function fail(string $error): void
    {
        $this->status = ImportStatus::Failed;
        $this->error = $error;
        $this->runToken = null;
        $this->finishedAt = now();
        $this->touch();
    }

    /** Gives up an import that no worker is on: what it wrote stays. */
    public function abandon(): void
    {
        if (!$this->isAbandonable()) {
            throw new \LogicException('Only an import waiting or stopped is abandoned.');
        }
        $this->status = ImportStatus::Abandoned;
        $this->runToken = null;
        $this->finishedAt = now();
        $this->touch();
    }

    public function isAbandonable(): bool
    {
        return !$this->status->isFinished() && (!$this->status->isInProgress() || $this->isStale());
    }

    /** Failed, or in progress without any batch for a while: it may be started again. */
    public function isResumable(): bool
    {
        return ImportStatus::Failed === $this->status || $this->isStale();
    }

    /** In progress, without any batch for a while: the worker lost it. */
    public function isStale(): bool
    {
        return $this->status->isInProgress() && $this->updatedAt < now()->modify('-'.self::STALE_AFTER);
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = now();
    }
}
