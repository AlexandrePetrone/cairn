<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ImportRecordKind;
use App\Entity\Import;
use App\Entity\ImportRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRecord>
 */
class ImportRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRecord::class);
    }

    public function findOne(Import $import, ImportRecordKind $kind, string $remoteId): ?ImportRecord
    {
        return $this->findOneBy(['import' => $import, 'kind' => $kind, 'remoteId' => $remoteId]);
    }

    /**
     * What each id of the archive became: the id in Cairn, null when it
     * was left out.
     *
     * @return array<string, int|null>
     */
    public function localIds(Import $import, ImportRecordKind $kind): array
    {
        $ids = [];
        foreach ($this->createQueryBuilder('record')
            ->select('record.remoteId', 'record.localId')
            ->andWhere('record.import = :import AND record.kind = :kind')
            ->setParameter('import', $import)
            ->setParameter('kind', $kind)
            ->getQuery()
            ->getArrayResult() as $row) {
            $ids[(string) $row['remoteId']] = null === $row['localId'] ? null : (int) $row['localId'];
        }

        return $ids;
    }

    /**
     * How many of each kind were written.
     *
     * @return array<string, int> kind value => count
     */
    public function countDone(Import $import): array
    {
        $counts = array_fill_keys(array_map(static fn (ImportRecordKind $kind): string => $kind->value, ImportRecordKind::cases()), 0);
        foreach ($this->createQueryBuilder('record')
            ->select('record.kind AS kind', 'COUNT(record.localId) AS done')
            ->andWhere('record.import = :import')
            ->setParameter('import', $import)
            ->groupBy('record.kind')
            ->getQuery()
            ->getArrayResult() as $row) {
            $counts[$row['kind'] instanceof ImportRecordKind ? $row['kind']->value : (string) $row['kind']] = (int) $row['done'];
        }

        return $counts;
    }

    /**
     * What the import left out, in the order it met them.
     *
     * @return list<ImportRecord>
     */
    public function findRefused(Import $import): array
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.import = :import AND record.problem IS NOT NULL')
            ->setParameter('import', $import)
            ->orderBy('record.id')
            ->getQuery()
            ->getResult();
    }
}
