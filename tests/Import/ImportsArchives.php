<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\Import;
use App\Message\Command\Import\CreateImport;
use App\Message\Command\Import\StartImport;
use App\Message\Import\AdvanceImport;
use App\MessageHandler\Import\AdvanceImportHandler;
use App\Tests\KernelHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * For the tests of the imports: the imports directory of the test
 * environment, emptied; an import of the example archive, analysed; and
 * the worker, played by handing the queued batches to their handler one
 * after the other, as `messenger:consume async` would.
 *
 * @phpstan-require-extends KernelTestCase
 */
trait ImportsArchives
{
    use KernelHelpers;

    /** Empties the imports directory (IMPORTS_DIR in .env.test: one per ParaTest worker) and gives its path. */
    private function importsDirectory(): string
    {
        $directory = self::getContainer()->getParameter('kernel.project_dir').'/var/imports_test'.(getenv('TEST_TOKEN') ?: '');
        new Filesystem()->remove($directory);

        return $directory;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $async = self::getContainer()->get('messenger.transport.async');
        \assert($async instanceof InMemoryTransport);

        return $async;
    }

    /**
     * Runs the batches of the imports in progress until each one waits
     * for someone or ends. The batches are read from the imports rather
     * than from the queue, which a request of the test client empties.
     */
    private function work(): void
    {
        $handler = self::getContainer()->get(AdvanceImportHandler::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        for ($batches = 0; $batches < 2000; ++$batches) {
            $entityManager->clear();
            $running = array_find($entityManager->getRepository(Import::class)->findAll(), static fn (Import $import): bool => null !== $import->getRunToken());
            if (null === $running) {
                break;
            }
            $handler(new AdvanceImport((int) $running->getId(), (string) $running->getRunToken()));
        }
        $this->asyncTransport()->reset();
        $entityManager->clear();
    }

    /**
     * The example archive (see ArchiveBuilder), changed if asked,
     * dropped in the directory, imported into a project and analysed.
     */
    private function analysedImport(string $directory, int $adminId, int $projectId, ?callable $change = null): Import
    {
        ArchiveBuilder::example($directory, 'example.zip', $change);
        $import = self::commandBus()->dispatch(new CreateImport($adminId, 'example.zip', $projectId));
        \assert($import instanceof Import);
        self::commandBus()->dispatch(new StartImport((int) $import->getId()));
        $this->work();

        return $this->reloadImport($import);
    }

    private function reloadImport(Import $import): Import
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Import::class)->find($import->getId()) ?? throw new \LogicException('No import.');
    }
}
