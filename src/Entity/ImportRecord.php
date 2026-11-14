<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ImportRecordKind;
use App\Repository\ImportRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\Clock\now;

/**
 * One thing of the archive an import went through: what it became in
 * Cairn (`localId`), or why it was left out (`problem`, in the language
 * of the instance). Unique per import, kind and id in the archive, so a
 * resumed import never writes the same thing twice; the report of an
 * import is read from these lines.
 */
#[ORM\Entity(repositoryClass: ImportRecordRepository::class)]
#[ORM\Table(name: 'import_record')]
#[ORM\UniqueConstraint(name: 'uniq_import_record', columns: ['import_id', 'kind', 'remote_id'])]
class ImportRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Import $import;

    #[ORM\Column(length: 20, enumType: ImportRecordKind::class)]
    private ImportRecordKind $kind;

    #[ORM\Column(length: 100)]
    private string $remoteId;

    #[ORM\Column(nullable: true)]
    private ?int $localId;

    /** What it was in the archive, for the report: a title, a name. */
    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $problem;

    #[ORM\Column]
    private \DateTimeImmutable $at;

    public function __construct(Import $import, ImportRecordKind $kind, string $remoteId, ?int $localId, string $label, ?string $problem = null)
    {
        if ((null === $localId) === (null === $problem)) {
            throw new \InvalidArgumentException('A record is either done, with its id, or refused, with its reason.');
        }
        $this->import = $import;
        $this->kind = $kind;
        $this->remoteId = $remoteId;
        $this->localId = $localId;
        $this->label = mb_substr($label, 0, 255);
        $this->problem = $problem;
        $this->at = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): ImportRecordKind
    {
        return $this->kind;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getLocalId(): ?int
    {
        return $this->localId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getProblem(): ?string
    {
        return $this->problem;
    }
}
