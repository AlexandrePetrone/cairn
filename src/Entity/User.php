<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function Symfony\Component\Clock\now;

/**
 * Someone who signs in. `internal` tells agency users from client users and
 * drives what is private; `admin` is an internal user who also manages
 * workspaces, users and memberships, and holds every permission. A
 * deactivated account cannot sign in (Security/UserChecker) and loses its
 * open sessions at the next request (isEqualTo). An account is created
 * without a password and gets one from its invitation link; `company`
 * is free text.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 80)]
    private string $firstName;

    #[ORM\Column(length: 80)]
    private string $lastName;

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $company = null;

    #[ORM\Column]
    private bool $internal;

    #[ORM\Column]
    private bool $admin = false;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $firstName, string $lastName, bool $internal = false, ?string $company = null)
    {
        $email = mb_strtolower(trim($email));
        if ('' === $email) {
            throw new \InvalidArgumentException('An email address is required.');
        }

        $this->email = $email;
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
        $this->internal = $internal;
        $this->changeCompany($company);
        $this->createdAt = now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * Display name: "Claire Martin".
     */
    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function reactivate(): void
    {
        $this->active = true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Makes the user part of the agency. Administrator status is only
     * meaningful for internal users.
     */
    public function makeInternal(bool $admin = false): void
    {
        $this->internal = true;
        $this->admin = $admin;
    }

    /**
     * A client user: neither internal nor administrator.
     */
    public function makeExternal(): void
    {
        $this->internal = false;
        $this->admin = false;
    }

    public function rename(string $firstName, string $lastName): void
    {
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
    }

    /**
     * Stored lowercase, as the sign-in looks it up.
     */
    public function changeEmail(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if ('' === $email) {
            throw new \InvalidArgumentException('An email address is required.');
        }

        $this->email = $email;
    }

    /**
     * The organisation the person belongs to, as typed: free text, shared
     * through the suggestions of the form. Empty means none.
     */
    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function changeCompany(?string $company): void
    {
        $company = trim((string) $company);
        $this->company = '' === $company ? null : $company;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    /**
     * An account without a password has not accepted its invitation yet:
     * it cannot sign in (Symfony refuses a null password) and may be
     * invited again.
     */
    public function hasPassword(): bool
    {
        return null !== $this->password;
    }

    /**
     * Still invited: active, without a password. The screens show it and
     * offer to send the invitation again.
     */
    public function awaitsPassword(): bool
    {
        return $this->active && null === $this->password;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : throw new \LogicException('A user always has an email address.');
    }

    /**
     * Whether the user of an open session is still the one in database.
     * Symfony compares password, identifier and roles on its own; `active`
     * is added so that deactivating an account ends its sessions.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $user->getId() === $this->getId()
            && $user->email === $this->email
            && $user->password === $this->password
            && $user->active === $this->active
            && $user->getRoles() === $this->getRoles();
    }

    /**
     * Symfony security roles only mirror the two flags; project roles come
     * from memberships and the configuration (see AccessControl).
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->internal) {
            $roles[] = 'ROLE_INTERNAL';
        }
        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }
}
