<?php

declare(strict_types=1);

namespace App\Tests\Markdown;

use App\Entity\User;
use App\Markdown\MarkdownRenderer;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In the scene MD-SPT-2 is a public shop issue, MD-SPT-6 an internal task
 * that Claire, a client, may not see.
 */
final class MarkdownRendererTest extends KernelTestCase
{
    private MarkdownRenderer $renderer;
    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->renderer = self::getContainer()->get(MarkdownRenderer::class);
    }

    public function testMarkdownIsRenderedAndRawHtmlEscaped(): void
    {
        $html = $this->renderer->render("**Bloquant**\n\n- un\n- deux\n\n<script>alert(1)</script>\n\nUn [lien](javascript:alert(1))", $this->user('claire.martin@maison-dupont.test'));

        self::assertStringContainsString('<strong>Bloquant</strong>', $html);
        self::assertStringContainsString('<li>un</li>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('<a>lien</a>', $html);
    }

    public function testLinksToOtherSitesOpenApartAndOnlyAttachmentsAreImages(): void
    {
        $html = $this->renderer->render("[doc](https://docs.example.test/a) [ici](/MD/SPT/issues)\n\n![capture](/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/capture.png) ![pixel](https://tracker.example.test/p.gif)", $this->user('claire.martin@maison-dupont.test'));

        self::assertStringContainsString('<a rel="noopener noreferrer" target="_blank" href="https://docs.example.test/a">doc</a>', $html);
        self::assertStringContainsString('<a href="/MD/SPT/issues">ici</a>', $html);
        self::assertStringContainsString('<img src="/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/capture.png" alt="capture" />', $html);
        self::assertStringNotContainsString('<img src="https://tracker', $html, 'a remote image would log every reader');
        self::assertStringContainsString('https://tracker.example.test/p.gif', $html, 'left as text, nothing is lost');
    }

    public function testAbsoluteUrlsAreMadeForATextReadAwayFromTheSite(): void
    {
        $markdown = "[ici](/MD/SPT/issues) et #MD-SPT-3\n\n![capture](/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/capture.png) [ailleurs](https://docs.example.test/a) [protocole](//cdn.example.test/x)";

        $html = $this->renderer->render($markdown, $this->user('nadia.benali@cairn.test'), absoluteUrls: true);

        self::assertStringContainsString('href="http://localhost/MD/SPT/issues"', $html, 'a link typed as a path');
        self::assertStringContainsString('href="http://localhost/MD/SPT/issues/3"', $html, 'an issue reference');
        self::assertStringContainsString('src="http://localhost/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/capture.png"', $html, 'an attachment');
        self::assertStringContainsString('href="https://docs.example.test/a"', $html, 'another site is left alone');
        self::assertStringContainsString('href="//cdn.example.test/x"', $html, 'a protocol-relative URL too');
    }

    public function testReferencesInCodeStayText(): void
    {
        $html = $this->renderer->render("Tapez `#MD-SPT-2` ou :\n\n```\n#MD-SPT-2 @[Tom](user:1)\n```", $this->user('nadia.benali@cairn.test'));

        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('mention', $html);
        self::assertStringContainsString('<code>#MD-SPT-2</code>', $html);
    }

    public function testAnIssueReferenceIsALinkOnlyForWhoMaySeeTheIssue(): void
    {
        $markdown = 'Comme #MD-SPT-2, voir #MD-SPT-6 et #ZZ-ZZ-1, pas abc#MD-SPT-2.';

        $client = $this->renderer->render($markdown, $this->user('claire.martin@maison-dupont.test'));
        self::assertStringContainsString('<a class="issue-reference" href="/MD/SPT/issues/2"', $client);
        self::assertStringNotContainsString('/MD/SPT/issues/6', $client, 'a private issue cannot be probed');
        self::assertStringContainsString('voir #MD-SPT-6 et #ZZ-ZZ-1', $client);
        self::assertStringContainsString('pas abc#MD-SPT-2.', $client);

        $developer = $this->renderer->render($markdown, $this->user('nadia.benali@cairn.test'));
        self::assertStringContainsString('href="/MD/SPT/issues/6"', $developer);
    }

    public function testAMentionShowsTheCurrentName(): void
    {
        $thomas = $this->user('thomas.girard@cairn.test');

        $html = $this->renderer->render(\sprintf('Pour @[Tom](user:%d) et @[Ancien](user:999999)', $thomas->getId()), $this->user('claire.martin@maison-dupont.test'));

        self::assertStringContainsString('Pour <span class="mention">@Thomas Girard</span>', $html);
        self::assertStringContainsString('et @Ancien', $html, 'an unknown user stays text');
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }
}
