<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Forge;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use App\Entity\Enum\MergeRequestState;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Project;
use App\Forge\Event\CommitPayload;
use App\Forge\Event\MergeRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A linked repository, the commits of a push and a merge request, for the
 * forge tests.
 */
final class ForgeTestSupport
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function link(Project $project, string $path = 'maison-dupont/shop', string $secret = 's3cret', Forge $forge = Forge::GitLab): CodeRepository
    {
        $repository = new CodeRepository($project, $forge, $path, $secret);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;
    }

    public static function commit(string $sha, string $title = 'fix(export): paginate the export', string $author = 'Nadia Benali'): CommitPayload
    {
        return new CommitPayload($sha, $title, $title."\n\nSee MD-SPT-1.", 'https://gitlab.test/maison-dupont/shop/-/commit/'.$sha, $author);
    }

    public static function mergeRequest(MergeRequestState $state, int $number = 431, string $title = 'fix(export): paginate the CSV export', string $branch = 'fix/MD-SPT-4-export'): MergeRequestPayload
    {
        return new MergeRequestPayload($number, $title, 'Fixes the export.', 'https://gitlab.test/maison-dupont/shop/-/merge_requests/'.$number, $branch, $state, 'Nadia Benali');
    }

    /**
     * @return list<JournalEntry>
     */
    public function journal(Issue $issue): array
    {
        return $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $issue], ['id' => 'ASC']);
    }
}
