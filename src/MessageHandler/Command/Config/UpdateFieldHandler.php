<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Config;

use App\Entity\Field;
use App\Message\Command\Config\UpdateField;
use App\MessageHandler\Command\Tracker\ListOrder;
use App\Repository\FieldRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateFieldHandler
{
    public function __construct(
        private readonly FieldRepository $fields,
        private readonly ConfigWriting $writing,
    ) {
    }

    public function __invoke(UpdateField $command): void
    {
        $field = $this->writing->get(Field::class, $command->id);
        $options = null === $command->options ? null : $this->writing->choices($command->options, $field->getOptions());
        ConfigWriting::assertChoices($field->getType(), $options ?? $field->getOptions());

        if (null !== $command->label) {
            $field->rename($command->label);
        }
        if (null !== $options) {
            $field->offer($options);
        }
        if (null !== $command->placeholder) {
            $field->setPlaceholder($command->placeholder);
        }
        if (null !== $command->help) {
            $field->setHelp($command->help);
        }
        if (null !== $command->internalOnly) {
            $command->internalOnly ? $field->makeInternalOnly() : $field->showToExternalUsers();
        }
        if (null !== $command->position) {
            ListOrder::move($field, $this->fields->findOrdered(), $command->position);
        }
        $this->writing->settle('field.updated', ['%name%' => $field->getLabel()]);
    }
}
