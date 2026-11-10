<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Form\ApiTokenData;
use App\Form\ApiTokenType;
use App\Message\Command\ApiToken\CreateApiToken;
use App\Message\Command\ApiToken\NewApiToken;
use App\Message\Command\ApiToken\RevokeApiToken;
use App\Message\CommandBus;
use App\MessageHandler\Command\ApiToken\ApiTokenException;
use App\Repository\ApiTokenRepository;
use App\Security\AccessControl;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The API tokens of the signed-in user: those they created, the form
 * creating one more, and revoking. For the users who may use the API
 * (AccessControl::canUseApi()). The secret of a new token is shown once,
 * on the page that follows its creation.
 */
#[Route('/account/tokens')]
final class ApiTokenController extends AbstractController
{
    /** The flash carrying the secret of the token just created to the next page, read by the template only. */
    private const string SECRET_FLASH = 'api_token_secret';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_api_tokens', methods: ['GET', 'POST'])]
    public function list(#[CurrentUser] User $user, Request $request, ApiTokenRepository $tokens, ClockInterface $clock): Response
    {
        $this->denyUnlessApiUser($user);
        $data = new ApiTokenData();
        $form = $this->createForm(ApiTokenType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $new = $this->commands->dispatch(new CreateApiToken((int) $user->getId(), (string) $data->name, $data->scope, $data->expiresAt($clock->now())));
                \assert($new instanceof NewApiToken);
                $this->addFlash(self::SECRET_FLASH, $new->secret);

                return $this->redirectToRoute('app_api_tokens');
            } catch (ApiTokenException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        $now = $clock->now();
        $all = $tokens->findByUser($user);

        return $this->render('api_tokens.html.twig', [
            'active' => array_filter($all, static fn (ApiToken $token): bool => $token->isUsable($now)),
            'past' => array_filter($all, static fn (ApiToken $token): bool => !$token->isUsable($now)),
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{token}/revoke', name: 'app_api_token_revoke', requirements: ['token' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function revoke(#[CurrentUser] User $user, #[MapEntity(id: 'token')] ApiToken $token): Response
    {
        $this->denyUnlessApiUser($user);
        try {
            $this->commands->dispatch(new RevokeApiToken((int) $user->getId(), (int) $token->getId()));
            $this->addFlash('success', $this->translator->trans('api_token.revoked', ['%name%' => $token->getName()]));
        } catch (ApiTokenException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('app_api_tokens');
    }

    private function denyUnlessApiUser(User $user): void
    {
        if (!$this->access->canUseApi($user)) {
            throw $this->createAccessDeniedException('The API is for internal users.');
        }
    }
}
