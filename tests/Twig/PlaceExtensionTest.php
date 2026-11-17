<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Factory\TrackerFactory;
use App\Factory\UserFactory;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Query\Issue\IssueBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

final class PlaceExtensionTest extends KernelTestCase
{
    use Factories;
    use KernelHelpers;

    public function testRendersTheLabelOfThePlaceFromTheConfiguration(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $issue = (new IssueBuilder(self::getContainer()->get(EntityManagerInterface::class)))->issue(TrackerFactory::createOne(), UserFactory::createOne(), 'Export', 'to_test');

        $html = self::getContainer()->get(Environment::class)->createTemplate('{{ issue_place(issue) }}')->render(['issue' => $issue]);

        self::assertSame('To test', $html);
    }
}
