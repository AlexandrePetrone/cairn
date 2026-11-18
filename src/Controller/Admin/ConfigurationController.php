<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Config\ConfigException;
use App\Config\ConfigRegistry;
use App\Config\StarterKit;
use App\Repository\ConfigChangeRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The door of the configuration pages, one controller per subject
 * (RoleController, WorkflowController, PlaceController, FieldController),
 * and what an instance nobody configured yet is offered on each of them:
 * its starter configuration. It also shows the journal of the changes.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/configuration')]
final class ConfigurationController extends AbstractController
{
    #[Route('', name: 'app_admin_configuration', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_configuration_roles');
    }

    /**
     * Installs the starter configuration; an instance that has one keeps it.
     */
    #[Route('/install', name: 'app_admin_configuration_install', methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function install(StarterKit $kit, TranslatorInterface $translator, LoggerInterface $logger): RedirectResponse
    {
        try {
            $this->addFlash('success', $translator->trans($kit->install() ? 'admin.configuration.starter.installed' : 'admin.configuration.starter.already'));
        } catch (ConfigException|\DomainException $e) {
            // Nothing was installed. The page says so; why is for whoever runs the instance, in the logs.
            $logger->error('The starter configuration could not be installed: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);
            $this->addFlash('error', $translator->trans('admin.configuration.starter.failed'));
        }

        return $this->redirectToRoute('app_admin_configuration_workflows');
    }

    /**
     * Who changed what, the latest change first.
     */
    #[Route('/history', name: 'app_admin_configuration_history', methods: ['GET'])]
    public function history(ConfigChangeRepository $changes, ConfigRegistry $config): Response
    {
        return $this->render('admin/configuration/history.html.twig', [
            'definition' => $config->definition(),
            'changes' => $changes->findLatest(),
        ]);
    }
}
