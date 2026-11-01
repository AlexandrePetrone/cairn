<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Story\AppStory;
use App\Twig\Components\Issue\Description;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Zenstruck\Foundry\Test\Factories;

/**
 * The description of MD-SPT-4, which Claire wrote; Nadia may edit the issue
 * but did not write it.
 */
final class DescriptionTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
        $this->issue = self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4') ?? self::fail('no MD-SPT-4');
    }

    public function testTheAuthorRewritesTheDescriptionInPlace(): void
    {
        $component = $this->description('claire.martin@maison-dupont.test');

        $component->call('editDescription')->set('description', 'Le fichier ne contient que l\'en-tête.')->call('saveDescription');
        $render = $component->render();

        self::assertStringContainsString('<p>Le fichier ne contient que l\'en-tête.</p>', (string) $render);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame('Le fichier ne contient que l\'en-tête.', self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-4')?->getDescription());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }

    public function testTheAuthorOrAManagerRewritesTheDescription(): void
    {
        self::assertStringContainsString('data-live-action-param="editDescription"', (string) $this->description('claire.martin@maison-dupont.test')->render());
        self::assertStringContainsString('data-live-action-param="editDescription"', (string) $this->description('thomas.girard@cairn.test')->render(), 'issue.edit');

        $developer = $this->description('nadia.benali@cairn.test');
        self::assertStringNotContainsString('data-live-action-param="editDescription"', (string) $developer->render(), 'Nadia did not write it and may not edit the issues of others');
        $developer->call('editDescription');
        $description = $developer->component();
        self::assertInstanceOf(Description::class, $description);
        self::assertFalse($description->editingDescription);
    }

    private function description(string $email): TestLiveComponent
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $this->createLiveComponent('Issue:Description', ['issue' => $this->issue])->actingAs($user);
    }
}
