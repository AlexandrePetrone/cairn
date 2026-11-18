<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Finds the account the identity provider just authenticated. Nobody is
 * created here: the account exists, and an administrator told it to sign
 * in through the provider (User::usesSso()), so an account that has a
 * password is never opened on the word of the provider. It is found by
 * the subject the provider gives it; the first time, by the address the
 * provider vouches for, and the subject is then kept on the account
 * (UserRepository::bindSubject()), so that an address changed on either
 * side afterwards neither loses the account nor gives it to someone else.
 * Whatever the reason, a refusal reads the same on the sign-in page.
 *
 * @implements AttributesBasedUserProviderInterface<User>
 */
final class OidcUserProvider implements AttributesBasedUserProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array<array-key, mixed> $attributes the claims of the provider
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): UserInterface
    {
        $user = $this->users->findOneBy(['oidcSubject' => $identifier]);
        if (null !== $user) {
            return $user->usesSso() ? $user : throw self::noAccount();
        }

        $email = $attributes['email'] ?? null;
        // A provider that says the address was not verified vouches for nothing.
        if (!\is_string($email) || '' === trim($email) || false === ($attributes['email_verified'] ?? null) || 'false' === ($attributes['email_verified'] ?? null)) {
            throw self::noAccount();
        }

        $user = $this->users->loadUserByIdentifier($email);
        if (null === $user || !$user->usesSso() || null !== $user->getOidcSubject()) {
            throw self::noAccount();
        }

        $this->users->bindSubject($user, $identifier);

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->users->find($user->getId()) ?? throw new UserNotFoundException();
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }

    private static function noAccount(): CustomUserMessageAuthenticationException
    {
        return new CustomUserMessageAuthenticationException('auth.sso.no_account');
    }
}
