<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Attachment>
 */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Attachment
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * The files of an issue itself, not those of its comments, oldest first.
     *
     * @return list<Attachment>
     */
    public function findByIssue(Issue $issue): array
    {
        return $this->findBy(['issue' => $issue, 'comment' => null], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The uuids of every attachment, as the files are named on disk.
     *
     * @return list<string>
     */
    public function findAllUuids(): array
    {
        // Array hydration converts the column through UuidType; scalar hydration would hand back the raw bytes.
        $rows = $this->createQueryBuilder('a')->select('a.uuid')->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['uuid'], $rows));
    }

    /**
     * Pending attachments sent before a date: abandoned with their draft.
     *
     * @return list<Attachment>
     */
    public function findPendingBefore(\DateTimeImmutable $before): array
    {
        $query = $this->createQueryBuilder('a')
            ->where('a.issue IS NULL')
            ->andWhere('a.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery();

        return EntityList::of($query, Attachment::class);
    }

    /**
     * Attachments a user sent to a project that no issue claimed yet.
     *
     * @param list<Uuid> $uuids
     *
     * @return list<Attachment>
     */
    public function findUnattached(Project $project, User $uploadedBy, array $uuids): array
    {
        if ([] === $uuids) {
            return [];
        }

        return $this->findBy(['project' => $project, 'uploadedBy' => $uploadedBy, 'issue' => null, 'uuid' => $uuids]);
    }
}
