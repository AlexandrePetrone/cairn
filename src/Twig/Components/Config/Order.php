<?php

declare(strict_types=1);

namespace App\Twig\Components\Config;

use App\Config\ConfigRegistry;
use App\Config\Definition\InstanceDefinition;
use App\Message\Command\Config\UpdateField;
use App\Message\Command\Config\UpdateLinkType;
use App\Message\Command\Config\UpdatePlace;
use App\Message\Command\Config\UpdateRole;
use App\Message\Command\Config\UpdateTransition;
use App\Message\Command\Config\UpdateWorkflow;
use App\Message\CommandBus;
use App\Message\Query\Config\CountMembershipsByRole;
use App\Message\QueryBus;
use App\Repository\IssueLinkRepository;
use App\Repository\IssueRepository;
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
 * A list of the configuration in the order people give it: the roles,
 * the steps, the fields and the link types of the instance, the steps and
 * the actions of a workflow. Dragging a row to another place moves it (the
 * `sortable` controller calls `move`); each row says what it is in a line
 * and leads to the dialog that changes it. One component for all of them:
 * `kind` says which list, `workflow` whose when it is one of a workflow.
 */
#[AsLiveComponent('Config:Order')]
final class Order extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    public const string ROLES = 'roles';
    public const string PLACES = 'places';
    public const string FIELDS = 'fields';
    public const string LINKS = 'links';
    public const string WORKFLOW_PLACES = 'workflow_places';
    public const string TRANSITIONS = 'transitions';

    #[LiveProp]
    public string $kind;

    #[LiveProp]
    public ?string $workflow = null;

    /** What to say when the list has no row. */
    #[LiveProp]
    public string $empty = '';

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly QueryBus $queries,
        private readonly IssueRepository $issues,
        private readonly IssueLinkRepository $links,
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

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    public function getRows(): array
    {
        $definition = $this->config->definition();

        return match ($this->kind) {
            self::ROLES => $this->roles($definition),
            self::PLACES => $this->places($definition),
            self::FIELDS => $this->fields($definition),
            self::LINKS => $this->linkTypes($definition),
            self::WORKFLOW_PLACES => $this->workflowPlaces($definition),
            self::TRANSITIONS => $this->transitions($definition),
            default => throw $this->createNotFoundException(),
        };
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $id, #[LiveArg] int $position): void
    {
        $command = match ($this->kind) {
            self::ROLES => new UpdateRole($id, position: $position),
            self::PLACES => new UpdatePlace($id, position: $position),
            self::FIELDS => new UpdateField($id, position: $position),
            self::LINKS => new UpdateLinkType($id, position: $position),
            self::WORKFLOW_PLACES => new UpdateWorkflow((string) $this->workflow, places: $this->placesWith($id, $position)),
            self::TRANSITIONS => new UpdateTransition((string) $this->workflow, $id, position: $position),
            default => throw $this->createNotFoundException(),
        };

        if ($this->tryCommand($commands, $command)) {
            $this->emit('config:changed');
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.configuration.moved')]);
        }
    }

    /**
     * @return list<string> the places of the workflow, the one moved at its new position
     */
    private function placesWith(string $moved, int $position): array
    {
        $places = array_values(array_diff($this->config->definition()->workflow((string) $this->workflow)->places, [$moved]));
        array_splice($places, min($position, \count($places)), 0, [$moved]);

        return $places;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function roles(InstanceDefinition $definition): array
    {
        $held = $this->queries->ask(new CountMembershipsByRole());
        $rows = [];
        foreach ($definition->roles as $role) {
            $meta = [
                $this->translator->trans($role->internalOnly ? 'admin.configuration.roles.internal_only' : 'admin.configuration.roles.anyone'),
                $this->translator->trans('admin.configuration.roles.membership_count', ['%count%' => $held[$role->id] ?? 0]),
            ];
            if ($role->followsNewIssues) {
                array_splice($meta, 1, 0, [$this->translator->trans('admin.configuration.roles.follows_new_issues_help')]);
            }
            $rows[] = $this->row($role->id, $role->label, implode(' · ', $meta), $this->generateUrl('app_admin_configuration_role', ['id' => $role->id]));
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function places(InstanceDefinition $definition): array
    {
        $standing = [];
        foreach ($this->issues->countByPlace() as $count) {
            $standing[$count['placeId']] = ($standing[$count['placeId']] ?? 0) + $count['issues'];
        }

        $rows = [];
        foreach ($definition->places as $place) {
            $workflows = [];
            foreach ($definition->workflows as $workflow) {
                if ($workflow->hasPlace($place->id)) {
                    $workflows[] = $workflow->label;
                }
            }
            $meta = [
                [] === $workflows ? $this->translator->trans('admin.configuration.places.unused') : implode(', ', $workflows),
                $this->translator->trans('admin.configuration.issue_count', ['%count%' => $standing[$place->id] ?? 0]),
            ];
            $rows[] = $this->row($place->id, $place->label, implode(' · ', $meta), $this->generateUrl('app_admin_configuration_place', ['id' => $place->id]), status: $place->status->value);
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function fields(InstanceDefinition $definition): array
    {
        $rows = [];
        foreach ($definition->fields as $field) {
            $workflows = [];
            foreach ($definition->workflows as $workflow) {
                if (isset($workflow->fields[$field->id])) {
                    $workflows[] = $workflow->label;
                }
            }
            $meta = [
                $this->translator->trans('field_type.'.$field->type->value),
                [] === $workflows ? $this->translator->trans('admin.configuration.fields.unused') : implode(', ', $workflows),
            ];
            $rows[] = $this->row($field->id, $field->label, implode(' · ', $meta), $this->generateUrl('app_admin_configuration_field', ['id' => $field->id]), badge: $field->internalOnly ? $this->translator->trans('admin.configuration.fields.internal_only') : null);
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function linkTypes(InstanceDefinition $definition): array
    {
        $used = $this->links->countByType();
        $rows = [];
        foreach ($definition->links as $link) {
            $meta = [
                $link->isSymmetric() ? $this->translator->trans('admin.configuration.links.symmetric') : $this->translator->trans('admin.configuration.links.inverse', ['%label%' => $link->labelFromTarget()]),
                $this->translator->trans('admin.configuration.links.link_count', ['%count%' => $used[$link->id] ?? 0]),
            ];
            $rows[] = $this->row($link->id, $link->label, implode(' · ', $meta), $this->generateUrl('app_admin_configuration_link', ['id' => $link->id]));
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function workflowPlaces(InstanceDefinition $definition): array
    {
        $workflow = $definition->workflow((string) $this->workflow);
        $standing = [];
        foreach ($this->issues->countByPlace() as $count) {
            if ($count['workflowId'] === $workflow->id) {
                $standing[$count['placeId']] = $count['issues'];
            }
        }

        $rows = [];
        foreach ($definition->placesOf($workflow) as $place) {
            $initial = $place->id === $workflow->initial;
            $rows[] = $this->row(
                $place->id,
                $place->label,
                $this->translator->trans('admin.configuration.issue_count', ['%count%' => $standing[$place->id] ?? 0]),
                $initial ? '' : $this->generateUrl('app_admin_configuration_workflow_place_remove', ['id' => $workflow->id, 'place' => $place->id]),
                status: $place->status->value,
                badge: $initial ? $this->translator->trans('admin.configuration.workflow.initial_badge') : null,
                action: 'admin.configuration.workflow.remove_place',
            );
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}>
     */
    private function transitions(InstanceDefinition $definition): array
    {
        $workflow = $definition->workflow((string) $this->workflow);
        $label = static fn (string $placeId): string => $definition->place($placeId)->label;

        $rows = [];
        foreach ($workflow->transitions as $transition) {
            $meta = [
                implode(', ', array_map($label, $transition->from)).' → '.$label($transition->to),
                implode(', ', array_map(static fn (string $roleId): string => $definition->role($roleId)->label, $transition->roles)),
            ];
            if ($transition->requiresComment) {
                $meta[] = $this->translator->trans('admin.configuration.workflow.comment_required');
            }
            $rows[] = $this->row($transition->id, $transition->label, implode(' · ', $meta), $this->generateUrl('app_admin_configuration_transition', ['id' => $workflow->id, 'key' => $transition->id]));
        }

        return $rows;
    }

    /**
     * @return array{id: string, label: string, key: string, status: string|null, badge: string|null, meta: string, href: string, action: string}
     */
    private function row(string $id, string $label, string $meta, string $href, ?string $status = null, ?string $badge = null, string $action = 'admin.configuration.edit'): array
    {
        return ['id' => $id, 'label' => $label, 'key' => $id, 'status' => $status, 'badge' => $badge, 'meta' => $meta, 'href' => $href, 'action' => $this->translator->trans($action)];
    }
}
