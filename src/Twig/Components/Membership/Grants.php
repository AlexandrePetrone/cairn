<?php

declare(strict_types=1);

namespace App\Twig\Components\Membership;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Membership\SetReferent;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\InviteUser;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The memberships of a workspace (who holds a role on it), of a user
 * (where they hold one) or of a project (who holds a role on it directly),
 * exactly one of the three: grant a role, change it, take it back after a
 * confirmation. The rules are GrantMembership's; a refusal is shown in
 * place. The first two serve the administration, which acts without an
 * actor and picks the person, or the workspace or project, in a
 * searchable list. On a project, the viewer acts and the roles inherited
 * from the workspace are shown read-only, a direct role only widening
 * them; someone is added by email: an existing account gets a role, an
 * unknown address gets an external account, the role and an invitation,
 * in a dialog the page opens (`adding`, from `?add=1` or its button).
 * A member who has not chosen their password yet can be invited again.
 * Each row can be made the referent of its role (SetReferent): on a
 * project the referents inherited from the workspace only show.
 */
#[AsLiveComponent('Membership:Grants')]
final class Grants
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    /** Under the email field, what the address led to. */
    public const string NONE = 'none';
    public const string NEW = 'new';
    public const string EXISTING = 'existing';
    public const string MEMBER = 'member';
    public const string CEILING = 'ceiling';
    public const string DEACTIVATED = 'deactivated';

    #[LiveProp]
    public ?Workspace $workspace = null;

    #[LiveProp]
    public ?User $user = null;

    #[LiveProp]
    public ?Project $project = null;

    /** @var array<int, string> membership id => role, bound to the role of each row */
    #[LiveProp(writable: true)]
    public array $roles = [];

    /** On a workspace: who gets the new role. */
    #[LiveProp(writable: true)]
    public ?int $newUserId = null;

    /** For a user: where the new role applies, `workspace-3` or `project-5`. */
    #[LiveProp(writable: true)]
    public string $newTarget = '';

    #[LiveProp(writable: true)]
    public string $newRole = '';

    /** On a project: whether the dialog adding someone is open; the page opens it when its address says `?add=1`. */
    #[LiveProp(writable: true)]
    public bool $adding = false;

    /** On a project: the address of who gets the new role. */
    #[LiveProp(writable: true, onUpdated: 'onEmailChanged')]
    public string $email = '';

    /** On a project, for an unknown address: the account to create. */
    #[LiveProp(writable: true)]
    public string $firstName = '';

    #[LiveProp(writable: true)]
    public string $lastName = '';

    #[LiveProp(writable: true)]
    public string $company = '';

    /** The membership whose removal waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $revoking = null;

    /** @var list<Membership>|null read once per render, `align()` forgets it */
    private ?array $loadedMemberships = null;

    /** @var array<int, Membership>|null read once per render, `align()` forgets it */
    private ?array $loadedInherited = null;

    /** @var array{string, User|null}|null what `email` leads to, read once per render */
    private ?array $loadedMatch = null;

    /** @var list<array{workspace: Workspace, projects: list<Project>}>|null read once per render */
    private ?array $loadedTargets = null;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly QueryBus $queries,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function mount(?Workspace $workspace = null, ?User $user = null, ?Project $project = null, bool $adding = false): void
    {
        $this->adding = $adding && null !== $project;
        if (1 !== \count(array_filter([$workspace, $user, $project]))) {
            throw new \LogicException('Membership:Grants lists the memberships of a workspace, of a user or of a project.');
        }
        $this->workspace = $workspace;
        $this->user = $user;
        $this->project = $project;
        $this->align();
    }

    public function onEmailChanged(): void
    {
        $this->loadedMatch = null;
        $this->newRole = '';
        [$this->firstName, $this->lastName] = self::guessName($this->email);
        $this->company = '';
        $this->error = null;
    }

    /**
     * The first and last name an address of the shape `first.last@…` gives,
     * for an account to create: the person adding only checks them.
     *
     * @return array{string, string}
     */
    private static function guessName(string $email): array
    {
        $local = strstr(trim($email), '@', true);
        $parts = false === $local ? [] : explode('.', $local);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1] || 1 === preg_match('/\d/', $local)) {
            return ['', ''];
        }
        $word = static fn (string $part): string => implode('-', array_map(static fn (string $piece): string => mb_convert_case($piece, \MB_CASE_TITLE), explode('-', $part)));

        return [$word($parts[0]), $word($parts[1])];
    }

    /**
     * @return list<Membership>
     */
    public function getMemberships(): array
    {
        return $this->loadedMemberships ??= $this->loadMemberships();
    }

    /**
     * On a project: the roles inherited from its workspace, by user id.
     *
     * @return array<int, Membership>
     */
    public function getInherited(): array
    {
        return $this->loadedInherited ??= $this->loadInherited();
    }

    /**
     * The roles of the configuration that may go to the user: an external
     * user gets no internal-only role, and on a project only the roles
     * wider than the one they inherit from the workspace. Without a user
     * yet, all of them, or those of an external user when `external`.
     *
     * @return array<string, string> role id => label
     */
    public function rolesFor(?User $user, bool $external = false): array
    {
        $definition = $this->config->definition();
        $inherited = null !== $user ? ($this->getInherited()[(int) $user->getId()] ?? null)?->getRole() : null;
        $ceiling = null !== $inherited && $definition->hasRole($inherited) ? $definition->roleRank($inherited) : null;
        $external = $external || (null !== $user && !$user->isInternal());

        $roles = [];
        foreach ($definition->roles as $role) {
            if ($external && $role->internalOnly) {
                continue;
            }
            if (null !== $ceiling && $definition->roleRank($role->id) >= $ceiling) {
                continue;
            }
            $roles[$role->id] = $role->label;
        }

        return $roles;
    }

    /**
     * The label of a role, or its id when the configuration dropped it.
     */
    public function roleLabel(string $role): string
    {
        $definition = $this->config->definition();

        return $definition->hasRole($role) ? $definition->role($role)->label : $role;
    }

    /**
     * On a workspace: the active users who hold no role on it yet and may
     * be given one.
     *
     * @return list<User>
     */
    public function getCandidates(): array
    {
        $members = array_map(static fn (Membership $membership): ?int => $membership->getUser()->getId(), $this->getMemberships());

        return array_values(array_filter(
            $this->users->findActive(),
            fn (User $user): bool => !\in_array($user->getId(), $members, true) && [] !== $this->rolesFor($user),
        ));
    }

    public function getNewUser(): ?User
    {
        return null !== $this->newUserId ? $this->users->find($this->newUserId) : null;
    }

    /**
     * For a user: the workspaces, each with its projects.
     *
     * @return list<array{workspace: Workspace, projects: list<Project>}>
     */
    public function getTargets(): array
    {
        return $this->loadedTargets ??= $this->loadTargets();
    }

    /**
     * @return list<array{workspace: Workspace, projects: list<Project>}>
     */
    private function loadTargets(): array
    {
        $projects = [];
        foreach ($this->queries->ask(new ListProjects()) as $project) {
            $projects[(int) $project->getWorkspace()->getId()][] = $project;
        }
        $targets = [];
        foreach ($this->queries->ask(new ListWorkspaces()) as $workspace) {
            $targets[] = ['workspace' => $workspace, 'projects' => $projects[(int) $workspace->getId()] ?? []];
        }

        return $targets;
    }

    /**
     * What the chosen target reads as, for the picker's button.
     */
    public function getTargetLabel(): ?string
    {
        [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
        foreach ($this->getTargets() as ['workspace' => $workspace, 'projects' => $projects]) {
            if ('workspace' === $kind && $workspace->getId() === (int) $id) {
                return $this->translator->trans('admin.access.whole_workspace', ['%name%' => $workspace->getName()]);
            }
            foreach ($projects as $project) {
                if ('project' === $kind && $project->getId() === (int) $id) {
                    return $project->getIssuePrefix().' · '.$project->getName();
                }
            }
        }

        return null;
    }

    /**
     * The companies already known, suggested when creating an account.
     *
     * @return list<string>
     */
    public function getCompanies(): array
    {
        return $this->users->findCompanies();
    }

    /**
     * On a project: the account behind the address typed, if any.
     */
    public function getMatch(): ?User
    {
        return ($this->loadedMatch ??= $this->match())[1];
    }

    /**
     * On a project: what the address typed leads to, one of the constants.
     */
    public function getMatchState(): string
    {
        return ($this->loadedMatch ??= $this->match())[0];
    }

    /**
     * @return array{string, User|null} the state and the account
     */
    private function match(): array
    {
        $email = trim($this->email);
        // what `CreateUser` would refuse is not looked up: one rule says what an address is
        if ('' === $email || 0 < \count($this->validator->validatePropertyValue(CreateUser::class, 'email', $email))) {
            return [self::NONE, null];
        }
        $match = $this->users->loadUserByIdentifier($email);
        if (null === $match) {
            return [self::NEW, null];
        }
        if (!$match->isActive()) {
            return [self::DEACTIVATED, $match];
        }
        foreach ($this->getMemberships() as $membership) {
            if ($membership->getUser()->getId() === $match->getId()) {
                return [self::MEMBER, $match];
            }
        }

        return [[] === $this->rolesFor($match) ? self::CEILING : self::EXISTING, $match];
    }

    #[LiveAction]
    public function chooseTarget(#[LiveArg] string $target): void
    {
        $this->newTarget = $target;
    }

    #[LiveAction]
    public function chooseUser(#[LiveArg] int $user): void
    {
        $this->newUserId = $user;
    }

    #[LiveAction]
    public function grant(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->workspace) {
            $command = null !== $this->newUserId ? new GrantMembership($this->newUserId, $this->workspace->getId(), null, $this->newRole) : null;
        } elseif (null !== $this->project) {
            $match = self::EXISTING === $this->getMatchState() ? $this->getMatch() : null;
            $command = null !== $match ? new GrantMembership((int) $match->getId(), null, $this->project->getId(), $this->newRole, $this->actorId()) : null;
        } else {
            [$kind, $id] = explode('-', $this->newTarget.'-', 3) + [1 => ''];
            $command = match ($kind) {
                'workspace' => new GrantMembership((int) $this->user?->getId(), (int) $id, null, $this->newRole),
                'project' => new GrantMembership((int) $this->user?->getId(), null, (int) $id, $this->newRole),
                default => null,
            };
        }
        if (null === $command || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.access.incomplete');

            return;
        }
        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->reset();
        $this->adding = false;
        $this->done('admin.access.granted');
    }

    /**
     * On a project, for an unknown address: the external account, its
     * role, then its invitation, each its own command. A refusal stops
     * there and is shown; what went through stays.
     */
    #[LiveAction]
    public function invite(CommandBus $commands): void
    {
        $this->error = null;
        if (null === $this->project || self::NEW !== $this->getMatchState() || '' === $this->newRole) {
            $this->error = $this->translator->trans('admin.access.incomplete');

            return;
        }

        $create = new CreateUser(trim($this->email), $this->firstName, $this->lastName, company: $this->company, actorId: $this->actorId(), projectId: $this->project->getId());
        if (!$this->tryCommand($commands, $create, $this->translator->trans('project.access.invalid_account'))) {
            return;
        }
        $this->loadedMatch = null;
        $userId = (int) $this->getMatch()?->getId();

        if (!$this->tryCommand($commands, new GrantMembership($userId, null, $this->project->getId(), $this->newRole, $this->actorId()))) {
            return;
        }
        if (!$this->tryCommand($commands, new InviteUser($userId, $this->actorId(), $this->project->getId()))) {
            return;
        }

        $this->reset();
        $this->adding = false;
        $this->done('project.access.invited');
    }

    /**
     * On a project: opens the dialog adding someone.
     */
    #[LiveAction]
    public function open(): void
    {
        // what a row was refused has nothing to say in the dialog
        $this->error = null;
        $this->adding = true;
    }

    /**
     * On a project: closes the dialog adding someone, forgetting what it held.
     */
    #[LiveAction]
    public function close(): void
    {
        $this->reset();
        $this->error = null;
        $this->adding = false;
    }

    /**
     * Sends a member their invitation again.
     */
    #[LiveAction]
    public function resend(CommandBus $commands, #[LiveArg] int $user): void
    {
        $this->error = null;
        if ($this->tryCommand($commands, new InviteUser($user, $this->actorId(), $this->project?->getId()))) {
            $this->done('project.access.resent');
        }
    }

    #[LiveAction]
    public function changeRole(CommandBus $commands, #[LiveArg] int $membership): void
    {
        $this->error = null;
        $current = $this->memberships->find($membership);
        if (null === $current) {
            return;
        }
        $command = new GrantMembership(
            (int) $current->getUser()->getId(),
            $current->getWorkspace()?->getId(),
            $current->getProject()?->getId(),
            $this->roles[$membership] ?? '',
            $this->actorId(),
        );
        if (!$this->tryCommand($commands, $command)) {
            $this->align();

            return;
        }

        $this->done('admin.access.changed');
    }

    /**
     * Makes the member of a row the referent of their role, or no longer.
     */
    #[LiveAction]
    public function setReferent(CommandBus $commands, #[LiveArg] int $membership, #[LiveArg] int $referent): void
    {
        $this->error = null;
        if ($this->tryCommand($commands, new SetReferent($membership, 1 === $referent, $this->actorId()))) {
            $this->done(1 === $referent ? 'admin.access.referent_designated' : 'admin.access.referent_withdrawn');
        }
    }

    #[LiveAction]
    public function confirmRevoke(#[LiveArg] int $membership): void
    {
        $this->revoking = $membership;
    }

    #[LiveAction]
    public function cancelRevoke(): void
    {
        $this->revoking = null;
    }

    #[LiveAction]
    public function revoke(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->revoking && $this->tryCommand($commands, new RevokeMembership($this->revoking, $this->actorId()))) {
            $this->done('admin.access.revoked');
        }
        $this->revoking = null;
    }

    /**
     * On a project, the viewer acts and their rights are checked; the
     * administration acts without an actor.
     */
    private function actorId(): ?int
    {
        return null !== $this->project ? $this->viewer->get()->getId() : null;
    }

    private function reset(): void
    {
        $this->newUserId = null;
        $this->newTarget = '';
        $this->newRole = '';
        $this->email = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->company = '';
        $this->loadedMatch = null;
    }

    private function done(string $message): void
    {
        $this->align();
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans($message)]);
    }

    /**
     * Aligns the role of each row with the database, read again.
     */
    private function align(): void
    {
        $this->loadedMemberships = null;
        $this->loadedInherited = null;
        $this->roles = [];
        foreach ($this->getMemberships() as $membership) {
            $this->roles[(int) $membership->getId()] = $membership->getRole();
        }
    }

    /**
     * @return list<Membership>
     */
    private function loadMemberships(): array
    {
        if (null !== $this->workspace) {
            return $this->memberships->findOnWorkspace($this->workspace);
        }
        if (null !== $this->project) {
            return $this->memberships->findOnProject($this->project);
        }

        return $this->memberships->findOfUser($this->user ?? throw new \LogicException('Membership:Grants needs a workspace, a user or a project.'));
    }

    /**
     * @return array<int, Membership>
     */
    private function loadInherited(): array
    {
        if (null === $this->project) {
            return [];
        }
        $inherited = [];
        foreach ($this->memberships->findOnWorkspace($this->project->getWorkspace()) as $membership) {
            $inherited[(int) $membership->getUser()->getId()] = $membership;
        }

        return $inherited;
    }
}
