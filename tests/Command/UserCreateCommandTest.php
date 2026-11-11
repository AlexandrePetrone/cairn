<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

final class UserCreateCommandTest extends KernelTestCase
{
    use Factories;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester((new Application(self::bootKernel()))->find('app:user:create'));
    }

    public function testCreatesAnAdministratorWhoCanSignIn(): void
    {
        $exitCode = $this->answer(['Julie@Agency.test', 'Julie', 'Morel', '', 'Administrator', 'correct horse', 'correct horse']);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'julie@agency.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isAdmin());
        self::assertTrue($user->isInternal());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct horse'));
        self::assertNull($user->getCompany());
    }

    public function testAnExternalAccountIsTheDefaultType(): void
    {
        self::assertSame(Command::SUCCESS, $this->answer(['claire@client.test', 'Claire', 'Martin', 'Maison Dupont', '', 'correct horse', 'correct horse']));
        $claire = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'claire@client.test']);
        self::assertFalse($claire?->isInternal());
        self::assertSame('Maison Dupont', $claire?->getCompany());
    }

    public function testRefusesDifferentPasswords(): void
    {
        self::assertSame(Command::FAILURE, $this->answer(['nadia@agency.test', 'Nadia', 'Benali', '', 'Internal', 'correct horse', 'correct house']));
        self::assertStringContainsString('differ', $this->tester->getDisplay());
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'nadia@agency.test']));
    }

    public function testRefusesATakenEmail(): void
    {
        UserFactory::createOne(['email' => 'claire@client.test']);

        self::assertSame(Command::FAILURE, $this->answer(['claire@client.test', 'Claire', 'Martin', '', '', 'correct horse', 'correct horse']));
        self::assertStringContainsString('already uses', $this->tester->getDisplay());
    }

    public function testRefusesAnInvalidEmailAndAShortPassword(): void
    {
        self::assertSame(Command::FAILURE, $this->answer(['not-an-email', 'Claire', 'Martin', '', '', 'short', 'short']));
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('email', $display);
        self::assertStringContainsString('plainPassword', $display);
    }

    public function testRefusesToRunWithoutInteraction(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute([], ['interactive' => false]));
    }

    /**
     * @param list<string> $answers
     */
    private function answer(array $answers): int
    {
        $this->tester->setInputs($answers);

        return $this->tester->execute([], ['interactive' => true]);
    }
}
