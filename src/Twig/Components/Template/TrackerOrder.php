<?php

declare(strict_types=1);

namespace App\Twig\Components\Template;

use App\Config\ConfigRegistry;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateTracker;
use App\Message\Command\Template\UpdateTemplateTracker;
use App\Message\CommandBus;
use App\Repository\TemplateTrackerRepository;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The trackers of a project template in the order the projects made from
 * it will get them: dragging one to another place moves it (the
 * `sortable` controller calls `move`). Each links to its settings.
 */
#[AsLiveComponent('Template:TrackerOrder')]
final class TrackerOrder extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public ProjectTemplate $projectTemplate;

    /** The tracker whose settings the page shows, if any. */
    #[LiveProp]
    public ?int $current = null;

    public function __construct(
        private readonly TemplateTrackerRepository $trackers,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The live endpoint is not under `/admin`, and the command carries no actor.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    /**
     * @return list<TemplateTracker>
     */
    public function getTrackers(): array
    {
        return $this->trackers->findByTemplate($this->projectTemplate);
    }

    /**
     * @return array<string, string> workflow id => label
     */
    public function getWorkflowLabels(): array
    {
        return $this->config->definition()->workflowLabels();
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] int $tracker, #[LiveArg] int $position): void
    {
        $this->error = null;
        if ($this->trackers->find($tracker)?->getTemplate()->getId() !== $this->projectTemplate->getId()) {
            throw $this->createNotFoundException();
        }
        if ($this->tryCommand($commands, new UpdateTemplateTracker($tracker, position: $position))) {
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('project.settings.tracker.moved')]);
        }
    }
}
