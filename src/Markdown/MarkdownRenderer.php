<?php

declare(strict_types=1);

namespace App\Markdown;

use App\Entity\Issue;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the markdown written in descriptions and comments into HTML for one
 * reader. Raw HTML is escaped, unsafe links dropped, links to other sites
 * open in a new tab without a referrer, and only attachments are shown as
 * images: a remote image would log who reads the text. The two references
 * are parsed into nodes, then resolved in one query each: `#MD-SPT-12`
 * becomes a link when the reader may see that issue and stays text
 * otherwise, so that a private issue cannot be probed; `@[Name](user:12)`
 * shows the user's current name. Links and images are paths of the site,
 * as the pages need them; a text read away from it, in a mail, asks for
 * absolute URLs and gets them from the request context, like `absolute_url()`.
 */
final class MarkdownRenderer
{
    private const string ATTACHMENTS_PATH = '/attachments/';

    private readonly MarkdownParser $parser;
    private readonly HtmlRenderer $renderer;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly UrlGeneratorInterface $urls,
        private readonly UrlHelper $urlHelper,
    ) {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => ['open_in_new_window' => true, 'noopener' => 'external', 'noreferrer' => 'external'],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addInlineParser(new IssueReferenceParser());
        $environment->addInlineParser(new MentionParser());
        $environment->addRenderer(Mention::class, new MentionRenderer());

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    public function render(string $markdown, User $reader, bool $absoluteUrls = false): string
    {
        $document = $this->parser->parse($markdown);
        $this->dropRemoteImages($document);
        $this->resolveIssueReferences($document, $reader);
        $this->resolveMentions($document);
        if ($absoluteUrls) {
            $this->absolutizeUrls($document);
        }

        return (string) $this->renderer->renderDocument($document);
    }

    /**
     * Every link and image written as a path of the site gets the site's
     * base: attachments, issue references, links typed by hand.
     */
    private function absolutizeUrls(Document $document): void
    {
        foreach ([...$this->nodes($document, Link::class), ...$this->nodes($document, Image::class)] as $node) {
            if (str_starts_with($node->getUrl(), '/') && !str_starts_with($node->getUrl(), '//')) {
                $node->setUrl($this->urlHelper->getAbsoluteUrl($node->getUrl()));
            }
        }
    }

    /**
     * The people a text mentions, by id, without rendering it: the
     * subscription rules read them when the text is saved.
     *
     * @return list<int>
     */
    public function mentionedUserIds(string $markdown): array
    {
        $ids = [];
        foreach ($this->nodes($this->parser->parse($markdown), Mention::class) as $mention) {
            $ids[$mention->userId] = $mention->userId;
        }

        return array_values($ids);
    }

    private function resolveIssueReferences(Document $document, User $reader): void
    {
        $references = $this->nodes($document, IssueReference::class);
        if ([] === $references) {
            return;
        }

        $visible = [];
        foreach ($this->issues->findBy(['key' => array_column($references, 'key')]) as $issue) {
            if ($this->access->canSee($reader, $issue)) {
                $visible[$issue->getKey()] = $issue;
            }
        }

        foreach ($references as $reference) {
            $issue = $visible[$reference->key] ?? null;
            if (null === $issue || $this->insideLink($reference)) {
                // Invisible, unknown, or written inside a link: no link in a link.
                $reference->replaceWith(new Text('#'.$reference->key));
            } else {
                $reference->replaceWith($this->issueLink($issue));
            }
        }
    }

    /**
     * Only the attachments of this instance are shown as images; another
     * address is left visible as text.
     */
    private function dropRemoteImages(Document $document): void
    {
        foreach ($this->nodes($document, Image::class) as $image) {
            if (!str_starts_with($image->getUrl(), self::ATTACHMENTS_PATH)) {
                $image->replaceWith(new Text($image->getUrl()));
            }
        }
    }

    private function resolveMentions(Document $document): void
    {
        $mentions = $this->nodes($document, Mention::class);
        if ([] === $mentions) {
            return;
        }

        $names = [];
        foreach ($this->users->findBy(['id' => array_column($mentions, 'userId')]) as $user) {
            $names[$user->getId()] = $user->getFullName();
        }

        foreach ($mentions as $mention) {
            $name = $names[$mention->userId] ?? null;
            $mention->replaceWith(null === $name ? new Text('@'.$mention->name) : new Mention($mention->userId, $name));
        }
    }

    private function insideLink(Node $node): bool
    {
        for ($parent = $node->parent(); null !== $parent; $parent = $parent->parent()) {
            if ($parent instanceof Link) {
                return true;
            }
        }

        return false;
    }

    private function issueLink(Issue $issue): Link
    {
        $project = $issue->getProject();
        $url = $this->urls->generate('app_issue_show', [
            'workspaceKey' => $project->getWorkspace()->getKey(),
            'projectKey' => $project->getKey(),
            'number' => $issue->getNumber(),
        ]);
        $link = new Link($url, '#'.$issue->getKey(), $issue->getTitle());
        $link->data->set('attributes/class', 'issue-reference');

        return $link;
    }

    /**
     * @template T of Node
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function nodes(Document $document, string $class): array
    {
        $nodes = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof $class) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
