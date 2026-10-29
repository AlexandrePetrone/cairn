<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Message\Command\Issue\ChangeVisibility;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

final class HeaderTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    public function testTheHeaderFollowsAChangeMadeElsewhere(): void
    {
        self::bootKernel();
        AppStory::load();
        $issue = self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4') ?? self::fail('no MD-SPT-4');
        $thomas = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'thomas.girard@cairn.test']) ?? self::fail('no Thomas');
        $component = $this->createLiveComponent('Issue:Header', ['issue' => $issue])->actingAs($thomas);

        $before = (string) $component->render();
        self::assertStringContainsString('La correction est en préproduction', $before, 'the hint of « À recetter »');
        self::assertStringNotContainsString('Privée', $before);

        self::getContainer()->get(CommandBus::class)->dispatch(new ChangeVisibility((int) $thomas->getId(), 'MD-SPT-4', true));
        $component->emit('issue:changed');

        self::assertStringContainsString('Privée', (string) $component->render());
    }
}
