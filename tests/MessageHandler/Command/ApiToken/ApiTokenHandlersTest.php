<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\ApiToken;

use App\Entity\ApiToken;
use App\Entity\Enum\ApiTokenScope;
use App\Message\Command\ApiToken\CreateApiToken;
use App\Message\Command\ApiToken\NewApiToken;
use App\Message\Command\ApiToken\RevokeApiToken;
use App\Message\CommandBus;
use App\MessageHandler\Command\ApiToken\ApiTokenException;
use App\Repository\ApiTokenRepository;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApiTokenHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private CommandBus $commands;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testATokenIsCreatedWithASecretKeptNowhere(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);

        $new = $this->commands->dispatch(new CreateApiToken((int) $nadia->getId(), '  Deploy script ', ApiTokenScope::Write, new \DateTimeImmutable('2026-04-01')));

        self::assertInstanceOf(NewApiToken::class, $new);
        self::assertMatchesRegularExpression(ApiToken::PATTERN, $new->secret);
        self::assertSame('Deploy script', $new->token->getName());
        self::assertSame(ApiTokenScope::Write, $new->token->getScope());
        self::assertSame($nadia, $new->token->getUser());
        self::assertTrue($new->token->isUsable(new \DateTimeImmutable('2026-03-31 23:59:59')));
        self::assertFalse($new->token->isUsable(new \DateTimeImmutable('2026-04-01 00:00:00')));

        $tokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertSame($new->token, $tokens->findOneBySecret($new->secret));
        self::assertNull($tokens->findOneBySecret(ApiToken::newSecret()));
    }

    public function testTheApiIsForInternalUsers(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new CreateApiToken((int) $claire->getId(), 'Script'));
    }

    public function testATokenNeedsANameAndAFutureExpiry(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);

        try {
            $this->commands->dispatch(new CreateApiToken((int) $nadia->getId(), 'Script', expiresAt: new \DateTimeImmutable('2026-03-03')));
            self::fail('A token expiring in the past was created.');
        } catch (ApiTokenException) {
        }

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateApiToken((int) $nadia->getId(), '   '));
    }

    public function testATokenIsRevokedByItsOwnerOnly(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $new = $this->commands->dispatch(new CreateApiToken((int) $nadia->getId(), 'Script'));
        self::assertInstanceOf(NewApiToken::class, $new);

        try {
            $this->commands->dispatch(new RevokeApiToken((int) $thomas->getId(), (int) $new->token->getId()));
            self::fail('Someone revoked the token of someone else.');
        } catch (ApiTokenException) {
            self::assertFalse($new->token->isRevoked());
        }

        $this->commands->dispatch(new RevokeApiToken((int) $nadia->getId(), (int) $new->token->getId()));
        self::assertTrue($new->token->isRevoked());
        self::assertFalse($new->token->isUsable(new \DateTimeImmutable('2026-03-03 10:00:01')));
        self::assertEquals(new \DateTimeImmutable('2026-03-03 10:00:00'), $new->token->getRevokedAt());

        // Revoking again changes nothing.
        self::mockTime('2026-03-04 10:00:00');
        $this->commands->dispatch(new RevokeApiToken((int) $nadia->getId(), (int) $new->token->getId()));
        self::assertEquals(new \DateTimeImmutable('2026-03-03 10:00:00'), $new->token->getRevokedAt());
    }
}
