<?php

declare(strict_types=1);

namespace App\Automation\Definition;

use App\Config\Definition\WorkflowDefinition;
use App\Workflow\PlaceCatalog;

/**
 * Where a rule is written, and so what it may name: the trackers it can
 * be limited to, each with its workflow, the roles whose referent it can
 * aim at, and the people it can name. A project gives one
 * (RuleContexts::ofProject()); a project template gives its own
 * (ofTemplate()), without people. The checker and the form read nothing
 * else, so a rule is edited the same way in both.
 */
final readonly class RuleContext
{
    /**
     * @param array<array-key, string>             $trackers  tracker key => name (a tracker id reads as an integer key)
     * @param array<array-key, WorkflowDefinition> $workflows tracker key => its workflow
     * @param array<string, string>                $roles     role id => label
     * @param array<int, string>                   $people    user id => full name; empty where nobody can be named
     */
    public function __construct(
        public array $trackers,
        public array $workflows,
        public array $roles,
        public array $people = [],
    ) {
    }

    /**
     * The workflow of the tracker a rule is limited to, null without one.
     */
    public function workflow(?string $tracker): ?WorkflowDefinition
    {
        return null === $tracker ? null : $this->workflows[$tracker] ?? null;
    }

    /**
     * The places a rule may meet, under their workflow: those of the
     * workflow of the tracker it is limited to, or of every tracker
     * without one. A place belongs to one workflow, so two workflows
     * with a place of the same name have two.
     *
     * @param string|null $tracker the key of the tracker the rule is limited to
     *
     * @return array<string, array<string, string>> workflow label => place key (PlaceCatalog::key()) => place label
     */
    public function places(?string $tracker): array
    {
        $places = [];
        foreach (null === $tracker ? $this->workflows : array_filter([$this->workflow($tracker)]) as $workflow) {
            foreach ($workflow->places as $place) {
                $places[$workflow->label][PlaceCatalog::key($workflow->id, $place->id)] = $place->label;
            }
        }

        return $places;
    }

    /**
     * The same places, each under a name that says whose it is where
     * several workflows are in reach ("Bug › In progress"): what a place
     * reads as on its own, in a sentence or a closed list.
     *
     * @return array<string, string> place key => label
     */
    public function placeLabels(?string $tracker): array
    {
        $groups = $this->places($tracker);
        $labels = [];
        foreach ($groups as $workflow => $places) {
            foreach ($places as $key => $label) {
                $labels[$key] = 1 === \count($groups) ? $label : $workflow.' › '.$label;
            }
        }

        return $labels;
    }
}
