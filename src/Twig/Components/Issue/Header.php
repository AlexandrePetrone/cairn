<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Repository\JournalEntryRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The top of the issue page, redrawn when another component of the page
 * changes the issue (`issue:changed`): privacy and the path the issue
 * took follow. The title is edited here by whoever may edit the issue.
 */
#[AsLiveComponent('Issue:Header')]
final class Header
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editingTitle = false;

    /** The title being written. */
    #[LiveProp(writable: true)]
    public string $title = '';

    public function __construct(
        private readonly WorkflowProvider $workflows,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
        private readonly JournalEntryRepository $journal,
    ) {
    }

    public function getPlace(): ?PlaceDefinition
    {
        return $this->workflows->placeOf($this->issue);
    }

    /**
     * The places the issue went through, as the journal tells it with the
     * labels of the time: where it started, then every place it entered.
     * A step entered without an action of the workflow (a forced place, a
     * change of tracker) is marked.
     *
     * @return list<array{label: string, moved: bool}>
     */
    public function getPath(): array
    {
        $entries = $this->journal->findPlaceChanges($this->issue);
        $start = ($entries[0] ?? null)?->text('from') ?: ($this->getPlace()->label ?? $this->issue->getPlace());

        $steps = [['label' => $start, 'moved' => false]];
        foreach ($entries as $entry) {
            $steps[] = ['label' => $entry->text('to'), 'moved' => JournalEntryType::Transitioned !== $entry->getType()];
        }

        return $steps;
    }

    public function canEdit(): bool
    {
        return $this->access->canEditIssue($this->viewer->get(), $this->issue);
    }

    #[LiveAction]
    public function editTitle(): void
    {
        $this->editingTitle = $this->canEdit();
        $this->title = $this->issue->getTitle();
    }

    #[LiveAction]
    public function cancelTitle(): void
    {
        $this->editingTitle = false;
    }

    #[LiveAction]
    public function saveTitle(CommandBus $commands): void
    {
        $command = new UpdateIssue((int) $this->viewer->get()->getId(), $this->issue->getKey(), title: $this->title);
        if (!$this->tryCommand($commands, $command, $this->translator->trans('issue.edit.title_invalid'))) {
            return;
        }

        $this->editingTitle = false;
        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $this->issue->getKey()]));
    }

    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}
