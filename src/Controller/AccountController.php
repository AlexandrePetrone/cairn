<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attachment\AvatarStorage;
use App\Entity\User;
use App\Form\MailSettingsData;
use App\Form\MailSettingsType;
use App\Form\PasswordChangeData;
use App\Form\PasswordChangeType;
use App\Message\Command\User\ChangeAvatar;
use App\Message\Command\User\ChangePassword;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pages of the signed-in user's own account, one per subject: the
 * profile (their picture; who they are is shown, the administration
 * changes it), the notifications (how their mails are grouped) and the
 * security (their password). The API tokens are ApiTokenController's.
 * The pictures are served to whoever is signed in, wherever a person is
 * shown.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account', name: 'app_account', methods: ['GET'])]
    public function profile(#[CurrentUser] User $user): Response
    {
        return $this->render('account/profile.html.twig', ['user' => $user]);
    }

    #[Route('/account/avatar', name: 'app_account_avatar', methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function avatar(#[CurrentUser] User $user, Request $request): Response
    {
        $file = $request->files->get('avatar');
        try {
            $this->commands->dispatch(new ChangeAvatar((int) $user->getId(), $file instanceof UploadedFile ? $file : null));
            $this->addFlash('success', $this->translator->trans($file instanceof UploadedFile ? 'account.profile.picture.saved' : 'account.profile.picture.removed'));
        } catch (ValidationFailedException) {
            $this->addFlash('error', $this->translator->trans('account.profile.picture.refused'));
        }

        return $this->redirectToRoute('app_account');
    }

    #[Route('/account/notifications', name: 'app_account_notifications', methods: ['GET', 'POST'])]
    public function notifications(#[CurrentUser] User $user, Request $request): Response
    {
        $data = new MailSettingsData($user->getMailGrouping());
        $form = $this->createForm(MailSettingsType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateUser((int) $user->getId(), mailGrouping: $data->mailGrouping, actorId: (int) $user->getId()));
            $this->addFlash('success', $this->translator->trans('account.notifications.saved'));

            return $this->redirectToRoute('app_account_notifications');
        }

        return $this->render('account/notifications.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/account/security', name: 'app_account_security', methods: ['GET', 'POST'])]
    public function security(
        #[CurrentUser]
        User $user,
        Request $request,
        Security $security,
        #[Target('password_change.limiter')]
        RateLimiterFactoryInterface $limiter,
    ): Response {
        // Every attempt counts, right or wrong, before the form says anything about the current password.
        if ($request->isMethod('POST') && !$limiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('account.security.too_many_attempts'));

            return $this->redirectToRoute('app_account_security');
        }

        $data = new PasswordChangeData();
        $form = $this->createForm(PasswordChangeType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new ChangePassword((int) $user->getId(), (string) $data->currentPassword, (string) $data->newPassword));
                // The new password ended every session of the account; this one goes on.
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', $this->translator->trans('account.security.saved'));

                return $this->redirectToRoute('app_account_security');
            } catch (UserException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('account/security.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/avatars/{name}', name: 'app_avatar', requirements: ['name' => AvatarStorage::NAME_PATTERN], methods: ['GET'])]
    public function picture(string $name, AvatarStorage $storage): BinaryFileResponse
    {
        return $storage->serve($name) ?? throw $this->createNotFoundException('No such picture.');
    }
}
