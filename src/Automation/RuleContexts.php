<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\RuleContext;
use App\Config\ConfigRegistry;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Repository\MembershipRepository;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;

/**
 * Builds the context a rule is written in. A project: its trackers, the
 * roles of the instance, its members (the active accounts that hold a
 * role of the configuration on it, directly or through its workspace). A
 * project template: its own trackers and the roles, without anybody,
 * since its rules are copied into projects whose members are not known.
 */
final class RuleContexts
{
    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly TemplateTrackerRepository $templateTrackers,
        private readonly MembershipRepository $memberships,
        private readonly ConfigRegistry $config,
    ) {
    }

    public function ofProject(Project $project): RuleContext
    {
        $definition = $this->config->definition();
        $trackers = [];
        $workflows = [];
        foreach ($this->trackers->findByProject($project, includeArchived: true) as $tracker) {
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

        return new RuleContext($trackers, $workflows, $definition->places, $roles, $people);
    }

    public function ofTemplate(ProjectTemplate $template): RuleContext
    {
        $definition = $this->config->definition();
        $trackers = [];
        $workflows = [];
        foreach ($this->templateTrackers->findByTemplate($template) as $tracker) {
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

        return new RuleContext($trackers, $workflows, $definition->places, $roles);
    }
}
