<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Message\Query\Worker\WorkerStatus;
use App\Tests\MessageHandler\Query\Worker\WorkerQueues;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;

/**
 * The page of the administration that says what waits for the worker.
 * Julie administers the instance, Thomas manages a workspace.
 */
final class WorkerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use WorkerQueues;

    private KernelBrowser $client;
    private ShopScene $scene;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = self::mockTime();
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyAdministratorsReadIt(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', '/admin/worker');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAQuietInstanceShowsNoWarning(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
        $this->client->request('GET', '/admin/projects');
        self::assertSelectorExists('aside a[href="/admin/worker"]');

        $this->client->request('GET', '/admin/worker');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Background tasks');
        self::assertSelectorExists('aside a[href="/admin/worker"][aria-current="page"]');
        self::assertSelectorTextContains('main', 'Nothing waits.');
        self::assertSelectorNotExists('main [role="alert"]');
        self::assertSelectorTextNotContains('main', 'messenger:failed:show');
    }

    public function testMessagesNobodyHandlesRaiseAWarning(): void
    {
        self::asyncTransport()->send(new Envelope(new \stdClass()));
        self::failedTransport()->send(new Envelope(new \stdClass()));
        // The transports date with the real time, a few seconds after the clock froze: half a minute of margin.
        $this->clock->sleep(WorkerStatus::LATE + 150);

        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
        $this->client->request('GET', '/admin/worker');

        self::assertSelectorTextContains('main [role="alert"]', 'No worker seems to be running');
        self::assertSelectorTextContains('main', 'The oldest has waited for 7 minutes.');
        self::assertSelectorTextContains('main', 'messenger:failed:show');
    }
}
