<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\CodeRepository;
use App\Forge\GitLab\GitLabApi;
use App\Repository\CodeRepositoryRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The repositories page of the shop's settings: Thomas manages Maison
 * Dupont, Nadia develops on the shop. GitLab is a script of answers.
 */
final class RepositoriesTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->scene = ShopScene::load(self::getContainer());
    }

    private function gitlab(string $token, MockResponse ...$responses): void
    {
        $this->gitlabWithHook($token, '', ...$responses);
    }

    /** GitLab with a system hook of the instance when `$hookSecret` is not empty. */
    private function gitlabWithHook(string $token, string $hookSecret, MockResponse ...$responses): void
    {
        self::getContainer()->set(GitLabApi::class, new GitLabApi(new MockHttpClient(array_values($responses)), self::getContainer()->get(UrlGeneratorInterface::class), 'https://gitlab.test', $token, 'https://cairn.test', $hookSecret));
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }

    /**
     * @return list<CodeRepository>
     */
    private function linked(): array
    {
        return self::getContainer()->get(CodeRepositoryRepository::class)->findByProject($this->scene->shop);
    }

    public function testOnlyTheManagerSeesThePageInTheNavigation(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        self::assertResponseStatusCodeSame(403);

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/general');
        self::assertSelectorExists('a[href="/MD/SPT/settings/repositories"]');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No linked repository');
    }

    public function testLinkingInstallsTheWebhookWhenTheInstanceTokenAllowsIt(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]));
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        $this->client->clickLink('Link a repository');
        self::assertRouteSame('app_project_settings_repository_new');
        self::assertSelectorExists('turbo-frame#modal form[action="/MD/SPT/settings/repositories/new"]', 'loaded over the list, the form is still sent to the dialog');

        $this->client->submitForm('Link', ['code_repository[path]' => 'Maison-Dupont/Shop']);

        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $linked = $this->linked();
        self::assertCount(1, $linked);
        self::assertSame('maison-dupont/shop', $linked[0]->getPath(), 'lower case, as deliveries name it');
        self::assertSame('42', $linked[0]->getHookId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'maison-dupont/shop');
        self::assertSelectorTextContains('main', 'Webhook installed');
        self::assertSelectorNotExists('code.select-all', 'nothing to paste once installed');
        self::assertSelectorTextContains('body', 'webhook installed.');
    }

    public function testWithoutATokenTheManagerInstallsByHand(): void
    {
        $this->gitlab('');
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories/new');
        self::assertSelectorTextContains('[role="dialog"]', 'No instance token');

        $this->client->submitForm('Link', ['code_repository[path]' => ' /maison-dupont/shop/ ']);
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $this->client->followRedirect();

        $linked = $this->linked();
        self::assertCount(1, $linked);
        self::assertFalse($linked[0]->isInstalled());
        self::assertSelectorTextContains('body', 'Install the webhook by hand');
        self::assertSelectorTextContains('main', 'Webhook to install');
        self::assertSelectorTextContains('main', 'https://cairn.test/webhook/gitlab');
        self::assertSelectorTextContains('main', $linked[0]->getSecret());
        self::assertSelectorNotExists('button:contains("Install the webhook")', 'no button without a token');

        $this->client->request('GET', '/MD/SPT/settings/repositories/new');
        $this->client->submitForm('Link', ['code_repository[path]' => 'maison-dupont/shop']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="code_repository"]', 'already linked');
    }

    public function testWithAnInstanceHookLinkingIsAllThereIsToDo(): void
    {
        // A token besides: the hook of the instance wins, GitLab is not called.
        $this->gitlabWithHook('glpat-test', 'the-secret-of-the-instance-hook');
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories/new');
        self::assertSelectorTextContains('[role="dialog"]', 'nothing to install');

        $this->client->submitForm('Link', ['code_repository[path]' => 'maison-dupont/shop']);
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $this->client->followRedirect();

        $linked = $this->linked();
        self::assertCount(1, $linked);
        self::assertFalse($linked[0]->isInstalled());
        self::assertSelectorTextContains('body', 'the instance hook delivers it');
        self::assertSelectorTextContains('main', 'Delivered by the instance hook');
        self::assertSelectorNotExists('code.select-all', 'neither an address nor a secret to paste');
        self::assertSelectorTextNotContains('main', $linked[0]->getSecret());
        self::assertSelectorNotExists('button:contains("Install the webhook")');
        self::assertSelectorNotExists('button:contains("Regenerate the secret")');
        self::assertSelectorExists('button:contains("Unlink")');

        $this->client->request('POST', \sprintf('/MD/SPT/settings/repositories/%d/install', $linked[0]->getId()), ['_token' => $this->client->getCrawler()->filter('input[name="_token"]')->attr('value')]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'no webhook to install', 'refused by the command, not by a missing button');
    }

    public function testTheActionsOnALinkedRepository(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]), new MockResponse('{"id": 42}'), new MockResponse('', ['http_code' => 204]), new MockResponse('{"message": "403 Forbidden"}', ['http_code' => 403]), new MockResponse('{"id": 43}', ['http_code' => 201]), new MockResponse('', ['http_code' => 204]));
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories/new');
        $this->client->submitForm('Link', ['code_repository[path]' => 'maison-dupont/shop']);
        $repository = $this->linked()[0];
        $secret = $repository->getSecret();
        $url = \sprintf('/MD/SPT/settings/repositories/%d/', $repository->getId());

        $this->client->request('POST', $url.'unlink');
        self::assertResponseStatusCodeSame(403, 'a POST without its token is refused');
        self::assertCount(1, $this->linked());

        $this->client->request('GET', '/MD/SPT/settings/repositories');
        $this->client->submitForm('Regenerate the secret');
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'New secret');
        self::assertNotSame($secret, $this->linked()[0]->getSecret());

        $this->client->submitForm('Remove the webhook');
        self::assertFalse($this->linked()[0]->isInstalled());

        $this->client->followRedirect();
        $this->client->submitForm('Install the webhook');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The forge refused', 'a refusal is told');
        self::assertFalse($this->linked()[0]->isInstalled());

        $this->client->submitForm('Install the webhook');
        self::assertSame('43', $this->linked()[0]->getHookId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Unlink this repository?', 'unlinking confirms in place');
        $this->client->submitForm('Unlink');
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        self::assertSame([], $this->linked());
    }
}
