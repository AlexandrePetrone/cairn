<?php

declare(strict_types=1);

namespace App\Import;

/**
 * An archive being imported: the zip, and a work folder where its
 * `manifest.json` and `issues.ndjson` are extracted, so that the issues
 * are read line by line from a position, batch after batch. The files of
 * `files/` stay in the zip and are taken out one at a time.
 */
final class ImportArchive
{
    public const string MANIFEST = 'manifest.json';
    public const string ISSUES = 'issues.ndjson';

    private ?\ZipArchive $zip = null;

    public function __construct(
        private readonly string $zipPath,
        private readonly string $workDirectory,
    ) {
    }

    public function __destruct()
    {
        $this->zip?->close();
    }

    /**
     * Extracts the two descriptions into the work folder, unless they
     * are there already.
     */
    public function extract(): void
    {
        if (!is_dir($this->workDirectory) && !mkdir($this->workDirectory, 0o775, true) && !is_dir($this->workDirectory)) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', $this->workDirectory));
        }
        foreach ([self::MANIFEST, self::ISSUES] as $file) {
            $target = $this->workDirectory.'/'.$file;
            if (is_file($target)) {
                continue;
            }
            $in = $this->zip()->getStream($file);
            if (false === $in) {
                throw ImportArchiveException::missing($file);
            }
            $out = fopen($target.'.part', 'w') ?: throw new \RuntimeException(\sprintf('Cannot write to %s.', $target));
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            rename($target.'.part', $target);
        }
    }

    public function manifest(): ImportManifest
    {
        $this->extract();
        try {
            $data = json_decode((string) file_get_contents($this->workDirectory.'/'.self::MANIFEST), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        return ImportManifest::fromFile(\is_array($data) ? $data : throw ImportArchiveException::invalidManifest('JSON'));
    }

    /** The size of `issues.ndjson`, which the positions count in. */
    public function issuesSize(): int
    {
        $this->extract();

        return (int) filesize($this->workDirectory.'/'.self::ISSUES);
    }

    /**
     * The issues from a position of `issues.ndjson`: each one with the
     * position after it, to start again from. Empty lines are skipped.
     *
     * @return \Generator<int, ArchiveIssue> position after the issue => issue
     */
    public function issues(ImportManifest $manifest, int $position = 0): \Generator
    {
        $this->extract();
        $handle = fopen($this->workDirectory.'/'.self::ISSUES, 'r') ?: throw ImportArchiveException::missing(self::ISSUES);
        try {
            fseek($handle, $position);
            $previous = null;
            while (false !== ($text = fgets($handle))) {
                if ('' === trim($text)) {
                    continue;
                }
                try {
                    $data = json_decode($text, true, 64, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $data = null;
                }
                $id = \is_array($data) ? ($data['id'] ?? null) : null;
                if (!\is_array($data) || !(\is_string($id) || \is_int($id)) || '' === $id) {
                    throw ImportArchiveException::unreadableIssue($previous);
                }
                yield (int) ftell($handle) => ArchiveIssue::fromLine($data, $manifest);
                $previous = (string) $id;
            }
        } finally {
            fclose($handle);
        }
    }

    public function hasFile(string $path): bool
    {
        return false !== $this->zip()->locateName($path);
    }

    /** Copies a file of the archive to a local path. */
    public function extractFile(string $path, string $target): void
    {
        $in = $this->zip()->getStream($path);
        if (false === $in) {
            throw ImportArchiveException::missing($path);
        }
        $out = fopen($target, 'w') ?: throw new \RuntimeException(\sprintf('Cannot write to %s.', $target));
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    private function zip(): \ZipArchive
    {
        if (null === $this->zip) {
            $zip = new \ZipArchive();
            if (true !== $zip->open($this->zipPath, \ZipArchive::RDONLY)) {
                throw ImportArchiveException::unreadable(basename($this->zipPath));
            }
            $this->zip = $zip;
        }

        return $this->zip;
    }
}
