<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Query\Project;

use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\CountProjectIssues;
use App\Message\Query\Project\ListTrackers;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The counts of a project's navigation are the totals of its lists as
 * they come: the open issues the viewer sees, whoever they are.
 */
final class CountProjectIssuesTest extends KernelTestCase
{
    use KernelHelpers;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        yield 'an internal project manager' => ['thomas.girard@cairn.test'];
        yield 'a developer' => ['nadia.benali@cairn.test'];
        yield 'a client' => ['claire.martin@maison-dupont.test'];
    }

    #[DataProvider('viewers')]
    public function testEachCountIsTheTotalOfItsListWithoutFilter(string $email): void
    {
        $viewer = $this->scene->user($email);
        $shop = $this->scene->shop;
        $queries = self::queryBus();

        $counts = $queries->ask(new CountProjectIssues($viewer, $shop));

        self::assertGreaterThan(0, $counts->all);
        self::assertSame($queries->ask(new SearchIssues($viewer, projectId: $shop->getId()))->total, $counts->all);
        self::assertSame($queries->ask(new SearchIssues($viewer, projectId: $shop->getId(), assigneeId: $viewer->getId()))->total, $counts->assigned);
        self::assertSame($queries->ask(new SearchIssues($viewer, projectId: $shop->getId(), authorId: $viewer->getId()))->total, $counts->created);
        foreach ($queries->ask(new ListTrackers($viewer, $shop)) as $tracker) {
            self::assertSame(
                $queries->ask(new SearchIssues($viewer, projectId: $shop->getId(), tracker: [(int) $tracker->getId()]))->total,
                $counts->of($tracker),
                $tracker->getName(),
            );
        }
    }

    public function testAClientDoesNotCountWhatTheyDoNotSee(): void
    {
        $shop = $this->scene->shop;
        $queries = self::queryBus();

        $client = $queries->ask(new CountProjectIssues($this->scene->user('claire.martin@maison-dupont.test'), $shop));
        $internal = $queries->ask(new CountProjectIssues($this->scene->user('thomas.girard@cairn.test'), $shop));

        self::assertLessThan($internal->all, $client->all, 'private issues and private trackers stay out');
        self::assertSame(0, $client->of($this->scene->tasks), 'a private tracker counts nothing for a client');
        self::assertGreaterThan(0, $internal->of($this->scene->tasks));
    }
}
