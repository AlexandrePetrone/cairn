# Upgrading an instance

The configuration of an instance (roles and rights, workflows, steps,
fields, link types) lives in its database and is managed from
Administration > Configuration. A new version of Cairn that changes its
structure changes the tables itself.

Until the first release, there is no migration: the schema is derived
from the entities, and an instance is upgraded with
`bin/console doctrine:schema:update --force`. From the first release on,
every version comes with its Doctrine migrations, applied with
`bin/console doctrine:migrations:migrate`: they are all an upgrade
needs, and this file will list, version by version, what an
administrator may want to review afterwards (a new right nobody holds
yet, for instance: a new right is granted to no role until a migration
or an administrator grants it).

## Unreleased

**The configuration moved from a PHP file to the database.** The file
pointed to by `CAIRN_CONFIG`, `app:config:sync` and the configuration
snapshots are gone. No instance was released with them, so nothing
converts a file: recreate the database, then run
`bin/console app:config:install` for the starter configuration, or
configure the instance from the administration.
