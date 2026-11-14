<?php

declare(strict_types=1);

namespace App\Import;

use App\Config\ConfigRegistry;
use App\Config\Definition\FieldDefinition;
use App\Config\FieldType;
use App\Entity\Enum\Criticality;
use App\Entity\Enum\ImportRecordKind;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Import;
use App\Message\Command\Import\ImportedComment;
use App\Message\Command\Import\ImportedFile;
use App\Message\Command\Import\ImportIssue;
use App\Repository\ImportRecordRepository;
use App\Repository\TrackerRepository;
use App\Repository\UserRepository;
use App\Security\AccessControl;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an issue of an archive into the command that writes it, under
 * the configuration of its import: its tracker and the place of its
 * status, the accounts of its people, its fields (a value its field
 * cannot take, and a field sent there, end the description), its
 * references and mentions in Cairn's syntax, its files taken out of the
 * archive. The rules of the import that ask a question of Cairn are here:
 * an assignee is kept on a closed issue, and dropped from an open one
 * when the person has no role on the project. A deactivated account
 * keeps its issues: it is activated once the project is ready.
 */
final class ImportIssueBuilder
{
    public function __construct(
        private readonly ImportRecordRepository $records,
        private readonly TrackerRepository $trackers,
        private readonly UserRepository $users,
        private readonly AccessControl $access,
        private readonly ConfigRegistry $config,
        private readonly TextRewriter $rewriter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param int                             $number    the number reserved for the issue
     * @param array<array-key, int|null>      $users     person id => account id, null when the account was refused
     * @param callable(string): (string|null) $keyOf     the key an issue of the archive gets, null when it is not imported
     * @param list<string>                    $extracted the files taken out of the archive, which the caller removes
     * @param array<string, string>           $hidden    field of Cairn => why its values will not show on this issue, for the report
     *
     * @return ImportIssue|string the command, or why the issue cannot be written, in the language of the instance
     */
    public function build(Import $import, ImportManifest $manifest, ImportMapping $mapping, ImportArchive $archive, ArchiveIssue $issue, int $number, array $users, callable $keyOf, array &$extracted, array &$hidden = []): ImportIssue|string
    {
        $project = $import->getProject();
        $definition = $this->config->definition();

        $trackerId = $this->records->findOne($import, ImportRecordKind::Tracker, $issue->tracker)?->getLocalId();
        $tracker = null === $trackerId ? null : $this->trackers->find($trackerId);
        if (null === $tracker) {
            return $this->translator->trans('admin.import.problem.tracker', ['%name%' => $manifest->trackers[$issue->tracker]['label']]);
        }
        foreach ([$issue->author, ...array_column($issue->comments, 'author'), ...array_column($issue->allFiles(), 'author')] as $person) {
            if (!isset($users[$person])) {
                return $this->translator->trans('admin.import.problem.account', ['%name%' => $manifest->nameOf($person)]);
            }
        }

        $workflow = $definition->workflow($tracker->getWorkflowId());
        $source = $manifest->workflows[$manifest->trackers[$issue->tracker]['workflow']];
        $place = $workflow->findPlace($mapping->places[$workflow->id][$manifest->trackers[$issue->tracker]['workflow']][$issue->status] ?? '');
        if (null === $place) {
            // Checked before the run: the configuration of Cairn changed since. Never a guess, which would look like a choice.
            return $this->translator->trans('admin.import.problem.place', ['%status%' => $source['statuses'][$issue->status]['label'], '%workflow%' => $workflow->label]);
        }
        $notes = [];

        $assigneeId = null;
        if (null !== $issue->assignee) {
            $assignee = isset($users[$issue->assignee]) ? $this->users->find($users[$issue->assignee]) : null;
            if (null !== $assignee && (PlaceStatus::Closed === $place->status || null !== $this->access->roleOn($assignee, $project))) {
                $assigneeId = $assignee->getId();
            } else {
                $notes[] = $this->translator->trans('admin.import.note.assignee', ['%name%' => $manifest->nameOf($issue->assignee)]);
            }
        }

        $fields = [];
        $appended = [];
        foreach ($issue->fields as $fieldId => $value) {
            $target = $mapping->fields[$fieldId] ?? ImportMapping::APPEND;
            if (str_starts_with($target, 'field:')) {
                $converted = $definition->hasField(substr($target, 6)) ? self::convert($definition->field(substr($target, 6)), $value) : null;
                if (null !== $converted) {
                    $fields[substr($target, 6)] = $converted;
                    if (!isset($workflow->fields[substr($target, 6)])) {
                        // Kept as chosen, but said: nothing shows a field its workflow does not use.
                        $hidden[substr($target, 6)] = $this->translator->trans('admin.import.problem.hidden_field', ['%field%' => $definition->field(substr($target, 6))->label, '%workflow%' => $workflow->label]);
                    }
                    continue;
                }
                $target = ImportMapping::APPEND;
            }
            if (ImportMapping::APPEND === $target) {
                $appended[] = \sprintf('- **%s**: %s', $manifest->fields[$fieldId]['label'], \is_array($value) ? implode(', ', $value) : $value);
            }
        }

        $names = [];
        foreach ($users as $personId => $userId) {
            if (null !== $userId) {
                $names[$personId] = [$userId, $manifest->nameOf((string) $personId)];
            }
        }
        $rewrite = fn (string $text): string => $this->rewriter->references($text, $keyOf, $names);

        $title = $issue->title;
        $description = $issue->description;
        if (mb_strlen($title) > 200) {
            $description = '**'.$title."**\n\n".$description;
            $title = mb_substr($title, 0, 199).'…';
        }
        if ([] !== $appended) {
            $description = rtrim($description)."\n\n---\n\n".implode("\n", $appended)."\n";
        }

        $file = static function (array $file) use ($archive, $users, &$extracted): ImportedFile {
            $path = tempnam(sys_get_temp_dir(), 'cairn-import-') ?: throw new \RuntimeException('No temporary file could be made.');
            $extracted[] = $path;
            $archive->extractFile($file['path'], $path);

            return new ImportedFile($file['id'], $path, $file['name'], (int) $users[$file['author']], $file['createdAt']);
        };
        $comments = [];
        foreach ($issue->comments as $comment) {
            $comments[] = new ImportedComment((int) $users[$comment['author']], $rewrite($comment['body']), $comment['private'], $comment['createdAt'], $mapping->files ? array_map($file, $comment['files']) : []);
        }

        return new ImportIssue(
            (int) $project->getId(),
            (int) $tracker->getId(),
            $number,
            $place->id,
            (int) $users[$issue->author],
            $title,
            $rewrite($description),
            Criticality::tryFrom($mapping->priorities[$issue->priority ?? ''] ?? '') ?? Criticality::DEFAULT,
            $assigneeId,
            null !== $issue->version && $mapping->versions ? $this->records->findOne($import, ImportRecordKind::Release, $issue->version)?->getLocalId() : null,
            $issue->private,
            $fields,
            $manifest->source,
            $issue->id,
            $issue->createdAt,
            $issue->updatedAt,
            $issue->closedAt,
            $comments,
            $mapping->files ? array_map($file, $issue->files) : [],
            array_values(array_filter(array_map(static fn (string $watcher): ?int => $users[$watcher] ?? null, $issue->watchers))),
            $notes,
        );
    }

    /**
     * A value of the archive as the field of Cairn stores it, or null
     * when it does not fit (the value then goes to the description).
     *
     * @param string|list<string> $value
     */
    private static function convert(FieldDefinition $field, string|array $value): mixed
    {
        $text = \is_array($value) ? implode(', ', $value) : $value;

        return match ($field->type) {
            FieldType::Text => mb_substr($text, 0, 255),
            FieldType::Textarea, FieldType::RichText => $text,
            // Shown as a link: only a web address is one.
            FieldType::Url => 1 === preg_match('#^https?://#i', $text) ? $text : null,
            FieldType::Number => is_numeric($text) && is_finite((float) $text) ? $text + 0 : null,
            FieldType::Date => false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $text) ? $text : null,
            FieldType::Bool => match ($text) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            },
            FieldType::Select, FieldType::Choice => self::option($field, \is_array($value) ? ($value[0] ?? '') : $value),
        };
    }

    private static function option(FieldDefinition $field, string $value): ?string
    {
        foreach ($field->options as $option => $label) {
            if (0 === strcasecmp((string) $option, $value) || 0 === strcasecmp($label, $value)) {
                return (string) $option;
            }
        }

        return null;
    }
}
