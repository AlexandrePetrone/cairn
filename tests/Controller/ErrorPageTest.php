<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Api\ApiTestCase;
use App\Tests\Scene\ShopScene;
use App\Twig\ThemeExtension;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The page a person reads when a request fails, as an instance shows it:
 * the kernel runs without debug, where the framework no longer draws the
 * exception.
 */
final class ErrorPageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAnUnknownAddressAnswersThePageOfCairnToWhoeverIsNotSignedIn(): void
    {
        $this->client->request('GET', '/no/such/page');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Page not found');
        self::assertSelectorTextContains('main', 'This page does not exist, or you may not see it.');
        self::assertSelectorTextContains('main', 'Error 404');
        self::assertSelectorExists('main a[href="/"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertSelectorNotExists('header', 'The page draws no navigation: it reads neither the user nor the database.');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Nor the session.');
    }

    public function testAPageRefusedToASignedInUserSaysSo(): void
    {
        $this->client->loginUser($this->scene->user(self::DEVELOPER));

        $this->client->request('GET', '/admin/users');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextSame('h1', 'Access denied');
        self::assertSelectorExists('main a[href="/"]');
    }

    public function testThePageFollowsTheThemeKeptByTheBrowser(): void
    {
        $this->client->getCookieJar()->set(new Cookie(ThemeExtension::COOKIE, 'dark'));

        $this->client->request('GET', '/no/such/page');

        self::assertSelectorExists('html[data-theme="dark"]');
    }

    public function testEveryOtherFailureHasItsWords(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        $failure = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 500, 'status_text' => 'Internal Server Error']);
        self::assertStringContainsString('Something went wrong', $failure);
        self::assertStringContainsString('The problem is on our side', $failure);

        $unavailable = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 503, 'status_text' => 'Service Unavailable']);
        self::assertStringContainsString('Error 503', $unavailable);
        self::assertStringContainsString('This request could not be handled.', $unavailable);
    }

    public function testThePageIsWrittenInBothLanguages(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['code', 'home', '403.title', '403.text', '404.title', '404.text', '500.title', '500.text', 'other.title', 'other.text'] as $key) {
            foreach (['en', 'fr'] as $locale) {
                self::assertNotSame('error.'.$key, $translator->trans('error.'.$key, [], null, $locale), \sprintf('error.%s has no %s text.', $key, $locale));
            }
        }
        self::assertSame('Page introuvable', $translator->trans('error.404.title', [], null, 'fr'));
    }

    public function testAProgramIsStillAnsweredInJson(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $this->api('GET', '/api/issues/MD-SPT-999', $secret);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        // An address no operation answers is the framework's to refuse, in the format asked for.
        $unknown = $this->api('GET', '/api/no/such/resource', $secret);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(404, $unknown['status']);

        $this->client->request('GET', '/mcp/no/such/address', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertJson((string) $this->client->getResponse()->getContent());
    }
}
