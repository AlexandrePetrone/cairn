<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Issue;

use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\IssueGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The key of a group is written twice: in SQL, for the totals
 * (Repository/IssueOrder::groupKey()), and in PHP, to cut the page into
 * groups (IssueGrouping::keyOf()). Both must agree, or a group would show
 * the count of its page instead of its total. Checked for every grouping
 * on the shop of the scene and across projects.
 */
final class SearchIssuesGroupingTest extends KernelTestCase
{
    public function testTheSqlAndThePhpGiveEveryGroupTheSameKey(): void
    {
        self::bootKernel();
        $scene = ShopScene::load(self::getContainer());
        $scene->intranetBug();
        $queries = self::getContainer()->get(QueryBus::class);
        $viewer = $scene->user('thomas.girard@cairn.test');

        foreach (IssueGrouping::cases() as $grouping) {
            $result = $queries->ask(new SearchIssues($viewer, closed: true, group: $grouping));

            self::assertGreaterThan(1, \count($result->groupTotals), $grouping->value.': several groups');
            self::assertSame($result->total, array_sum($result->groupTotals), $grouping->value.': every issue counted once');
            $groups = IssueGroup::split($result->items, $grouping, $result->groupTotals, []);
            foreach ($groups as $group) {
                self::assertArrayHasKey($group->key, $result->groupTotals, \sprintf('%s: the SQL knows the group "%s"', $grouping->value, $group->key));
                self::assertSame($result->groupTotals[$group->key], $group->total);
            }
            self::assertSame(\count($result->groupTotals), \count($groups), $grouping->value.': each group comes once, its issues together');
        }
    }
}
