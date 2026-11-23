<?php

declare(strict_types=1);

namespace App\Tests\Forge\GitLab;

use App\Forge\Event\PushReceived;
use App\Forge\GitLab\GitLabRequestParser;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * What the parser makes of a delivery; the token and the repository are
 * checked end to end by GitLabWebhookTest. The second argument of `parse()`
 * is the secret of the instance hook, empty when the instance has none.
 */
final class GitLabRequestParserTest extends KernelTestCase
{
    private const string HOOK_SECRET = 'the-secret-of-the-instance-hook';

    private GitLabRequestParser $parser;
    private AccessFixtures $access;
    private ForgeTestSupport $forge;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->parser = self::getContainer()->get(GitLabRequestParser::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->access = new AccessFixtures($entityManager);
        $this->forge = new ForgeTestSupport($entityManager);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../Fixture/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private static function request(array|string $body, string $token = 's3cret', string $method = 'POST'): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITLAB_EVENT' => 'Push Hook', 'HTTP_X_GITLAB_TOKEN' => $token, 'HTTP_X_GITLAB_EVENT_UUID' => 'd1f2'];

        return Request::create('/webhook/gitlab', $method, server: $server, content: \is_string($body) ? $body : (string) json_encode($body));
    }

    public function testAPushBecomesOneEventPerLinkThatAcceptsTheToken(): void
    {
        $secret = str_repeat('ab', 20);
        $shop = $this->forge->link($this->access->shop, secret: $secret);
        $this->forge->link($this->access->intranet, secret: str_repeat('cd', 20));
        $this->forge->link($this->access->otherProject, secret: $secret);

        $events = $this->parser->parse(self::request(self::fixture('gitlab_push'), $secret), '');

        self::assertIsArray($events);
        self::assertCount(2, $events, 'the shop and the other project share the secret, the intranet does not');
        self::assertContainsOnlyInstancesOf(PushReceived::class, $events);
        $event = $events[0];
        self::assertSame($shop->getId(), $event->codeRepositoryId);
        self::assertSame('main', $event->branch);
        self::assertSame('https://gitlab.test/maison-dupont/shop/-/tree/main', $event->branchUrl);
        self::assertCount(4, $event->commits);
        self::assertSame('fix(export): paginate the CSV export', $event->commits[0]->title);
        self::assertStringContainsString('Closes MD-SPT-4', $event->commits[0]->message);
        self::assertSame('Léa Fontaine', $event->commits[1]->authorName);
    }

    public function testAMalformedTokenIsRefusedBeforeTheBodyIsRead(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));

        try {
            $this->parser->parse(self::request('{not json', 'wrong'), '');
            self::fail('A malformed token went through.');
        } catch (RejectWebhookException $e) {
            self::assertSame(403, $e->getStatusCode(), 'not the 406 a malformed body gives once authenticated');
        }
    }

    public function testOddPayloadsAreTamedNotTrusted(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));
        $body = self::fixture('gitlab_push');
        $body['ref'] = 'refs/heads/fix/"quoted" <branch>';
        $body['commits'][0]['author'] = 'not an object';
        $body['commits'][0]['title'] = ['not', 'a', 'string'];
        $body['commits'][1]['message'] = "with a NUL\0byte";
        $body['commits'][1]['url'] = 'javascript:alert(1)';
        $body['commits'][2] = 'not a commit';
        $body['commits'] = array_merge($body['commits'], array_fill(0, 120, ['id' => 'f', 'title' => 'x', 'message' => 'x', 'url' => 'u', 'author' => ['name' => 'a']]));

        $events = $this->parser->parse(self::request($body, str_repeat('ab', 20)), '');

        self::assertIsArray($events);
        self::assertInstanceOf(PushReceived::class, $events[0]);
        self::assertSame('https://gitlab.test/maison-dupont/shop/-/tree/fix/%22quoted%22%20%3Cbranch%3E', $events[0]->branchUrl);
        self::assertSame('', $events[0]->commits[0]->authorName);
        self::assertSame('', $events[0]->commits[0]->title);
        self::assertSame('with a NULbyte', $events[0]->commits[1]->message);
        self::assertSame('', $events[0]->commits[1]->url, 'an address that is not of the web is no link');
        self::assertStringStartsWith('https://', $events[0]->commits[0]->url);
        self::assertLessThanOrEqual(100, \count($events[0]->commits), 'no more than a hundred commits are read');
    }

    public function testAKindCairnDoesNotReadIsAcceptedAndIgnored(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));
        $body = self::fixture('gitlab_push');
        $body['object_kind'] = 'tag_push';

        $events = $this->parser->parse(self::request($body, str_repeat('ab', 20)), '');

        self::assertIsArray($events);
        self::assertCount(1, $events);
        self::assertSame('ignored', $events[0]->getName());
    }

    public function testTheInstanceHookStandsForEveryLinkOfTheRepository(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));
        $this->forge->link($this->access->otherProject, secret: str_repeat('cd', 20));
        $this->forge->link($this->access->intranet, path: 'maison-dupont/intranet', secret: str_repeat('ef', 20));
        // A system hook names a push by `event_name` only.
        $body = self::fixture('gitlab_push');
        unset($body['object_kind']);

        $events = $this->parser->parse(self::request($body, self::HOOK_SECRET), self::HOOK_SECRET);

        self::assertIsArray($events);
        self::assertCount(2, $events, 'whatever the secret of each link, and not the link of another repository');
        self::assertContainsOnlyInstancesOf(PushReceived::class, $events);
    }

    public function testTheInstanceHookAlsoDeliversWhatNobodyLinkedWhichIsIgnored(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));
        $unlinked = self::fixture('gitlab_push');
        $unlinked['project']['path_with_namespace'] = 'someone/else';
        $account = ['event_name' => 'user_create', 'email' => 'someone@gitlab.test', 'name' => 'Someone', 'username' => 'someone', 'user_id' => 41];
        $odd = ['event_name' => 'push', 'project' => 'maison-dupont/shop'];

        foreach ([$unlinked, $account, $odd] as $body) {
            $events = $this->parser->parse(self::request($body, self::HOOK_SECRET), self::HOOK_SECRET);

            self::assertIsArray($events);
            self::assertCount(1, $events);
            self::assertSame('ignored', $events[0]->getName(), 'accepted, and nothing made of it');
        }
    }

    public function testTheSecretOfALinkStillWorksBesideTheInstanceHook(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));
        $this->forge->link($this->access->otherProject, secret: str_repeat('cd', 20));

        $events = $this->parser->parse(self::request(self::fixture('gitlab_push'), str_repeat('ab', 20)), self::HOOK_SECRET);

        self::assertIsArray($events);
        self::assertCount(1, $events, 'the hook of a repository speaks for the links that have its secret only');

        try {
            $this->parser->parse(self::request(self::fixture('gitlab_push'), 'not-the-secret-of-the-instance'), self::HOOK_SECRET);
            self::fail('A wrong secret went through.');
        } catch (RejectWebhookException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function testTheSecretOfTheInstanceIsComparedWithoutTheBlanksAroundIt(): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));

        $events = $this->parser->parse(self::request(self::fixture('gitlab_push'), self::HOOK_SECRET), self::HOOK_SECRET."\n");

        self::assertIsArray($events);
        self::assertContainsOnlyInstancesOf(PushReceived::class, $events);
    }

    #[TestWith([''])]
    #[TestWith([" \n"])]
    public function testWithoutAnInstanceHookItsSecretOpensNothing(string $configured): void
    {
        $this->forge->link($this->access->shop, secret: str_repeat('ab', 20));

        foreach ([self::HOOK_SECRET, ''] as $token) {
            try {
                $this->parser->parse(self::request(self::fixture('gitlab_push'), $token), $configured);
                self::fail('A delivery went through without the secret of a link.');
            } catch (RejectWebhookException $e) {
                self::assertSame(403, $e->getStatusCode(), 'an empty secret is no secret');
            }
        }
    }

    public function testARequestWithoutGitLabHeadersIsNotAWebhook(): void
    {
        $request = Request::create('/webhook/gitlab', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $this->expectException(RejectWebhookException::class);
        $this->parser->parse($request, '');
    }
}
