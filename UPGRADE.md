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

Nothing to change for the file to load. Three permissions are new and
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
