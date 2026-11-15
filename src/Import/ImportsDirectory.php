<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Import;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where import archives live: `IMPORTS_DIR`. A zip dropped there (by
 * hand for a large one, or sent from the administration) waits to be
 * imported; an imported one moves to `archive/` and is offered no more;
 * `work/<import id>/` holds what an import in progress extracted. Nothing
 * else knows the layout.
 */
final class ImportsDirectory
{
    public function __construct(
        #[Autowire('%env(resolve:IMPORTS_DIR)%')]
        private readonly string $directory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The archives waiting, by name.
     *
     * @return array<string, int> file name => size in bytes
     */
    public function waiting(): array
    {
        $archives = [];
        foreach (glob($this->directory.'/*.zip') ?: [] as $path) {
            $archives[basename($path)] = (int) filesize($path);
        }
        ksort($archives);

        return $archives;
    }

    public function has(string $name): bool
    {
        return isset($this->waiting()[$name]);
    }

    /**
     * Takes a zip sent from the administration, under a name of its own.
     */
    public function receive(UploadedFile $file): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)), '-.');
        $base = '' === $base ? 'archive' : mb_substr($base, 0, 80);
        $name = $base.'.zip';
        for ($copy = 2; is_file($this->directory.'/'.$name) || is_file($this->directory.'/archive/'.$name); ++$copy) {
            $name = $base.'-'.$copy.'.zip';
        }
        $this->filesystem->mkdir($this->directory);
        $file->move($this->directory, $name);

        return $name;
    }

    public function open(Import $import): ImportArchive
    {
        return new ImportArchive($this->directory.'/'.$import->getFile(), $this->work($import));
    }

    /** The work folder of an import, for what the run keeps between batches. */
    public function work(Import $import): string
    {
        return $this->directory.'/work/'.$import->getId();
    }

    /** The import is over: its zip is archived, its work folder removed. */
    public function archive(Import $import): void
    {
        $this->filesystem->mkdir($this->directory.'/archive');
        $path = $this->directory.'/'.$import->getFile();
        if (is_file($path)) {
            $this->filesystem->rename($path, $this->directory.'/archive/'.$import->getFile(), true);
        }
        $this->release($import);
    }

    /** Removes the work folder; the zip stays where it is. */
    public function release(Import $import): void
    {
        $this->clear((int) $import->getId());
    }

    /** The work folder of an import that may be gone with its project. */
    public function clear(int $importId): void
    {
        $this->filesystem->remove($this->directory.'/work/'.$importId);
    }

    /**
     * Removes a zip put away after its import, whose project was deleted.
     * A zip still waiting is left: it may be imported elsewhere.
     */
    public function discard(string $name): void
    {
        $this->filesystem->remove($this->directory.'/archive/'.basename($name));
    }
}
