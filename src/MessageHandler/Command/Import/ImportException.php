<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Import;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An import cannot be created, configured or started as asked, or one of
 * its items cannot be written. The message is for the logs; the
 * administration shows the translated reason, and the record of an import
 * keeps it for the items left out.
 */
final class ImportException extends \DomainException implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, int|string $id): self
    {
        return new self(\sprintf('%s %s not found.', $what, $id), 'admin.refused.not_found');
    }

    public static function unknownArchive(string $file): self
    {
        return new self(\sprintf('No archive "%s" waits in the imports directory.', $file), 'admin.import.refused.unknown_archive', ['%file%' => $file]);
    }

    public static function readOnlyProject(string $project): self
    {
        return new self(\sprintf('The project %s is read-only.', $project), 'admin.import.refused.read_only', ['%project%' => $project]);
    }

    public static function busy(string $what): self
    {
        return new self(\sprintf('Another import of or into %s is not over.', $what), 'admin.import.refused.busy', ['%what%' => $what]);
    }

    /** The import is not in a state where that can be done: running, over, or not analysed yet. */
    public static function wrongState(): self
    {
        return new self('The import is not in a state for that.', 'admin.import.refused.wrong_state');
    }

    public static function nothingToImport(): self
    {
        return new self('No issue would be imported.', 'admin.import.refused.nothing');
    }

    /**
     * A choice of the configuration is not one the archive and Cairn allow.
     *
     * @param string $what what the choice is for, as the archive names it
     */
    public static function invalidChoice(string $what, string $value): self
    {
        return new self(\sprintf('"%s" is not a possible choice for %s.', $value, $what), 'admin.import.refused.invalid_choice', ['%what%' => $what, '%value%' => $value]);
    }

    public static function numberTaken(string $key): self
    {
        return new self(\sprintf('An issue already has the key %s.', $key), 'admin.import.refused.number_taken', ['%key%' => $key]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
