<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\RuleContext;
use App\Config\ConfigRegistry;
use App\Entity\Project;
use App\Repository\MembershipRepository;
use App\Repository\TrackerRepository;

/**
 * Builds the context a rule is written in. Today a project: its active
 * trackers, the roles of the instance, its members (the active accounts
 * that hold a role of the configuration on it, directly or through its
 * workspace).
 */
final class RuleContexts
{
    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function ofProject(Project $project): RuleContext
    {
        $definition = $this->config->definition();
        $trackers = [];
        $workflows = [];
        foreach ($this->trackers->findByProject($project) as $tracker) {
            if (!$definition->hasWorkflow($tracker->getWorkflowId())) {
                continue;
            }
            $key = (string) $tracker->getId();
            $trackers[$key] = $tracker->getName();
            $workflows[$key] = $definition->workflow($tracker->getWorkflowId());
        }
        $roles = [];
        foreach ($definition->roles as $role) {
            $roles[$role->id] = $role->label;
        }
        $people = [];
        foreach ([...$this->memberships->findOnProject($project), ...$this->memberships->findOnWorkspace($project->getWorkspace())] as $membership) {
            $user = $membership->getUser();
            if ($user->isActive() && $definition->hasRole($membership->getRole())) {
                $people[(int) $user->getId()] = $user->getFullName();
            }
        }
        asort($people);

        return new RuleContext($trackers, $workflows, $roles, $people);
    }
}
