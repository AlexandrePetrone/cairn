<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Scene\ShopScene;
use App\Twig\ThemeExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class ThemeTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testWithoutAChoiceThePageFollowsTheSystem(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('html[data-theme]');
        self::assertSelectorExists('meta[name="color-scheme"][content="light dark"]');
        self::assertSelectorExists('header [data-controller="theme"] input[name="theme"][value="system"][checked]', 'The user menu offers the choice.');
        self::assertSelectorCount(1, 'header input[name="theme"][checked]');
    }

    public function testTheChoiceKeptByTheBrowserRendersThePage(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));
        $this->client->getCookieJar()->set(new Cookie(ThemeExtension::COOKIE, 'dark'));

        $this->client->request('GET', '/');

        self::assertSelectorExists('html[data-theme="dark"]');
        self::assertSelectorExists('meta[name="color-scheme"][content="dark"]');
        self::assertSelectorExists('header input[name="theme"][value="dark"][checked]');
        self::assertSelectorCount(1, 'header input[name="theme"][checked]');
    }

    public function testAnUnknownChoiceIsTheSystems(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));
        $this->client->getCookieJar()->set(new Cookie(ThemeExtension::COOKIE, 'sepia'));

        $this->client->request('GET', '/');

        self::assertSelectorNotExists('html[data-theme]');
        self::assertSelectorExists('header input[name="theme"][value="system"][checked]');
    }

    public function testThePagesWithoutAnAccountFollowTheChoiceToo(): void
    {
        $this->client->getCookieJar()->set(new Cookie(ThemeExtension::COOKIE, 'light'));

        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-theme="light"]');
    }
}
