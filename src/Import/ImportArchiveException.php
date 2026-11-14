<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An archive cannot be imported as it is: not a zip, a file missing, a
 * manifest or an issue that does not follow the format. The message names
 * the place for the logs; the administration shows the translated reason
 * to whoever wrote the export.
 */
final class ImportArchiveException extends \DomainException implements TranslatableInterface
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

    public static function unreadable(string $name): self
    {
        return new self(\sprintf('"%s" cannot be opened as a zip.', $name), 'admin.import.archive.unreadable', ['%name%' => $name]);
    }

    public static function missing(string $file): self
    {
        return new self(\sprintf('"%s" is missing from the archive.', $file), 'admin.import.archive.missing', ['%file%' => $file]);
    }

    public static function unsupportedFormat(mixed $version): self
    {
        $version = \is_scalar($version) ? (string) $version : '?';

        return new self(\sprintf('Format version "%s" is not supported.', $version), 'admin.import.archive.format', ['%version%' => $version, '%expected%' => (string) ImportManifest::FORMAT]);
    }

    /**
     * @param string $where the place in the manifest: `people[3].email`
     */
    public static function invalidManifest(string $where): self
    {
        return new self(\sprintf('manifest.json is invalid at %s.', $where), 'admin.import.archive.manifest', ['%where%' => $where]);
    }

    public static function sharedEmail(string $email): self
    {
        return new self(\sprintf('manifest.json: two people share the email %s.', $email), 'admin.import.archive.shared_email', ['%email%' => $email]);
    }

    /**
     * A line that is not JSON, or an issue without an id: named after
     * the issue before it, the only thing known of it.
     */
    public static function unreadableIssue(?string $previous): self
    {
        return null === $previous
            ? new self('issues.ndjson: a line is not JSON, or its issue has no id.', 'admin.import.archive.unreadable_issue')
            : new self(\sprintf('issues.ndjson: the line after the issue %s is not JSON, or its issue has no id.', $previous), 'admin.import.archive.unreadable_issue_after', ['%previous%' => $previous]);
    }

    /**
     * @param string $id    the id of the issue in the archive
     * @param string $where the property of the issue: `author`, `comments[2].createdAt`
     */
    public static function invalidIssue(string $id, string $where): self
    {
        return new self(\sprintf('issues.ndjson, issue %s: %s is missing or invalid.', $id, $where), 'admin.import.archive.issue', ['%id%' => $id, '%where%' => $where]);
    }

    public static function missingFile(string $id, string $path): self
    {
        return new self(\sprintf('issues.ndjson, issue %s: the file "%s" is not in the archive.', $id, $path), 'admin.import.archive.file', ['%id%' => $id, '%path%' => $path]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
