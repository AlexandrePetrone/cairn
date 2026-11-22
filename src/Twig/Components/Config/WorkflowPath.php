<?php

declare(strict_types=1);

namespace App\Twig\Components\Config;

use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Message\Command\Config\UpdateWorkflow;
use App\Message\CommandBus;
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
 * The diagram of a workflow and, next to it, what decides its shape: the
 * steps taken off the path its issues follow, each with the step of the
 * path it is reached from (WorkflowDefinition::$aside). A step leaves the
 * path, changes where it is reached from or goes back on the path in one
 * click, and the diagram is drawn again: what a choice means is seen, not
 * read. Every change is an UpdateWorkflow, which says what is refused.
 */
#[AsLiveComponent('Config:WorkflowPath')]
final class WorkflowPath extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public string $workflow;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The live endpoint is not under `/admin`, and the commands carry no actor.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    public function getDefinition(): WorkflowDefinition
    {
        return $this->config->definition()->workflow($this->workflow);
    }

    /**
     * The steps off the path, in the order of the workflow, each with the
     * step of the path it is reached from.
     *
     * @return list<array{place: PlaceDefinition, from: PlaceDefinition}>
     */
    public function getOffPath(): array
    {
        $definition = $this->config->definition();
        $workflow = $this->getDefinition();

        $steps = [];
        foreach ($definition->placesOf($workflow) as $place) {
            if (isset($workflow->aside[$place->id])) {
                $steps[] = ['place' => $place, 'from' => $definition->place($workflow->aside[$place->id])];
            }
        }

        return $steps;
    }

    /**
     * The steps of the path, in their order: where a step off the path may
     * be reached from.
     *
     * @return list<PlaceDefinition>
     */
    public function getPath(): array
    {
        $workflow = $this->getDefinition();

        return array_values(array_filter($this->config->definition()->placesOf($workflow), static fn (PlaceDefinition $place): bool => !isset($workflow->aside[$place->id])));
    }

    /**
     * The steps that may leave the path: not the one the issues start on,
     * nor one another step is reached from.
     *
     * @return list<PlaceDefinition>
     */
    public function getMovable(): array
    {
        $workflow = $this->getDefinition();

        return array_values(array_filter(
            $this->getPath(),
            static fn (PlaceDefinition $place): bool => $place->id !== $workflow->initial && !\in_array($place->id, $workflow->aside, true),
        ));
    }

    /**
     * Takes a step off the path, reached from the step of the path that
     * comes before it: the likeliest, changed in a click when it is not.
     */
    #[LiveAction]
    public function takeOff(CommandBus $commands, #[LiveArg] string $place): void
    {
        // Only what the menu offers: a step already off the path is reached from where it was said.
        if (!array_any($this->getMovable(), static fn (PlaceDefinition $step): bool => $step->id === $place)) {
            return;
        }
        $from = null;
        foreach ($this->getPath() as $step) {
            if ($step->id === $place) {
                break;
            }
            $from = $step->id;
        }
        $this->attach($commands, $place, $from ?? $this->getDefinition()->initial);
    }

    /**
     * Says which step of the path a step off it is reached from.
     */
    #[LiveAction]
    public function attach(CommandBus $commands, #[LiveArg] string $place, #[LiveArg] string $from): void
    {
        $this->tryCommand($commands, new UpdateWorkflow($this->workflow, aside: [$place => $from] + $this->getDefinition()->aside));
    }

    #[LiveAction]
    public function putBack(CommandBus $commands, #[LiveArg] string $place): void
    {
        $this->tryCommand($commands, new UpdateWorkflow($this->workflow, aside: array_diff_key($this->getDefinition()->aside, [$place => true])));
    }
}
