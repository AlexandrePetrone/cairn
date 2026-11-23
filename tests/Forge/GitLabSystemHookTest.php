<?php

declare(strict_types=1);

namespace App\Tests\Forge;

use App\Entity\CodeReference;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A delivery of the system hook of a GitLab instance, end to end: one
 * secret for every repository, set by `FORGE_GITLAB_HOOK_SECRET` before
 * the kernel boots. The shop's repository is linked with a secret of its
 * own, which GitLab does not know.
 */
final class GitLabSystemHookTest extends WebTestCase
{
    private const string HOOK_SECRET = 'the-secret-of-the-instance-hook';

    private KernelBrowser $client;
    private ShopScene $scene;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $async;
    private string|false $secretBefore = false;

    protected function setUp(): void
    {
        $this->secretBefore = $_ENV['FORGE_GITLAB_HOOK_SECRET'] ?? false;
        $_ENV['FORGE_GITLAB_HOOK_SECRET'] = $_SERVER['FORGE_GITLAB_HOOK_SECRET'] = self::HOOK_SECRET;
        $this->client = self::createClient();
        // Several deliveries in one test share the transport and the entity manager.
        $this->client->disableReboot();
        $this->scene = ShopScene::load(self::getContainer());
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new ForgeTestSupport($this->entityManager))->link($this->scene->shop, secret: str_repeat('ab', 20));
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->async->reset();
    }

    protected function tearDown(): void
    {
        if (false === $this->secretBefore) {
            unset($_ENV['FORGE_GITLAB_HOOK_SECRET'], $_SERVER['FORGE_GITLAB_HOOK_SECRET']);
        } else {
            $_ENV['FORGE_GITLAB_HOOK_SECRET'] = $_SERVER['FORGE_GITLAB_HOOK_SECRET'] = $this->secretBefore;
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function deliver(array $body, string $token = self::HOOK_SECRET): void
    {
        $this->client->request('POST', '/webhook/gitlab', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'System Hook',
            'HTTP_X_GITLAB_TOKEN' => $token,
        ], content: (string) json_encode($body));
    }

    /**
     * A push as a system hook sends it: named by `event_name` only.
     *
     * @return array<string, mixed>
     */
    private static function push(): array
    {
        $body = json_decode((string) file_get_contents(__DIR__.'/Fixture/gitlab_push.json'), true, flags: \JSON_THROW_ON_ERROR);
        unset($body['object_kind']);

        return $body;
    }

    /** The worker's turn: every delivery queued is handled in place. */
    private function work(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($this->async->getSent() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
        }
        $this->async->reset();
    }

    public function testAPushOfALinkedRepositoryIsRecorded(): void
    {
        $this->deliver(self::push());

        self::assertResponseStatusCodeSame(202);
        $this->work();

        $references = $this->entityManager->getRepository(CodeReference::class)->findBy(['issue' => $this->scene->issue('MD-SPT-4')], ['id' => 'ASC']);
        self::assertSame(['b6568db1', 'da156088'], array_map(static fn (CodeReference $commit): string => $commit->getShortSha(), $references));
    }

    public function testWhatNobodyLinkedIsAcceptedAndLeavesNothing(): void
    {
        $unlinked = self::push();
        $unlinked['project']['path_with_namespace'] = 'someone/else';
        $this->deliver($unlinked);
        self::assertResponseStatusCodeSame(202, 'a refusal would be one per push of the instance');

        $this->deliver(['event_name' => 'user_create', 'email' => 'someone@gitlab.test', 'name' => 'Someone', 'username' => 'someone', 'user_id' => 41]);
        self::assertResponseStatusCodeSame(202);

        $this->work();
        self::assertSame(0, $this->entityManager->getRepository(CodeReference::class)->count([]), 'no repository links itself');
    }

    public function testAnotherSecretIsRefused(): void
    {
        $this->deliver(self::push(), 'not-the-secret-of-the-instance');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->async->getSent());
    }
}
