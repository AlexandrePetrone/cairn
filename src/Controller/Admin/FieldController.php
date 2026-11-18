<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Config\Definition\FieldDefinition;
use App\Config\Definition\LinkTypeDefinition;
use App\Config\FieldType;
use App\Form\Config\FieldData;
use App\Form\Config\FieldSettingsType;
use App\Form\Config\LinkTypeData;
use App\Form\Config\LinkTypeType;
use App\Message\Command\Config\CreateField;
use App\Message\Command\Config\CreateLinkType;
use App\Message\Command\Config\DeleteField;
use App\Message\Command\Config\DeleteLinkType;
use App\Message\Command\Config\UpdateField;
use App\Message\Command\Config\UpdateLinkType;
use App\Message\CommandBus;
use App\MessageHandler\Command\Config\ConfigDeletions;
use App\Repository\IssueFieldValueRepository;
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
 * The fields and the link types of the instance, two ordered lists on
 * one page, each created, changed and deleted in a dialog. Which
 * workflows ask for a field is settled on the page of each workflow.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class FieldController extends AbstractController
{
    use EditsConfiguration;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly ConfigDeletions $deletions,
        private readonly IssueFieldValueRepository $held,
    ) {
    }

    #[Route('/fields', name: 'app_admin_configuration_fields', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->page($request, null);
    }

    #[Route('/fields/new', name: 'app_admin_configuration_field_new', methods: ['GET', 'POST'])]
    public function newField(Request $request): Response
    {
        $data = new FieldData();
        $form = $this->createForm(FieldSettingsType::class, $data, ['action' => $request->getRequestUri(), 'create' => true, 'attr' => ['data-controller' => 'reveal']]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): CreateField => new CreateField((string) $data->label, $data->type ?? FieldType::Text, $data->labels(), $data->placeholder, $data->help, $data->internalOnly))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.fields.created', ['%name%' => (string) $data->label]));

            return $this->redirectToRoute('app_admin_configuration_fields');
        }

        return $this->page($request, $form);
    }

    #[Route('/field/{id}', name: 'app_admin_configuration_field', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function editField(string $id, Request $request): Response
    {
        $definition = $this->config->definition();
        $field = $definition->hasField($id) ? $definition->field($id) : throw new NotFoundHttpException(\sprintf('No field "%s" in the configuration.', $id));
        $data = new FieldData();
        $data->label = $field->label;
        $data->type = $field->type;
        $data->placeholder = $field->placeholder;
        $data->help = $field->help;
        $data->internalOnly = $field->internalOnly;

        $form = $this->createForm(FieldSettingsType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        // An empty placeholder or help erases it; the choices are changed on their own page.
        if ($this->applies($form, static fn (): UpdateField => new UpdateField($id, (string) $data->label, placeholder: (string) $data->placeholder, help: (string) $data->help, internalOnly: $data->internalOnly))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.saved'));

            return $this->redirectToRoute('app_admin_configuration_fields');
        }

        return $this->page($request, $form, $field);
    }

    /**
     * The choices of a field that offers some, a list arranged in place (Config:Choices).
     */
    #[Route('/field/{id}/choices', name: 'app_admin_configuration_field_choices', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['GET'])]
    public function choices(string $id): Response
    {
        $definition = $this->config->definition();
        if (!$definition->hasField($id) || !$definition->field($id)->type->hasOptions()) {
            throw new NotFoundHttpException(\sprintf('No field "%s" that offers choices in the configuration.', $id));
        }

        return $this->render('admin/configuration/field_choices.html.twig', ['definition' => $definition, 'field' => $definition->field($id)]);
    }

    #[Route('/field/{id}/delete', name: 'app_admin_configuration_field_delete', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function deleteField(string $id): RedirectResponse
    {
        $this->attempt(new DeleteField($id), $this->translator->trans('admin.configuration.fields.deleted'));

        return $this->redirectToRoute('app_admin_configuration_fields');
    }

    #[Route('/links/new', name: 'app_admin_configuration_link_new', methods: ['GET', 'POST'])]
    public function newLink(Request $request): Response
    {
        $data = new LinkTypeData();
        $form = $this->createForm(LinkTypeType::class, $data, ['action' => $request->getRequestUri(), 'create' => true, 'attr' => ['data-controller' => 'reveal']]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): CreateLinkType => new CreateLinkType((string) $data->label, $data->inverse()))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.links.created', ['%name%' => (string) $data->label]));

            return $this->redirectToRoute('app_admin_configuration_fields');
        }

        return $this->page($request, $form, link: true);
    }

    #[Route('/link/{id}', name: 'app_admin_configuration_link', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function editLink(string $id, Request $request): Response
    {
        $definition = $this->config->definition();
        $link = $definition->hasLink($id) ? $definition->link($id) : throw new NotFoundHttpException(\sprintf('No link type "%s" in the configuration.', $id));
        $data = new LinkTypeData();
        $data->label = $link->label;
        $data->nature = $link->isSymmetric() ? LinkTypeData::SAME : LinkTypeData::DIRECTED;
        $data->inverseLabel = $link->inverseLabel;

        $form = $this->createForm(LinkTypeType::class, $data, ['action' => $request->getRequestUri(), 'directed' => !$link->isSymmetric()]);
        $form->handleRequest($request);
        // Whether it reads the same from both issues was chosen when it was created: only its labels change.
        if ($this->applies($form, static fn (): UpdateLinkType => new UpdateLinkType($id, (string) $data->label, $data->inverse()))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.saved'));

            return $this->redirectToRoute('app_admin_configuration_fields');
        }

        return $this->page($request, $form, $link, true);
    }

    #[Route('/link/{id}/delete', name: 'app_admin_configuration_link_delete', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function deleteLink(string $id): RedirectResponse
    {
        $this->attempt(new DeleteLinkType($id), $this->translator->trans('admin.configuration.links.deleted'));

        return $this->redirectToRoute('app_admin_configuration_fields');
    }

    /**
     * @param FormInterface<FieldData>|FormInterface<LinkTypeData>|null $form    the dialog, when it is open
     * @param FieldDefinition|LinkTypeDefinition|null                   $subject what it changes, none when it creates
     * @param bool                                                      $link    whether the dialog is one of a link type
     */
    private function page(Request $request, ?FormInterface $form, FieldDefinition|LinkTypeDefinition|null $subject = null, bool $link = false): Response
    {
        $refusal = match (true) {
            $subject instanceof FieldDefinition => $this->deletions->field($subject->id),
            $subject instanceof LinkTypeDefinition => $this->deletions->linkType($subject->id),
            default => null,
        };

        return $this->renderWithDialog($request, 'admin/configuration/fields.html.twig', ['form' => $form, 'dialogSubject' => $subject, 'dialogIsLink' => $link, 'dialogRefusal' => $refusal?->trans($this->translator), 'dialogValues' => $subject instanceof FieldDefinition ? $this->held->countHolding($subject->id) : 0], fn (): array => [
            'definition' => $this->config->definition(),
        ], $form);
    }
}
