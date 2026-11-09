<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConfigSyncCommand;
use App\Config\ConfigLoader;
use App\Config\Sync\ConfigSynchronizer;
use App\Repository\ConfigSnapshotRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigSyncCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private ConfigSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->tester = new CommandTester((new Application($kernel))->find('app:config:sync'));
        $this->snapshots = self::getContainer()->get(ConfigSnapshotRepository::class);
    }

    public function testDryRunShowsTheChangesWithoutApplying(): void
    {
        $exitCode = $this->tester->execute(['--dry-run' => true]);
        $output = $this->tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode, $output);
        self::assertStringContainsString('tests/Config/Fixture/instance.php', $output);
        self::assertStringContainsString('this will be the first one', $output);
        self::assertStringContainsString('+ workflows.bug', $output);
        self::assertStringContainsString('Dry run', $output);
        self::assertNull($this->snapshots->findLatest());
    }

    public function testAFileWrittenForAnotherVersionIsRefusedWithItsLine(): void
    {
        $path = \dirname(__DIR__).'/Config/Fixture/Broken/unknown_parameter.php';
        $tester = new CommandTester(new ConfigSyncCommand(new ConfigLoader($path), self::getContainer()->get(ConfigSynchronizer::class)));

        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode, $output);
        self::assertStringContainsString('(line 14)', $output);
        self::assertStringContainsString('Unknown named parameter $colour', $output);
        self::assertStringContainsString('UPGRADE.md', $output);
        self::assertNull($this->snapshots->findLatest());
    }

    public function testApplyingTwiceIsIdempotent(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());
        self::assertStringContainsString('applied', $this->tester->getDisplay());
        $first = $this->snapshots->findLatest();
        self::assertNotNull($first);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());
        self::assertStringContainsString('Up to date', $this->tester->getDisplay());
        self::assertSame($first->getId(), $this->snapshots->findLatest()?->getId());
    }
}
