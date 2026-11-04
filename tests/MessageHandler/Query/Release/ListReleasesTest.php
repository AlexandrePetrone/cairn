<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Release;

use App\Entity\Enum\ReleaseStatus;
use App\Message\Command\Release\UpdateRelease;
use App\Message\Query\Release\ListReleases;
use App\Message\Query\Release\ReleaseSummary;
use App\Message\QueryBus;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ListReleases on the shop of the scene: MD-SPT-6 is an internal task,
 * MD-SPT-5 is closed.
 */
final class ListReleasesTest extends KernelTestCase
{
    use KernelHelpers;

    public function testOpenReleasesComeFirstWithTheirIssuesCountedAsTheViewerSeesThem(): void
    {
        self::bootKernel();
        $scene = ShopScene::load(self::getContainer());
        $delivered = $scene->release('2.4.0', ['MD-SPT-5']);
        self::commandBus()->dispatch(new UpdateRelease((int) $scene->user('thomas.girard@cairn.test')->getId(), (int) $delivered->getId(), status: ReleaseStatus::Delivered));
        $scene->release('2.5.0', ['MD-SPT-3', 'MD-SPT-4', 'MD-SPT-6']);

        $forThomas = $this->list($scene, 'thomas.girard@cairn.test');
        self::assertSame(['2.5.0', '2.4.0'], array_map(static fn (ReleaseSummary $summary): string => $summary->release->getName(), $forThomas));
        self::assertSame(3, $forThomas[0]->total());
        self::assertSame(['open' => 0, 'in_progress' => 2, 'waiting' => 1, 'closed' => 0], $forThomas[0]->byStatus, 'the internal task is in progress');
        self::assertSame(100, $forThomas[1]->progress());

        $forClaire = $this->list($scene, 'claire.martin@maison-dupont.test');
        self::assertSame(2, $forClaire[0]->total(), 'the client does not count the internal task');
        self::assertSame(0, $forClaire[0]->progress());
    }

    /**
     * @return list<ReleaseSummary>
     */
    private function list(ShopScene $scene, string $email): array
    {
        return self::getContainer()->get(QueryBus::class)->ask(new ListReleases($scene->user($email), $scene->shop));
    }
}
