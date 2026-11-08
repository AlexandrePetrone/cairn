<?php

declare(strict_types=1);

namespace App\Tests\Forge;

use App\Entity\CodeReference;
use App\Entity\Enum\MergeRequestState;
use App\Entity\JournalEntry;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A delivery from GitLab, end to end: the webhook answers at once, the
 * worker records the references. The shop's repository has the secret
 * `SECRET`.
 */
final class GitLabWebhookTest extends WebTestCase
{
    private const string SECRET = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private KernelBrowser $client;
    private ShopScene $scene;
    private EntityManagerInterface $entityManager;
    private InMemoryTransport $async;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Several deliveries in one test share the transport and the entity manager.
        $this->client->disableReboot();
        $this->scene = ShopScene::load(self::getContainer());
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new ForgeTestSupport($this->entityManager))->link($this->scene->shop, secret: self::SECRET);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
        $this->async->reset();
    }

    private function deliver(string $fixture, string $token = self::SECRET, ?callable $change = null): void
    {
        $body = json_decode((string) file_get_contents(__DIR__.'/Fixture/'.$fixture.'.json'), true);
        if (null !== $change) {
            $body = $change($body);
        }
        $this->client->request('POST', '/webhook/gitlab', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITLAB_EVENT' => 'Push Hook',
            'HTTP_X_GITLAB_TOKEN' => $token,
        ], content: (string) json_encode($body));
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

    /**
     * @return list<CodeReference>
     */
    private function references(string $key): array
    {
        return $this->entityManager->getRepository(CodeReference::class)->findBy(['issue' => $this->scene->issue($key)], ['id' => 'ASC']);
    }

    public function testAPushIsAcceptedAtOnceAndRecordedByTheWorker(): void
    {
        $this->deliver('gitlab_push');

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'no session for a forge');
        self::assertCount(1, $this->async->getSent(), 'queued for the worker');
        self::assertSame([], $this->references('MD-SPT-4'), 'nothing written yet');

        $this->work();

        self::assertSame(['b6568db1', 'da156088'], array_map(static fn (CodeReference $commit): string => $commit->getShortSha(), $this->references('MD-SPT-4')));
        self::assertCount(1, $this->references('MD-SPT-1'));
        self::assertCount(1, $this->entityManager->getRepository(JournalEntry::class)->findBy(['issue' => $this->scene->issue('MD-SPT-4'), 'private' => true, 'author' => null]), 'the foreign key NC-CAT-3 and the commit without a key left no trace');
    }

    public function testAMergeRequestFollowsItsState(): void
    {
        $this->deliver('gitlab_merge_request');
        $this->work();
        $this->deliver('gitlab_merge_request', change: static function (array $body): array {
            $body['object_attributes']['state'] = 'merged';

            return $body;
        });
        $this->work();

        $references = $this->references('MD-SPT-4');
        self::assertCount(1, $references);
        self::assertTrue($references[0]->isMergeRequest());
        self::assertSame('431', $references[0]->getExternalId());
        self::assertSame(MergeRequestState::Merged, $references[0]->getState());
        self::assertSame([], $this->references('MD-SPT-1'), 'the merge request mentions MD-SPT-4 only');
    }

    public function testAWrongTokenOrAnUnlinkedRepositoryIsRefused(): void
    {
        $this->deliver('gitlab_push', str_repeat('0', 40));
        self::assertResponseStatusCodeSame(403);

        $this->deliver('gitlab_push', change: static function (array $body): array {
            $body['project']['path_with_namespace'] = 'someone/else';

            return $body;
        });
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->async->getSent());

        $this->client->request('POST', '/webhook/bitbucket', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(404);
    }
}
