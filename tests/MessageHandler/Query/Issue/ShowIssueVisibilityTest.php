<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Entity\User;
use App\Message\Query\Issue\ListLinks;
use App\Message\Query\Issue\ShowIssue;
use App\Security\AccessControl;
use App\Story\AppStory;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ShowIssueHandler must agree with AccessControl for every user and every
 * issue of the development dataset: the issue itself through canSee(),
 * its comments through canSeeComment(), whose SQL side lives in the
 * handler, and its files through canSeeAttachment(). ListLinks agrees
 * with canSee() on the other end of each link.
 */
final class ShowIssueVisibilityTest extends KernelTestCase
{
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
        $links = $entityManager->getRepository(IssueLink::class)->findAll();

        $partialThreads = 0;
        $hiddenFiles = 0;
        $hiddenLinks = 0;
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
                    if (null !== $entry->comment) {
                        $shown[] = $entry->comment;
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

                $ofIssue = array_filter($links, static fn (IssueLink $link): bool => $link->getSource() === $issue || $link->getTarget() === $issue);
                $visibleLinks = array_filter($ofIssue, static fn (IssueLink $link): bool => $access->canSee($user, $link->otherThan($issue)));
                self::assertSame(self::linkIds(...$visibleLinks), self::linkIds(...self::queryBus()->ask(new ListLinks($user, $issue))), $context.' (links)');
                $hiddenLinks += \count($ofIssue) - \count($visibleLinks);
            }
        }
        self::assertGreaterThan(0, $partialThreads, 'the dataset must hide some private comments from someone');
        self::assertGreaterThan(0, $hiddenFiles, 'the dataset must hide some files of private comments from someone');
        self::assertGreaterThan(0, $hiddenLinks, 'the dataset must hide some links from someone');
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
    private static function linkIds(IssueLink ...$links): array
    {
        $ids = array_map(static fn (IssueLink $link): int => (int) $link->getId(), $links);
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
