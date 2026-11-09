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

No change to the configuration file yet.
