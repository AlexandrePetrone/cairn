<?php

declare(strict_types=1);

namespace App\Twig\Components\Config;

use App\Config\ConfigRegistry;
use App\Config\Definition\FieldDefinition;
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
 * The fields a workflow asks of an issue, in the order of its form: each
 * is required or not, dragged to another place or taken out, and a field
 * of the instance the workflow does not ask for yet is added from a menu.
 * Every change sends the whole list (UpdateWorkflow::$fields).
 */
#[AsLiveComponent('Config:WorkflowFields')]
final class WorkflowFields extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public string $workflow;

    /** The field chosen in the menu, before "Add". */
    #[LiveProp(writable: true)]
    public string $adding = '';

    public function __construct(
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
     * @return list<array{field: FieldDefinition, required: bool}>
     */
    public function getAsked(): array
    {
        $definition = $this->config->definition();
        $asked = [];
        foreach ($definition->workflow($this->workflow)->fields as $usage) {
            $asked[] = ['field' => $definition->field($usage->field), 'required' => $usage->required];
        }

        return $asked;
    }

    /**
     * @return list<FieldDefinition> the fields of the instance the workflow does not ask for
     */
    public function getOthers(): array
    {
        $definition = $this->config->definition();
        $asked = $definition->workflow($this->workflow)->fields;

        return array_values(array_filter($definition->fields, static fn (FieldDefinition $field): bool => !isset($asked[$field->id])));
    }

    #[LiveAction]
    public function add(CommandBus $commands): void
    {
        if ('' !== $this->adding) {
            $this->send($commands, $this->current() + [$this->adding => false]);
            $this->adding = '';
        }
    }

    #[LiveAction]
    public function remove(CommandBus $commands, #[LiveArg] string $field): void
    {
        $fields = $this->current();
        unset($fields[$field]);
        $this->send($commands, $fields);
    }

    #[LiveAction]
    public function toggle(CommandBus $commands, #[LiveArg] string $field): void
    {
        $fields = $this->current();
        if (isset($fields[$field])) {
            $fields[$field] = !$fields[$field];
            $this->send($commands, $fields);
        }
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $id, #[LiveArg] int $position): void
    {
        $fields = $this->current();
        if (!isset($fields[$id])) {
            return;
        }
        $required = $fields[$id];
        unset($fields[$id]);
        $this->send($commands, \array_slice($fields, 0, $position, true) + [$id => $required] + \array_slice($fields, $position, null, true));
    }

    /**
     * @return array<string, bool> field id => required, in the order of the form
     */
    private function current(): array
    {
        $fields = [];
        foreach ($this->config->definition()->workflow($this->workflow)->fields as $usage) {
            $fields[$usage->field] = $usage->required;
        }

        return $fields;
    }

    /**
     * @param array<string, bool> $fields
     */
    private function send(CommandBus $commands, array $fields): void
    {
        $this->tryCommand($commands, new UpdateWorkflow($this->workflow, fields: $fields));
    }
}
