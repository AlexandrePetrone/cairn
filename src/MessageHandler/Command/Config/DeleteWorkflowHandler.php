<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Workflow;
use App\Message\Command\Config\DeleteWorkflow;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;
use App\Repository\TransitionRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class DeleteWorkflowHandler
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly TrackerRepository $trackers,
        private readonly TransitionRepository $transitions,
        private readonly TemplateTrackerRepository $templateTrackers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(DeleteWorkflow $command): void
    {
        $workflow = $this->writing->get(Workflow::class, $command->id);

        // A tracker follows its workflow for good: while one does, of a project or of a template, the workflow stays.
        $trackers = $this->trackers->countByWorkflow()[$workflow->getId()] ?? 0;
        if ($trackers > 0) {
            throw ConfigChangeException::workflowFollowed($workflow->getLabel(), $trackers);
        }
        $templates = $this->templateTrackers->templatesByWorkflow()[$workflow->getId()] ?? [];
        if ([] !== $templates) {
            throw ConfigChangeException::workflowInTemplates($workflow->getLabel(), $templates);
        }

        ListOrder::close($workflow, $this->workflows->findOrdered());
        // Its actions, places and fields go with it.
        array_map($this->writing->removeTransition(...), $this->transitions->findByWorkflow($workflow));
        array_map($this->entityManager->remove(...), $this->workflows->placesOf($workflow));
        array_map($this->entityManager->remove(...), $this->workflows->fieldsOf($workflow));
        $this->entityManager->remove($workflow);
        $this->writing->settle('workflow.deleted', ['%name%' => $workflow->getLabel()]);
    }
}
