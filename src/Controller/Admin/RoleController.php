<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Automation\RuleReferences;
use App\Config\ConfigRegistry;
use App\Config\Definition\RoleDefinition;
use App\Form\Config\RoleData;
use App\Form\Config\RoleType;
use App\Message\Command\Config\CreateRole;
use App\Message\Command\Config\DeleteRole;
use App\Message\Command\Config\UpdateRole;
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
 * The roles of the instance and the rights: the
 * list is ordered and the rights are ticked in place (Config:Order,
 * Config:PermissionMatrix); a role is created, changed and deleted in a
 * dialog.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class RoleController extends AbstractController
{
    use EditsConfiguration;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly ConfigDeletions $deletions,
        private readonly RuleReferences $rules,
    ) {
    }

    #[Route('/roles', name: 'app_admin_configuration_roles', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return $this->page($request, null, null);
    }

    #[Route('/roles/new', name: 'app_admin_configuration_role_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new RoleData();
        $form = $this->createForm(RoleType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): CreateRole => new CreateRole((string) $data->label, $data->internalOnly, $data->followsNewIssues, $data->freeWorkflow))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.roles.created', ['%name%' => (string) $data->label]));

            return $this->redirectToRoute('app_admin_configuration_roles');
        }

        return $this->page($request, $form, null);
    }

    #[Route('/role/{id}', name: 'app_admin_configuration_role', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $role = $this->role($id);
        $data = new RoleData();
        $data->label = $role->label;
        $data->internalOnly = $role->internalOnly;
        $data->followsNewIssues = $role->followsNewIssues;
        $data->freeWorkflow = $role->freeWorkflow;

        $form = $this->createForm(RoleType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($this->applies($form, static fn (): UpdateRole => new UpdateRole($id, (string) $data->label, $data->internalOnly, $data->followsNewIssues, $data->freeWorkflow))) {
            $this->addFlash('success', $this->translator->trans('admin.configuration.saved'));

            return $this->redirectToRoute('app_admin_configuration_roles');
        }

        return $this->page($request, $form, $role);
    }

    #[Route('/role/{id}/delete', name: 'app_admin_configuration_role_delete', requirements: ['id' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(string $id): RedirectResponse
    {
        $this->attempt(new DeleteRole($id), $this->translator->trans('admin.configuration.roles.deleted', ['%name%' => $this->role($id)->label]));

        return $this->redirectToRoute('app_admin_configuration_roles');
    }

    /**
     * @param FormInterface<RoleData>|null $form the dialog, when it is open
     * @param RoleDefinition|null          $role the role it changes, none when it creates one
     */
    private function page(Request $request, ?FormInterface $form, ?RoleDefinition $role): Response
    {
        return $this->renderWithDialog($request, 'admin/configuration/roles.html.twig', [
            'form' => $form,
            'dialogRole' => $role,
            'dialogRefusal' => null === $role ? null : $this->deletions->role($role->id)?->trans($this->translator),
            'dialogLeaves' => null === $role || $role->freeWorkflow ? null : $this->leaves($role),
        ], fn (): array => [
            'definition' => $this->config->definition(),
        ], $form);
    }

    /**
     * What a role held to the workflow leaves when it is freed, said in
     * its dialog before it is done: the actions that name it, and the
     * automation rules triggered by those it would leave to nobody.
     *
     * @return array{actions: list<string>, rules: list<string>}
     */
    private function leaves(RoleDefinition $role): array
    {
        $actions = [];
        $rules = [];
        foreach ($this->config->definition()->workflows as $workflow) {
            foreach ($workflow->transitions as $transition) {
                if (!\in_array($role->id, $transition->roles, true)) {
                    continue;
                }
                $actions[] = \sprintf('%s (%s)', $transition->label, $workflow->label);
                if ([$role->id] === $transition->roles) {
                    array_push($rules, ...$this->rules->toTransition($workflow->id, $transition->id));
                }
            }
        }

        return ['actions' => $actions, 'rules' => array_values(array_unique($rules))];
    }

    private function role(string $id): RoleDefinition
    {
        $definition = $this->config->definition();

        return $definition->hasRole($id) ? $definition->role($id) : throw new NotFoundHttpException(\sprintf('No role "%s" in the configuration.', $id));
    }
}
