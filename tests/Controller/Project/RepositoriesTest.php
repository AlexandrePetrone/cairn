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
 * The repositories tab of the shop's settings: Thomas manages Maison
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
        self::getContainer()->set(GitLabApi::class, new GitLabApi(new MockHttpClient(array_values($responses)), self::getContainer()->get(UrlGeneratorInterface::class), 'https://gitlab.test', $token, 'https://cairn.test'));
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

    public function testOnlyTheManagerSeesTheTab(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        self::assertResponseStatusCodeSame(403);

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/general');
        self::assertSelectorExists('a[href="/MD/SPT/settings/repositories"]');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun dépôt lié');
    }

    public function testLinkingInstallsTheWebhookWhenTheInstanceTokenAllowsIt(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]));
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');

        $this->client->submitForm('Lier', ['code_repository[path]' => 'Maison-Dupont/Shop']);

        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $linked = $this->linked();
        self::assertCount(1, $linked);
        self::assertSame('maison-dupont/shop', $linked[0]->getPath(), 'lower case, as deliveries name it');
        self::assertSame('42', $linked[0]->getHookId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'maison-dupont/shop');
        self::assertSelectorTextContains('main', 'Webhook installé');
        self::assertSelectorNotExists('code.select-all', 'nothing to paste once installed');
        self::assertSelectorTextContains('body', 'webhook installé.');
    }

    public function testWithoutATokenTheManagerInstallsByHand(): void
    {
        $this->gitlab('');
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        self::assertSelectorTextContains('main', "Aucun jeton d'instance");

        $this->client->submitForm('Lier', ['code_repository[path]' => ' /maison-dupont/shop/ ']);
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $this->client->followRedirect();

        $linked = $this->linked();
        self::assertCount(1, $linked);
        self::assertFalse($linked[0]->isInstalled());
        self::assertSelectorTextContains('body', 'Installez le webhook à la main');
        self::assertSelectorTextContains('main', 'Webhook à installer');
        self::assertSelectorTextContains('main', 'https://cairn.test/webhook/gitlab');
        self::assertSelectorTextContains('main', $linked[0]->getSecret());
        self::assertSelectorNotExists('button:contains("Installer le webhook")', 'no button without a token');

        $this->client->submitForm('Lier', ['code_repository[path]' => 'maison-dupont/shop']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="code_repository"]', 'déjà lié');
    }

    public function testTheActionsOnALinkedRepository(): void
    {
        $this->gitlab('glpat-test', new MockResponse('{"id": 42}', ['http_code' => 201]), new MockResponse('{"id": 42}'), new MockResponse('', ['http_code' => 204]), new MockResponse('{"message": "403 Forbidden"}', ['http_code' => 403]), new MockResponse('{"id": 43}', ['http_code' => 201]), new MockResponse('', ['http_code' => 204]));
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/repositories');
        $this->client->submitForm('Lier', ['code_repository[path]' => 'maison-dupont/shop']);
        $repository = $this->linked()[0];
        $secret = $repository->getSecret();
        $url = \sprintf('/MD/SPT/settings/repositories/%d/', $repository->getId());

        $this->client->request('POST', $url.'unlink');
        self::assertResponseRedirects('/login', message: 'a POST without its token reads as a missing authentication');
        self::assertCount(1, $this->linked());

        $this->client->request('GET', '/MD/SPT/settings/repositories');
        $this->client->submitForm('Régénérer le secret');
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Nouveau secret');
        self::assertNotSame($secret, $this->linked()[0]->getSecret());

        $this->client->submitForm('Retirer le webhook');
        self::assertFalse($this->linked()[0]->isInstalled());

        $this->client->followRedirect();
        $this->client->submitForm('Installer le webhook');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'La forge a refusé', 'a refusal is told');
        self::assertFalse($this->linked()[0]->isInstalled());

        $this->client->submitForm('Installer le webhook');
        self::assertSame('43', $this->linked()[0]->getHookId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Délier ce dépôt ?', 'unlinking confirms in place');
        $this->client->submitForm('Délier');
        self::assertResponseRedirects('/MD/SPT/settings/repositories');
        self::assertSame([], $this->linked());
    }
}
