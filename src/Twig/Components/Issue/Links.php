<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Issue;
use App\Entity\IssueLink;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\RemoveLink;
use App\Message\CommandBus;
use App\Message\Query\Issue\ListLinks;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The links of an issue, a card of the side panel of its page, grouped by the
 * label read from this issue (Blocks, Blocked by…); only those whose other
 * end the viewer sees (ListLinks). Whoever holds `issue.link` adds one,
 * choosing the label from this side and the other issue among the
 * project's or by its key, and removes one after confirming in place.
 */
#[AsLiveComponent('Issue:Links')]
final class Links
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    /** The other issue shown first in the choice of the link to add. */
    private const int SUGGESTIONS = 6;

    #[LiveProp]
    public Issue $issue;

    /** Whether the form of a new link is open. */
    #[LiveProp(writable: true)]
    public bool $adding = false;

    /**
     * The label chosen from this issue: a link type id, read from this
     * issue, or `<id>:inverse` for the inverse label of an asymmetric
     * type, which links the other way.
     */
    #[LiveProp(writable: true)]
    public string $kind = '';

    /** The key of the other issue, or the words to find it among the project's. */
    #[LiveProp(writable: true)]
    public string $otherKey = '';

    /** The link whose removal waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $removingId = null;

    /** @var list<IssueLink>|null read once per render */
    private ?array $links = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function canLink(): bool
    {
        return [] !== $this->config->definition()->links
            && $this->access->isGranted($this->viewer->get(), Permission::IssueLink, $this->issue->getProject());
    }

    /**
     * The links grouped by their label seen from this issue, in the order
     * they were made.
     *
     * @return array<string, list<IssueLink>> label => links
     */
    public function getGroups(): array
    {
        $groups = [];
        foreach ($this->links() as $link) {
            $groups[$this->labelOf($link)][] = $link;
        }

        return $groups;
    }

    /**
     * The labels a new link can take from this issue: each type's label,
     * then the inverse label of the asymmetric ones.
     *
     * @return array<string, string> kind => label
     */
    public function getKinds(): array
    {
        $kinds = [];
        foreach ($this->config->definition()->links as $type) {
            $kinds[$type->id] = $type->label;
            if (!$type->isSymmetric()) {
                $kinds[$type->id.':inverse'] = $type->labelFromTarget();
            }
        }

        return $kinds;
    }

    /**
     * Issues of the project matching what is typed, for the choice of the
     * other end; a key typed in full is taken as it is.
     *
     * @return list<Issue>
     */
    public function getSuggestions(): array
    {
        if (!$this->adding || '' === trim($this->otherKey)) {
            return [];
        }
        $page = $this->queries->ask(new SearchIssues($this->viewer->get(), projectId: $this->issue->getProject()->getId(), q: $this->otherKey, closed: true));
        $others = array_filter($page->items, fn (Issue $issue): bool => $issue !== $this->issue);

        return \array_slice(array_values($others), 0, self::SUGGESTIONS);
    }

    #[LiveAction]
    public function open(): void
    {
        $this->adding = true;
        $this->kind = array_key_first($this->getKinds()) ?? '';
        $this->otherKey = '';
        $this->error = null;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->adding = false;
        $this->error = null;
    }

    /**
     * Picks a suggested issue as the other end.
     */
    #[LiveAction]
    public function pick(#[LiveArg] string $key): void
    {
        $this->otherKey = $key;
    }

    #[LiveAction]
    public function add(CommandBus $commands): void
    {
        if (!isset($this->getKinds()[$this->kind]) || '' === trim($this->otherKey)) {
            $this->error = $this->translator->trans('issue.links.choose');

            return;
        }
        [$typeId, $inverse] = str_ends_with($this->kind, ':inverse') ? [substr($this->kind, 0, -\strlen(':inverse')), true] : [$this->kind, false];
        $actorId = (int) $this->viewer->get()->getId();
        $otherKey = mb_strtoupper(trim($this->otherKey));
        $command = $inverse ? new AddLink($actorId, $otherKey, $typeId, $this->issue->getKey()) : new AddLink($actorId, $this->issue->getKey(), $typeId, $otherKey);

        if ($this->tryCommand($commands, $command, $this->translator->trans('issue.links.choose'))) {
            $this->adding = false;
            $this->links = null;
            $this->announce($this->translator->trans('issue.links.added', ['%key%' => $otherKey]));
        }
    }

    #[LiveAction]
    public function confirmRemove(#[LiveArg] int $id): void
    {
        $this->removingId = $id;
    }

    #[LiveAction]
    public function cancelRemove(): void
    {
        $this->removingId = null;
    }

    #[LiveAction]
    public function remove(CommandBus $commands): void
    {
        if (null === $this->removingId) {
            return;
        }
        $removed = $this->tryCommand($commands, new RemoveLink((int) $this->viewer->get()->getId(), $this->removingId));
        $this->removingId = null;
        if ($removed) {
            $this->links = null;
            $this->announce($this->translator->trans('issue.links.removed'));
        }
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
        $this->links = null;
    }

    /**
     * @return list<IssueLink>
     */
    private function links(): array
    {
        return $this->links ??= $this->queries->ask(new ListLinks($this->viewer->get(), $this->issue));
    }

    /**
     * The label of a link read from this issue.
     */
    private function labelOf(IssueLink $link): string
    {
        $type = $this->config->definition()->link($link->getTypeId());

        return $link->getSource() === $this->issue ? $type->label : $type->labelFromTarget();
    }
}
