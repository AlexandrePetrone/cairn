<?php

declare(strict_types=1);

namespace App\Api\Mapper;

use App\Api\Resource\TrackerResource;
use App\Api\Resource\Value\TrackerField;
use App\Api\Resource\Value\TrackerPlace;
use App\Api\Resource\Value\WorkflowRef;
use App\Config\ConfigRegistry;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Tracker;
use App\Security\Viewer;
use App\Workflow\WorkflowProvider;

/**
 * A tracker with what its workflow asks of an issue: its steps and its
 * fields, the internal ones for internal users only.
 */
final class TrackerMapper
{
    public function __construct(
        private readonly Viewer $viewer,
        private readonly ConfigRegistry $config,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function map(Tracker $tracker): TrackerResource
    {
        $definition = $this->config->definition();
        $workflow = $this->workflows->definition($tracker->getWorkflowId());
        $internal = $this->viewer->get()->isInternal();

        $places = [];
        foreach ($definition->placesOf($workflow) as $place) {
            $places[] = new TrackerPlace($place->id, $place->label, $place->id === $workflow->initial, PlaceStatus::Closed === $place->status);
        }
        $fields = [];
        foreach ($workflow->fields as $usage) {
            $field = $definition->field($usage->field);
            if ($internal || !$field->internalOnly) {
                $fields[] = new TrackerField($field->id, $field->label, $field->type->value, $usage->required, $field->internalOnly, $field->type->hasOptions() ? $field->options : null, $field->help);
            }
        }

        return new TrackerResource((int) $tracker->getId(), $tracker->getName(), $tracker->getDescription(), $tracker->isPrivate(), $tracker->isArchived(), new WorkflowRef($workflow->id, $workflow->label), $places, $fields);
    }
}
