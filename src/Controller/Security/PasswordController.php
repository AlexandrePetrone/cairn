<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Form\PasswordRequestData;
use App\Form\PasswordRequestType;
use App\Form\PasswordSetData;
use App\Form\PasswordSetType;
use App\Message\Command\User\RequestPasswordReset;
use App\Message\Command\User\ResetPassword;
use App\Message\CommandBus;
use App\Security\PasswordLinkException;
use App\Security\PasswordTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;

/**
 * Choosing a password without being signed in: "Forgot password?" asks
 * for an address and always answers the same page; the link of the
 * mail (an invitation or a reset) is stored in the session and the
 * browser sent on to the form without it, so that the token stays out
 * of the history and the referrers. A link that cannot be used says
 * so, and no more.
 */
#[Route('/password')]
final class PasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly PasswordTokens $tokens,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forgot', name: 'app_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $data = new PasswordRequestData();
        $form = $this->createForm(PasswordRequestType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new RequestPasswordReset((string) $data->email));

            return $this->redirectToRoute('app_password_requested');
        }

        return $this->render('security/password_request.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/forgot/sent', name: 'app_password_requested', methods: ['GET'])]
    public function requested(): Response
    {
        return $this->render('security/password_requested.html.twig');
    }

    #[Route('/set/{token}', name: 'app_password_set', requirements: ['token' => '[A-Za-z0-9_-]+'], defaults: ['token' => null], methods: ['GET', 'POST'])]
    public function set(Request $request, ?string $token): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }
        if (null !== $token) {
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_password_set');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            return $this->refused(PasswordLinkException::invalid());
        }
        try {
            $user = $this->tokens->peek($token);
        } catch (PasswordLinkException $e) {
            return $this->refused($e);
        }

        $data = new PasswordSetData();
        $form = $this->createForm(PasswordSetType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new ResetPassword($token, (string) $data->password));
            } catch (PasswordLinkException $e) {
                return $this->refused($e);
            }
            $this->cleanSessionAfterReset();
            $this->addFlash('success', $this->translator->trans('auth.password.set_done'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/password_set.html.twig', ['form' => $form, 'user' => $user], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    private function refused(PasswordLinkException $exception): Response
    {
        $this->cleanSessionAfterReset();

        return $this->render('security/password_invalid.html.twig', ['reason' => $exception->trans($this->translator)]);
    }
}
