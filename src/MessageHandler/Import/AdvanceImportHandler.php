<?php

declare(strict_types=1);

namespace App\MessageHandler\Import;

use App\Entity\Enum\ImportStatus;
use App\Entity\Import;
use App\Import\ImportAnalyser;
use App\Import\ImportRunner;
use App\Message\Import\AdvanceImport;
use App\Repository\ImportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Does one batch of an import, analysis or run, and sends the next one
 * while there is work left: mails take turns with it in the queue, and a
 * worker restart loses at most one batch. It acts only on an import in
 * progress with the token of its last start. Whatever goes wrong fails
 * the import with the reason, which StartImport resumes from: nothing is
 * thrown back, so Messenger never replays a batch on its own.
 */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class AdvanceImportHandler
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly ImportAnalyser $analyser,
        private readonly ImportRunner $runner,
        private readonly MessageBusInterface $bus,
        private readonly ManagerRegistry $doctrine,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AdvanceImport $message): void
    {
        $import = $this->current($message);
        if (null === $import) {
            return;
        }

        try {
            $more = ImportStatus::Analysing === $import->getStatus() ? $this->analyser->advance($import) : $this->runner->advance($import);
        } catch (\Throwable $e) {
            $this->logger->error('Import {id} failed: {reason}', ['id' => $message->importId, 'reason' => $e->getMessage(), 'exception' => $e]);
            $this->fail($message, $e);

            return;
        }
        if ($more) {
            $this->bus->dispatch(new AdvanceImport($message->importId, $message->token));
        }
    }

    private function current(AdvanceImport $message): ?Import
    {
        $import = $this->imports->find($message->importId);

        return null !== $import && $import->getStatus()->isInProgress() && $message->token === $import->getRunToken() ? $import : null;
    }

    /**
     * Records the failure in a fresh unit of work: what the batch left
     * scheduled is dropped, and a failed flush has closed the manager.
     */
    private function fail(AdvanceImport $message, \Throwable $e): void
    {
        $manager = $this->doctrine->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->doctrine->resetManager();
            $manager = $this->doctrine->getManager();
        }
        $manager->clear();

        $import = $this->current($message);
        if (null === $import) {
            return;
        }
        $import->fail($e instanceof TranslatableInterface ? $e->trans($this->translator) : $this->translator->trans('admin.import.failure', ['%reason%' => $e->getMessage()]));
        $manager->flush();
    }
}
