<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Form\Config\PlaceData;
use App\Form\Config\PlaceType;
use App\Message\Command\Config\CreatePlace;
use App\Message\Command\Config\DeletePlace;
use App\Message\Command\Config\UpdatePlace;
use App\Message\CommandBus;
use App\MessageHandler\Command\Config\ConfigDeletions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The steps of the instance, in the order the issue lists filter and
 * group by. A step is created, changed and deleted in a dialog; which
 * workflows use it is settled on the page of each workflow.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class PlaceController extends AbstractController
{
    use EditsConfiguration;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly ConfigDeletions $deletions,
    ) {
    }

    #[Route('/places', name: 'app_admin_configuration_places', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->page($request, null, null);
    }

    #[Route('/places/new', name: 'app_admin_configuration_place_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new PlaceData();
        $form = $this->createForm(PlaceType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): CreatePlace => new CreatePlace((string) $data->label, $data->status ?? PlaceStatus::Open))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.places.created', ['%name%' => (string) $data->label]));

            return $this->redirectToRoute('app_admin_configuration_places');
        }

        return $this->page($request, $form, null);
    }

    #[Route('/places/{id}', name: 'app_admin_configuration_place', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $place = $this->place($id);
        $data = new PlaceData();
        $data->label = $place->label;
        $data->status = $place->status;

        $form = $this->createForm(PlaceType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): UpdatePlace => new UpdatePlace($id, (string) $data->label, $data->status))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.saved'));

            return $this->redirectToRoute('app_admin_configuration_places');
        }

        return $this->page($request, $form, $place);
    }

    #[Route('/places/{id}/delete', name: 'app_admin_configuration_place_delete', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(string $id): RedirectResponse
    {
        $this->attempt(new DeletePlace($id), $this->translator->trans('admin.configuration.places.deleted', ['%name%' => $this->place($id)->label]));

        return $this->redirectToRoute('app_admin_configuration_places');
    }

    /**
     * @param FormInterface<PlaceData>|null $form  the dialog, when it is open
     * @param PlaceDefinition|null          $place the step it changes, none when it creates one
     */
    private function page(Request $request, ?FormInterface $form, ?PlaceDefinition $place): Response
    {
        return $this->renderWithDialog($request, 'admin/configuration/places.html.twig', [
            'form' => $form,
            'dialogPlace' => $place,
            'dialogRefusal' => null === $place ? null : $this->deletions->place($place->id)?->trans($this->translator),
        ], fn (): array => [
            'definition' => $this->config->definition(),
        ], $form);
    }

    private function place(string $id): PlaceDefinition
    {
        return $this->config->definition()->findPlace($id) ?? throw new NotFoundHttpException(\sprintf('No place "%s" in the configuration.', $id));
    }
}
