<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Message\Query\Worker\ShowWorkerStatus;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What waits for the worker: the one page that tells an administrator
 * that none runs, since nothing else in the application fails without it.
 */
#[IsGranted('ROLE_ADMIN')]
final class WorkerController extends AbstractController
{
    #[Route('/admin/worker', name: 'app_admin_worker', methods: ['GET'])]
    public function show(QueryBus $queries): Response
    {
        return $this->render('admin/worker.html.twig', ['status' => $queries->ask(new ShowWorkerStatus())]);
    }
}
