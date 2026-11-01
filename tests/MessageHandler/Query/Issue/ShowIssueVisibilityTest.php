<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Issue\ShowIssue;
use App\Security\AccessControl;
use App\Story\AppStory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * ShowIssueHandler must agree with AccessControl for every user and every
 * issue of the development dataset: the issue itself through canSee(),
 * its comments through canSeeComment(), whose SQL side lives in the
 * handler, and its files through canSeeAttachment().
 */
final class ShowIssueVisibilityTest extends KernelTestCase
{
    use Factories;
    use KernelHelpers;

    public function testSqlAndPhpAgreeOnTheWholeDevelopmentDataset(): void
    {
        self::bootKernel();
        AppStory::load();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $access = self::getContainer()->get(AccessControl::class);
        $users = $entityManager->getRepository(User::class)->findAll();
        $issues = $entityManager->getRepository(Issue::class)->findAll();
        $comments = $entityManager->getRepository(Comment::class)->findAll();
        $attachments = $entityManager->getRepository(Attachment::class)->findAll();

        $partialThreads = 0;
        $hiddenFiles = 0;
        foreach ($users as $user) {
            foreach ($issues as $issue) {
                $detail = self::queryBus()->ask(new ShowIssue($user, (int) $issue->getProject()->getId(), $issue->getNumber()));
                $context = $user->getEmail().' on '.$issue->getKey();

                if (!$access->canSee($user, $issue)) {
                    self::assertNull($detail, $context);
                    continue;
                }
                self::assertNotNull($detail, $context);

                $all = array_filter($comments, static fn (Comment $comment): bool => $comment->getIssue()->getId() === $issue->getId());
                $expected = self::ids(...array_filter($all, static fn (Comment $comment): bool => $access->canSeeComment($user, $comment)));
                $shown = [];
                foreach ($detail->timeline as $entry) {
                    $comment = $entry['comment'] ?? $entry['event']?->getComment();
                    if (null !== $comment) {
                        $shown[] = $comment;
                    }
                }

                self::assertSame($expected, self::ids(...$shown), $context);
                if (\count($expected) < \count($all)) {
                    ++$partialThreads;
                }

                $files = array_filter($attachments, static fn (Attachment $file): bool => $file->getIssue()?->getId() === $issue->getId());
                $visibleFiles = array_filter($files, static fn (Attachment $file): bool => $access->canSeeAttachment($user, $file));
                self::assertSame(self::fileIds(...$visibleFiles), self::fileIds(...$detail->attachments), $context.' (files)');
                $hiddenFiles += \count($files) - \count($visibleFiles);
            }
        }
        self::assertGreaterThan(0, $partialThreads, 'the dataset must hide some private comments from someone');
        self::assertGreaterThan(0, $hiddenFiles, 'the dataset must hide some files of private comments from someone');
    }

    /**
     * @return list<int>
     */
    private static function fileIds(Attachment ...$files): array
    {
        $ids = array_map(static fn (Attachment $file): int => (int) $file->getId(), $files);
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function ids(Comment ...$comments): array
    {
        $ids = array_map(static fn (Comment $comment): int => (int) $comment->getId(), $comments);
        sort($ids);

        return $ids;
    }
}
