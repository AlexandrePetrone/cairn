<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Config;

use App\Message\Query\Config\CountMembershipsByRole;
use App\Message\Query\Config\ShowWorkflowUsage;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ShowWorkflowUsage and CountMembershipsByRole on the scene: the shop has
 * a tracker per workflow, five bugs (two new, one in progress, one to
 * test, one done), and seven memberships.
 */
final class ConfigUsageTest extends KernelTestCase
{
    use KernelHelpers;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheIssuesOfAWorkflowAreCountedByTrackerAndPlace(): void
    {
        $usage = self::queryBus()->ask(new ShowWorkflowUsage('bug'));

        self::assertSame([$this->scene->bugs->getId()], array_map(static fn ($tracker) => $tracker->getId(), $usage->trackers));
        self::assertSame(2, $usage->count($this->scene->bugs, 'new'));
        self::assertSame(1, $usage->count($this->scene->bugs, 'to_test'));
        self::assertSame(0, $usage->count($this->scene->bugs, 'declined'));
        self::assertSame(5, $usage->total($this->scene->bugs));

        self::assertSame([], self::queryBus()->ask(new ShowWorkflowUsage('nonsense'))->trackers);
    }

    public function testMembershipsAreCountedByRole(): void
    {
        self::assertSame(
            ['client' => 2, 'client_readonly' => 1, 'developer' => 3, 'project_manager' => 1],
            self::sorted(self::queryBus()->ask(new CountMembershipsByRole())),
        );
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
