# Upgrading an instance

Cairn is configured by a PHP file outside the repository (`CAIRN_CONFIG`),
applied to the database by `app:config:sync`. A new version of Cairn may
change the structure of that file: the arguments the definition objects of
`src/Config/Definition/` take. This file lists, version by version, what
to change in yours.

How a deployment behaves, depending on the change:

- **A property added with a default value, or nullable.** Nothing to do.
  The instance keeps running on the snapshot in force, your file loads as
  it is, and the next `app:config:sync` stores the snapshot in the new
  shape. Set the property when you want something else than the default.
- **A property made required, renamed or removed.** The instance refuses
  to serve pages after the deployment, with the reason in the logs, until
  you update the file and run `app:config:sync`. The sync names the line
  of the file that no longer loads and why; this file says what to write
  instead. Plan such an upgrade for a moment when the instance may be
  unavailable for a few minutes.

Run `app:config:sync --dry-run` first: it validates the file and shows
what changes without applying anything.

## Unreleased

**The file must change for this version**: places are declared once for
the instance, no longer in each workflow.

- `InstanceDefinition` takes a new required argument `places`: the list
  of every `PlaceDefinition` (`id`, `label`, `status`), in the order the
  issue lists filter and group by.
- `WorkflowDefinition::$places` is now the list of the ids of the places
  the workflow uses, in the order of its kanban columns:
  `places: ['new', 'in_progress', 'done']`.
- A place has one label and one status wherever it is used. Two
  workflows that gave the same id different labels must agree on one, or
  use two ids; places that mean the same under different ids (`doing`
  and `in_progress`) are worth merging, which is what lets a list across
  categories filter by step.
- `PlaceDefinition` no longer takes `migrateFrom`. A workflow says where
  the issues of a place it no longer has go:
  `migrations: ['doing' => 'in_progress']` on the `WorkflowDefinition`.
  Renaming the id of a place is such a migration, in each workflow that
  used it.
- The `place` filter of `GET /api/issues` takes place ids
  (`place[]=in_progress`), and a step of `GET /api/projects/{key}/trackers`
  no longer has a `key`.
- What named a place with its workflow before (`bug:in_progress`) no
  longer names anything: a trigger or a condition of an automation rule
  that did never holds until its steps are chosen again (a "changes
  step" trigger saved without choosing its step holds on every step),
  and the place
  filter of an issue list someone had kept, or of a shared address, is
  ignored.
- The sync now gives the issues of a place the status the file gives
  that place, when it changed: it lists them under "Data migrations".

Three permissions are also new and
held by nobody until you grant them:

- `budget.manage` (`Permission::BudgetManage`): create and close the
  budgets of a project that set a unit, delete a closed one, write,
  change and delete their lines. The example grants it to project managers.
- `budget.view` (`Permission::BudgetView`): read the budgets of a
  project and their lines, on their pages and on the issues the lines
  name. The example grants it to every role, clients included.
- `issue.delete` (`Permission::IssueDelete`): delete an issue for good,
  with its comments, files, links and history. For internal-only roles;
  the example grants it to project managers and developers.

Add them to `permissions`, then run `app:config:sync`.

The example workflow of features no longer has the `revise` action ("Ask
for a revision"): at "Estimate to approve" the client accepts or
refuses. Yours keeps what it defines.
