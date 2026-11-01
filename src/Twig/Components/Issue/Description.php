<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Entity\Issue;
use App\Message\Command\Issue\ChangeDescription;
use App\Message\CommandBus;
use App\Security\AccessControl;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The description of the issue page: read, or rewritten in place by the
 * issue's author. A save tells the page (`issue:changed`).
 */
#[AsLiveComponent('Issue:Description')]
final class Description
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Issue $issue;

    #[LiveProp(writable: true)]
    public bool $editingDescription = false;

    /** The description being rewritten. */
    #[LiveProp(writable: true)]
    public string $description = '';

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The issue's author, or whoever may edit the issues of others. */
    public function canEdit(): bool
    {
        return $this->access->canEditIssue($this->viewer->get(), $this->issue);
    }

    #[LiveAction]
    public function editDescription(): void
    {
        $this->editingDescription = $this->canEdit();
        $this->description = $this->issue->getDescription();
    }

    #[LiveAction]
    public function cancelDescription(): void
    {
        $this->editingDescription = false;
    }

    #[LiveAction]
    public function saveDescription(CommandBus $commands): void
    {
        if (!$this->tryCommand($commands, new ChangeDescription((int) $this->viewer->get()->getId(), $this->issue->getKey(), $this->description))) {
            return;
        }

        $this->editingDescription = false;
        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $this->issue->getKey()]));
    }

    /** Redraws with the issue as another component left it. */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }
}
