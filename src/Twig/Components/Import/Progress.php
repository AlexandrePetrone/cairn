<?php

declare(strict_types=1);

namespace App\Twig\Components\Import;

use App\Entity\Import;
use App\Import\ImportRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Where an import stands while a worker is on it: its phases, and how
 * far the one it is on went. It asks again every two seconds, and sends
 * the browser back to the page of the import once it is no longer the
 * state it was drawn in (configured, completed, failed): the page then
 * shows something else.
 */
#[AsLiveComponent('Import:Progress')]
final class Progress extends AbstractController
{
    use DefaultActionTrait;

    private const array ANALYSIS_PHASES = ['read', 'scan'];

    #[LiveProp]
    public Import $import;

    /** The status the page was drawn with. */
    #[LiveProp]
    public string $drawnStatus = '';

    public function mount(Import $import): void
    {
        $this->import = $import;
        $this->drawnStatus = $import->getStatus()->value;
    }

    /**
     * The live endpoint is not under `/admin`.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    #[LiveAction]
    public function refresh(): ?Response
    {
        return $this->import->getStatus()->value === $this->drawnStatus ? null : $this->redirectToRoute('app_admin_import', ['id' => $this->import->getId()]);
    }

    /**
     * @return list<string>
     */
    public function getPhases(): array
    {
        return $this->import->isAnalysed() ? ImportRunner::PHASES : self::ANALYSIS_PHASES;
    }

    /** The place of the current phase among the phases. */
    public function getIndex(): int
    {
        $index = array_search($this->import->getPhase(), $this->getPhases(), true);

        return false === $index ? 0 : $index;
    }

    /**
     * How far the current phase went. The scan of the archive is
     * measured in bytes read, the other phases in items done.
     *
     * @return array{value: int, max: int|null, counted: bool}
     */
    public function getBar(): array
    {
        $scanning = 'scan' === $this->import->getPhase();
        $max = $this->import->getTotal();

        return ['value' => $scanning ? $this->import->getPosition() : $this->import->getDone(), 'max' => $max > 0 ? $max : null, 'counted' => !$scanning && $max > 0];
    }
}
