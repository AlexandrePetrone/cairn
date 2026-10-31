<?php

declare(strict_types=1);

namespace App\Tests\Markdown;

use App\Entity\User;
use App\Markdown\MarkdownRenderer;
use App\Repository\UserRepository;
use App\Story\AppStory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * On the development dataset: MD-SPT-2 is a public shop issue, MD-SPT-18
 * an internal task that Claire, a client, may not see.
 */
final class MarkdownRendererTest extends KernelTestCase
{
    use Factories;

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        AppStory::load();
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

    public function testReferencesInCodeStayText(): void
    {
        $html = $this->renderer->render("Tapez `#MD-SPT-2` ou :\n\n```\n#MD-SPT-2 @[Tom](user:1)\n```", $this->user('nadia.benali@cairn.test'));

        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('mention', $html);
        self::assertStringContainsString('<code>#MD-SPT-2</code>', $html);
    }

    public function testAnIssueReferenceIsALinkOnlyForWhoMaySeeTheIssue(): void
    {
        $markdown = 'Comme #MD-SPT-2, voir #MD-SPT-18 et #ZZ-ZZ-1, pas abc#MD-SPT-2.';

        $client = $this->renderer->render($markdown, $this->user('claire.martin@maison-dupont.test'));
        self::assertStringContainsString('<a class="issue-reference" href="/MD/SPT/issues/2"', $client);
        self::assertStringNotContainsString('/MD/SPT/issues/18', $client, 'a private issue cannot be probed');
        self::assertStringContainsString('voir #MD-SPT-18 et #ZZ-ZZ-1', $client);
        self::assertStringContainsString('pas abc#MD-SPT-2.', $client);

        $developer = $this->renderer->render($markdown, $this->user('nadia.benali@cairn.test'));
        self::assertStringContainsString('href="/MD/SPT/issues/18"', $developer);
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
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]) ?? self::fail('no '.$email);
    }
}
