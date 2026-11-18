<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Users get the password `cairn` unless `plainPassword` is given;
 * `company` is a constructor argument, null by default.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'cairn';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    public function internal(): static
    {
        return $this->with(['internal' => true]);
    }

    public function admin(): static
    {
        return $this->with(['internal' => true, 'admin' => true]);
    }

    /**
     * Signs in through the identity provider: no password.
     */
    public function sso(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->signInWithSso();
        });
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'internal' => false,
            'company' => null,
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::withConstructor()->alwaysForce()->allowExtra('plainPassword'))
            ->afterInstantiate(function (User $user, array $attributes): void {
                $user->setPassword($this->passwordHasher->hashPassword($user, $attributes['plainPassword'] ?? self::DEFAULT_PASSWORD));
            });
    }
}
