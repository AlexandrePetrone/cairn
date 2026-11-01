<?php

declare(strict_types=1);

namespace App\Twig\Components\Membership;

use App\Config\ConfigRegistry;
use App\Entity\Membership;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjects;
use App\Message\Query\Workspace\ListWorkspaces;
use App\Message\QueryBus;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Twig\Components\DispatchesCommands;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The memberships of a workspace (who holds a role on it) or of a user
 * (where they hold one), exactly one of the two: grant a role, change it,
 * take it back after a confirmation. The rules are GrantMembership's; a
 * refusal is shown in place.
 */
#[AsLiveComponent('Membership:Grants')]
final class Grants
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public ?Workspace $workspace = null;

    #[LiveProp]
    public ?User $user = null;

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

    /** The membership whose removal waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $revoking = null;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly QueryBus $queries,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function mount(?Workspace $workspace = null, ?User $user = null): void
    {
        if ((null === $workspace) === (null === $user)) {
            throw new \LogicException('Membership:Grants lists the memberships of a workspace or of a user.');
        }
        $this->workspace = $workspace;
        $this->user = $user;
        $this->align();
    }

    /**
     * @return list<Membership>
     */
    public function getMemberships(): array
    {
        if (null !== $this->workspace) {
            return $this->memberships->findOnWorkspace($this->workspace);
        }

        return $this->memberships->findOfUser($this->user ?? throw new \LogicException('Membership:Grants needs a workspace or a user.'));
    }

    /**
     * The roles of the configuration that may go to the user: an external
     * user gets no internal-only role. Without a user yet, all of them.
     *
     * @return array<string, string> role id => label
     */
    public function rolesFor(?User $user): array
    {
        $roles = [];
        foreach ($this->config->definition()->roles as $role) {
            if (null === $user || $user->isInternal() || !$role->internalOnly) {
                $roles[$role->id] = $role->label;
            }
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
     * On a workspace: the active users who hold no role on it yet.
     *
     * @return list<User>
     */
    public function getCandidates(): array
    {
        $members = array_map(static fn (Membership $membership): ?int => $membership->getUser()->getId(), $this->getMemberships());

        return array_values(array_filter(
            $this->users->findBy(['active' => true], ['lastName' => 'ASC', 'firstName' => 'ASC']),
            static fn (User $user): bool => !\in_array($user->getId(), $members, true),
        ));
    }

    public function getNewUser(): ?User
    {
        return null !== $this->newUserId ? $this->users->find($this->newUserId) : null;
    }

    /**
     * For a user: the workspaces, each followed by its projects.
     *
     * @return array<string, array<string, string>> workspace name => [target => label]
     */
    public function getTargets(): array
    {
        $targets = [];
        foreach ($this->queries->ask(new ListWorkspaces()) as $workspace) {
            $targets[$workspace->getName()]['workspace-'.$workspace->getId()] = $this->translator->trans('admin.access.whole_workspace', ['%name%' => $workspace->getName()]);
        }
        foreach ($this->queries->ask(new ListProjects()) as $project) {
            $targets[$project->getWorkspace()->getName()]['project-'.$project->getId()] = $project->getIssuePrefix().' · '.$project->getName();
        }

        return $targets;
    }

    #[LiveAction]
    public function grant(CommandBus $commands): void
    {
        $this->error = null;
        if (null !== $this->workspace) {
            $command = null !== $this->newUserId ? new GrantMembership($this->newUserId, $this->workspace->getId(), null, $this->newRole) : null;
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

        $this->newUserId = null;
        $this->newTarget = '';
        $this->newRole = '';
        $this->done('admin.access.granted');
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
        );
        if (!$this->tryCommand($commands, $command)) {
            $this->align();

            return;
        }

        $this->done('admin.access.changed');
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
        if (null !== $this->revoking && $this->tryCommand($commands, new RevokeMembership($this->revoking))) {
            $this->done('admin.access.revoked');
        }
        $this->revoking = null;
    }

    private function done(string $message): void
    {
        $this->align();
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans($message)]);
    }

    /**
     * Aligns the role of each row with the database.
     */
    private function align(): void
    {
        $this->roles = [];
        foreach ($this->getMemberships() as $membership) {
            $this->roles[(int) $membership->getId()] = $membership->getRole();
        }
    }
}
