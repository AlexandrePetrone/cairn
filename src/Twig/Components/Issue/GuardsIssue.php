<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * For the components of the page of an issue, which hold it as a prop: the
 * live endpoint is not under the route of the page, and the props a page
 * was given outlive what its viewer may see. An issue made private, or a
 * membership taken back, and the component answers as if the issue was
 * not there, each time it is drawn.
 */
trait GuardsIssue
{
    private AccessControl $issueAccess;
    private Viewer $issueViewer;

    #[Required]
    public function setIssueGuard(AccessControl $access, Viewer $viewer): void
    {
        $this->issueAccess = $access;
        $this->issueViewer = $viewer;
    }

    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->issueAccess->canSee($this->issueViewer->get(), $this->issue)) {
            throw new NotFoundHttpException('The issue is no longer visible.');
        }
    }
}
