<?php

declare(strict_types=1);

namespace App\Twig\Components\Config;

use App\Config\ConfigRegistry;
use App\Message\Command\Config\UpdateField;
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
 * The choices of a field that offers some, arranged in place: dragged to
 * reorder, renamed, taken out, and one added from its label. Each gesture
 * is an UpdateField carrying the whole list. A choice keeps the value
 * issues store, which it got from its label when it was added; only its
 * label changes.
 */
#[AsLiveComponent('Config:Choices')]
final class Choices extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public string $field;

    /** The value of the choice being renamed, and the label typed for it. */
    #[LiveProp]
    public ?string $editing = null;

    #[LiveProp(writable: true)]
    public string $label = '';

    /** The value of the choice asked to be taken out, until it is confirmed. */
    #[LiveProp]
    public ?string $removing = null;

    /** The label of a choice to add. */
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
     * @return list<array{value: string, label: string}> the choices the field has, in their order
     */
    public function getChoices(): array
    {
        $choices = [];
        foreach ($this->config->definition()->field($this->field)->options as $value => $label) {
            // A value made of digits comes back from PHP as an integer key.
            $choices[] = ['value' => (string) $value, 'label' => $label];
        }

        return $choices;
    }

    #[LiveAction]
    public function edit(#[LiveArg] string $value): void
    {
        foreach ($this->getChoices() as $choice) {
            if ($choice['value'] === $value) {
                $this->editing = $value;
                $this->label = $choice['label'];
                $this->removing = null;
                $this->error = null;
            }
        }
    }

    #[LiveAction]
    public function rename(CommandBus $commands): void
    {
        $choices = $this->getChoices();
        foreach ($choices as $index => $choice) {
            if ($choice['value'] === $this->editing) {
                $choices[$index]['label'] = $this->label;
            }
        }
        if ($this->send($commands, $choices)) {
            $this->editing = null;
        }
    }

    #[LiveAction]
    public function askRemove(#[LiveArg] string $value): void
    {
        $this->removing = $value;
        $this->editing = null;
        $this->error = null;
    }

    #[LiveAction]
    public function remove(CommandBus $commands): void
    {
        $this->send($commands, array_values(array_filter($this->getChoices(), fn (array $choice): bool => $choice['value'] !== $this->removing)));
        $this->removing = null;
    }

    #[LiveAction]
    public function cancel(): void
    {
        $this->editing = null;
        $this->removing = null;
        $this->error = null;
    }

    #[LiveAction]
    public function add(CommandBus $commands): void
    {
        if ('' !== trim($this->adding) && $this->send($commands, [...$this->getChoices(), ['value' => null, 'label' => $this->adding]])) {
            $this->adding = '';
        }
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $id, #[LiveArg] int $position): void
    {
        $choices = $this->getChoices();
        $moved = array_values(array_filter($choices, static fn (array $choice): bool => $choice['value'] === $id));
        if ([] === $moved) {
            return;
        }
        $others = array_values(array_filter($choices, static fn (array $choice): bool => $choice['value'] !== $id));
        array_splice($others, min($position, \count($others)), 0, $moved);
        $this->send($commands, $others);
    }

    /**
     * @param list<array{value: ?string, label: string}> $choices
     */
    private function send(CommandBus $commands, array $choices): bool
    {
        if (!$this->tryCommand($commands, new UpdateField($this->field, options: $choices))) {
            return false;
        }
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('admin.configuration.saved')]);

        return true;
    }
}
