<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the controllers of the pages whose dialogs load in the `modal`
 * Turbo frame (`project/_form_dialog.html.twig`), of a project or of the
 * administration: a request made
 * for the frame is answered with the `modal` block of the page alone, the
 * dialog when the address opens one, the empty frame otherwise. So opening
 * a dialog reads nothing of the page behind it, a refused form draws the
 * dialog again and nothing else, and the page a form redirects to keeps
 * its flash for the visit that follows (the `modal-frame` controller).
 */
trait OpensDialog
{
    /**
     * @param array<string, mixed>             $dialog what the `modal` block reads: the form, what it changes, `project` in a project
     * @param \Closure(): array<string, mixed> $page   what only the whole page reads, not asked for the frame
     * @param FormInterface<mixed>|null        $form   the form of the dialog, when the address opens one
     */
    private function renderWithDialog(Request $request, string $view, array $dialog, \Closure $page, ?FormInterface $form = null): Response
    {
        $response = new Response(null, $form?->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        $response->setVary('Turbo-Frame', false);
        if (self::forDialog($request)) {
            return $this->renderBlock($view, 'modal', $dialog, $response);
        }

        return $this->render($view, $dialog + $page(), $response);
    }

    /**
     * Whether the request was made for the `modal` frame.
     */
    private static function forDialog(Request $request): bool
    {
        return 'modal' === $request->headers->get('Turbo-Frame');
    }
}
