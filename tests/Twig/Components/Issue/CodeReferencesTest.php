<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\MergeRequestState;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * On MD-SPT-4 of the scene, with a repository linked to the shop: Nadia
 * develops on it, Claire is its client.
 */
final class CodeReferencesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use KernelHelpers;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $repository = (new ForgeTestSupport(self::getContainer()->get(EntityManagerInterface::class)))->link($this->scene->shop);
        $commands = self::commandBus();
        $id = (int) $repository->getId();
        $commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', ForgeTestSupport::mergeRequest(MergeRequestState::Opened)));
        $commands->dispatch(new RecordMergeRequest($id, 'MD-SPT-4', ForgeTestSupport::mergeRequest(MergeRequestState::Closed, 420, 'chore: old attempt', 'old/MD-SPT-4')));
        $commands->dispatch(new RecordCommits($id, 'MD-SPT-4', 'main', 'https://gitlab.test/maison-dupont/shop/-/tree/main', array_map(static fn (int $i) => ForgeTestSupport::commit(\sprintf('%012d', $i)), range(1, 7))));
        $commands->dispatch(new RecordCommits($id, 'MD-SPT-4', 'fix/MD-SPT-4-export', 'https://gitlab.test/maison-dupont/shop/-/tree/fix', [ForgeTestSupport::commit('9c8d7e6f5a4b', 'wip')]));
    }

    private function render(string $key, string $email): Crawler
    {
        return new Crawler((string) $this->createLiveComponent('Issue:CodeReferences', ['issue' => $this->scene->issue($key)])->actingAs($this->scene->user($email))->render());
    }

    public function testADeveloperReadsTheMergeRequestsAndTheCommitsByBranch(): void
    {
        $crawler = $this->render('MD-SPT-4', 'nadia.benali@cairn.test');
        $summary = $crawler->filter('summary')->text();
        self::assertStringContainsString('!431', $summary, 'folded, the header shows the first merge request');
        self::assertStringContainsString('Ouverte', $summary);
        self::assertStringNotContainsString('!420', $summary);
        $text = $crawler->text();

        self::assertStringContainsString('!431', $text);
        self::assertStringContainsString('Ouverte', $text);
        self::assertStringContainsString('!420', $text);
        self::assertStringContainsString('Fermée', $text);
        self::assertLessThan(strpos($text, '!420'), strpos($text, '!431'), 'open merge requests come first');
        self::assertStringContainsString('fix/MD-SPT-4-export', $text);
        self::assertStringContainsString('9c8d7e6f', $text);
        self::assertCount(5, $crawler->filter('a[href^="https://gitlab.test/maison-dupont/shop/-/commit/0000"]'), 'five commits of main shown');
        self::assertStringContainsString('+2', $text, 'the other two counted');
        self::assertStringNotContainsString('hidden', (string) $crawler->filter('div')->first()->attr('class'));
    }

    public function testAClientGetsNothingEvenIfThePageAskedForIt(): void
    {
        $crawler = $this->render('MD-SPT-4', 'claire.martin@maison-dupont.test');

        self::assertStringNotContainsString('!431', $crawler->text());
        self::assertStringContainsString('hidden', (string) $crawler->filter('div')->first()->attr('class'));
    }

    public function testAnIssueWithoutCodeShowsNoBlock(): void
    {
        $crawler = $this->render('MD-SPT-1', 'nadia.benali@cairn.test');

        self::assertStringContainsString('hidden', (string) $crawler->filter('div')->first()->attr('class'));
    }
}
