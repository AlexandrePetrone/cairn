<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Forge;

use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use App\Forge\GitLab\GitLabApi;
use App\Message\Command\Forge\LinkRepository;
use App\Message\Command\Forge\UnlinkRepository;
use App\Message\Command\Forge\UpdateRepository;
use App\Message\CommandBus;
use App\MessageHandler\Command\Forge\CodeRepositoryException;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Linking, installing, regenerating and unlinking, with GitLab as a
 * script of answers: what is sent to it, and what a refusal leaves.
 */
final class UpdateRepositoryHandlerTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private EntityManagerInterface $entityManager;
    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
    }

    private function gitlab(string $token, MockResponse ...$responses): void
    {
        $this->gitlabWithHook($token, '', ...$responses);
    }

    /** GitLab with a system hook of the instance when `$hookSecret` is not empty. */
    private function gitlabWithHook(string $token, string $hookSecret, MockResponse ...$responses): void
    {
        $this->responses = array_values($responses);
        self::getContainer()->set(GitLabApi::class, new GitLabApi(new MockHttpClient($this->responses), self::getContainer()->get(UrlGeneratorInterface::class), 'https://gitlab.test/', $token, 'https://cairn.test', $hookSecret));
    }

    private function link(?int $actorId = null, string $path = 'maison-dupont/shop'): CodeRepository
    {
        return $this->commands->dispatch(new LinkRepository((int) $this->fixtures->access->shop->getId(), Forge::GitLab, $path, $actorId));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $index): array
    {
        return json_decode((string) $this->responses[$index]->getRequestOptions()['body'], true);
    }

    public function testTheManagerLinksARepositoryWithASecretOfItsOwn(): void
    {
        $repository = $this->link((int) $this->fixtures->manager->getId(), '/Maison-Dupont/Shop/');

        self::assertSame('maison-dupont/shop', $repository->getPath(), 'lower case, as deliveries name it');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $repository->getSecret());
        self::assertFalse($repository->isInstalled());

        $this->expectException(CodeRepositoryException::class);
        $this->expectExceptionMessage('already linked');
        $this->link((int) $this->fixtures->manager->getId());
    }

    public function testADeveloperCannotLinkARepository(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->link((int) $this->fixtures->developer->getId());
    }

    public function testInstallingCreatesTheHookOnGitLab(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]));
        $repository = $this->link();

        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true, actorId: (int) $this->fixtures->manager->getId()));

        self::assertSame('42', $repository->getHookId());
        self::assertSame('POST', $this->responses[0]->getRequestMethod());
        self::assertSame('https://gitlab.test/api/v4/projects/maison-dupont%2Fshop/hooks', $this->responses[0]->getRequestUrl());
        self::assertSame(['url' => 'https://cairn.test/webhook/gitlab', 'token' => $repository->getSecret(), 'push_events' => true, 'merge_requests_events' => true, 'enable_ssl_verification' => true], $this->body(0));
        self::assertContains('PRIVATE-TOKEN: glpat-test', $this->responses[0]->getRequestOptions()['headers']);
    }

    public function testWithoutAnInstanceTokenInstallationIsRefused(): void
    {
        $this->gitlab('');
        $repository = $this->link();

        $this->expectException(CodeRepositoryException::class);
        $this->expectExceptionMessage('No instance token');
        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true));
    }

    public function testWithAnInstanceHookNoWebhookIsInstalledAndAnOldOneIsStillRemoved(): void
    {
        $this->gitlabWithHook('glpat-test', 'the-secret-of-the-instance-hook', new MockResponse('', ['http_code' => 204]));
        // Linked before the instance had its hook: this one still has a webhook of its own.
        $repository = $this->link();
        $repository->markInstalled('42');
        $this->entityManager->flush();
        $other = $this->link(path: 'maison-dupont/intranet');

        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: false));
        self::assertFalse($repository->isInstalled());
        self::assertSame('DELETE', $this->responses[0]->getRequestMethod());

        try {
            $this->commands->dispatch(new UpdateRepository((int) $other->getId(), installed: true));
            self::fail('A webhook was installed beside the instance hook.');
        } catch (CodeRepositoryException $e) {
            self::assertStringContainsString('instance hook', $e->getMessage());
        }
        self::assertFalse($other->isInstalled());
    }

    public function testAskingAgainForAnInstalledWebhookChangesNothingEvenWithAnInstanceHook(): void
    {
        $this->gitlabWithHook('glpat-test', 'the-secret-of-the-instance-hook');
        $repository = $this->link();
        $repository->markInstalled('42');
        $this->entityManager->flush();

        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true));

        self::assertSame('42', $repository->getHookId(), 'GitLab was not called: it had no answer to give');
    }

    public function testWithoutTheInstanceTokenAnInstalledRepositoryIsStillRemovedAndUnlinked(): void
    {
        // The token is gone since the webhooks were installed: GitLab cannot be told, and is not called.
        $this->gitlabWithHook('', 'the-secret-of-the-instance-hook');
        $repository = $this->link();
        $repository->markInstalled('42');
        $other = $this->link(path: 'maison-dupont/intranet');
        $other->markInstalled('43');
        $this->entityManager->flush();
        $otherId = (int) $other->getId();

        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: false));
        self::assertFalse($repository->isInstalled());

        $this->commands->dispatch(new UnlinkRepository($otherId));
        self::assertNull($this->entityManager->find(CodeRepository::class, $otherId));
    }

    public function testABlankSecretIsNoInstanceHook(): void
    {
        $this->gitlabWithHook('', " \n");
        $repository = $this->link();

        $this->expectException(CodeRepositoryException::class);
        $this->expectExceptionMessage('No instance token');
        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true));
    }

    public function testARefusalOfGitLabLeavesTheLinkAsItWas(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"message": "403 Forbidden"}', ['http_code' => 403]));
        $repository = $this->link();
        $secret = $repository->getSecret();

        try {
            $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true, regenerateSecret: true));
            self::fail('The refusal was not reported.');
        } catch (CodeRepositoryException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }
        $this->entityManager->refresh($repository);
        self::assertFalse($repository->isInstalled());
        self::assertSame($secret, $repository->getSecret(), 'the transaction rolled the new secret back');
    }

    public function testRegeneratingTellsGitLabTheWholeHookFirst(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]), new MockResponse('{"id": 42}'));
        $repository = $this->link();
        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true));
        $before = $repository->getSecret();

        $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), regenerateSecret: true));

        self::assertNotSame($before, $repository->getSecret());
        self::assertSame('PUT', $this->responses[1]->getRequestMethod());
        self::assertSame('https://gitlab.test/api/v4/projects/maison-dupont%2Fshop/hooks/42', $this->responses[1]->getRequestUrl());
        self::assertSame('https://cairn.test/webhook/gitlab', $this->body(1)['url'], 'GitLab requires the url on an edit');
        self::assertSame($repository->getSecret(), $this->body(1)['token']);
    }

    public function testRemovingAndUnlinkingDeleteTheHook(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]), new MockResponse('', ['http_code' => 204]), new MockResponse('{"id": 43}', ['http_code' => 201]), new MockResponse('', ['http_code' => 404]));
        $repository = $this->link();
        $id = (int) $repository->getId();
        $this->commands->dispatch(new UpdateRepository($id, installed: true));

        $this->commands->dispatch(new UpdateRepository($id, installed: false));
        self::assertFalse($repository->isInstalled());
        self::assertSame('DELETE', $this->responses[1]->getRequestMethod());

        $this->commands->dispatch(new UpdateRepository($id, installed: true));
        $this->commands->dispatch(new UnlinkRepository($id));
        self::assertSame('DELETE', $this->responses[3]->getRequestMethod(), 'a hook already gone is not an error');
        self::assertNull($this->entityManager->find(CodeRepository::class, $id));
    }
}
