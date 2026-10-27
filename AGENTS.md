# AGENTS.md

Cairn is an issue tracker for a web agency: a single instance, ~50 client
organisations, ~80 active projects, several issue categories per project, each
with its own workflow. It replaces Redmine with a client-centred tool.

## Temporary specification files (will disappear)

> **Temporary.** During the initial build, the functional specification lives
> in the parent directory, outside this repository and untracked. These files
> will be deleted once the project is implemented. Do not link to them from
> code or docs; when something in them becomes a durable decision, copy it here.
>
> - `../context.md`: consolidated functional spec and decisions (vocabulary,
>   V1 scope, configuration pipeline, functional model).
> - `../tickets.config.php`: commented schema of the instance configuration
>   file (roles, permissions, fields, links, workflows). Its identifiers still
>   use `ticket.*` and `relations`; the code uses `issue.*` and `links`.
> - `../mockup/`: throwaway PHP + Tailwind mockups of every screen, plus
>   `brief-ux-demandes.md` (UX brief) and `_data.php` (sample data).

## Stack

Symfony 8.1 on PHP 8.4, installed from `webapp-pack`. Check `composer.json`
for the exact versions and `symfony.lock` for which recipes ran. Already in
place: Doctrine ORM + migrations, Messenger (Doctrine transport), Form,
Validator, Serializer, Security, Twig, Mailer, Notifier, Translation,
AssetMapper, Stimulus, Turbo.

Decisions already taken:

- Persistence: Doctrine ORM, schema compatible with both MariaDB and
  PostgreSQL. Nothing is ever physically deleted.
- Interface: server-rendered Twig + Turbo + UX Twig/Live Components, Tailwind
  for styling. The kanban and the rich-text editor are React islands.
- Auth: SecurityBundle, email + password, invitation of external users by
  email; agency SSO may come later.
- Workflow engine: the Symfony Workflow component, with state machines
  built at runtime from the configuration snapshot stored in database, not
  from YAML (`framework.workflows` stays empty). They enforce structure and
  dispatch the workflow events; who may trigger a transition is decided by
  `AccessControl`, never by a workflow guard.

Install missing capabilities with `composer require <package>` and let the
Flex recipe register the bundle and generate its config. Don't hand-edit
`config/bundles.php` or hand-write a bundle's base config. Planned additions:
`symfony/workflow`, `symfony/ux-twig-component`, `symfony/ux-live-component`,
`symfony/lock`, `symfony/scheduler`.

## Vocabulary

Use the English term in code, the French one in the UI (through translation
keys, see below). Never mix them.

| UI (fr)        | Code             | Meaning                                                          |
|----------------|------------------|------------------------------------------------------------------|
| Demande        | `Issue`          | The tracked object. Key `WORKSPACE-PROJECT-123`.                   |
| Catégorie      | `Tracker`        | Type of an issue within a project, bound to one workflow. In DB. |
| Workflow       | `Workflow`       | Places + transitions. Defined in configuration, not in the UI.   |
| Étape          | `Place`          | Position in a workflow (Symfony Workflow term).                  |
| Action         | `Transition`     | Named button that moves an issue to another place.               |
| Statut         | `PlaceStatus`    | Family of a place: Open, InProgress, Waiting, Closed. PHP enum.  |
| Criticité      | `Criticality`    | Low, Normal, High, Blocking. First-class column, not a field.    |
| Champ          | `Field`          | Global attribute, enabled per tracker.                           |
| Lien           | `Link`           | Related, Duplicates/DuplicatedBy, Blocks/BlockedBy.              |
| Espace         | `Workspace`      | Group of projects sharing memberships.                           |
| Projet         | `Project`        |                                                                  |
| Rôle           | `Role`           | Defined in configuration.                                        |
| Accès          | `Membership`     | Role of a user on a project or a workspace. No organisation entity: a client is a workspace. |
| Interne/Externe| `internal` flag  | Agency user vs client user.                                      |
| Privé          | `private`        | Hidden from external users.                                      |
| Version        | `Release`        | Planned delivery, optional per project.                          |
| Historique     | `JournalEntry`   | Immutable journal, keeps the labels of the time.                 |
| Fichier        | `Attachment`     |                                                                  |
| —              | `ConfigSnapshot` | Applied configuration definition, stored in database.            |

## Architecture

Standard Symfony layout plus a light command/query separation. No modules,
no hexagonal layers, no event sourcing, no query bus. A Symfony developer
must find their bearings by opening `src/`.

```
src/
  Entity/            single flat folder; persisted enums in Entity/Enum/
  Repository/        one per entity: find by id/key and simple finders only
  Message/           write commands, grouped by domain: Issue/, Project/, …
  MessageHandler/    exact mirror of Message/, one handler per command
  Query/             screen reads: filters, visibility, multi-entity queries
  Event/             domain events: IssueCreated, IssueTransitioned, …
  EventListener/     journal recorder, notification dispatcher, …
  Controller/        grouped by screen: Issue/, Project/, Admin/, Webhook/
  Form/              form types and their data objects
  Security/          voters, AccessControl, RoleResolver, authenticators
  Twig/Components/   UX Twig and Live components
  Config/            instance config pipeline: Definition/, validator, diff,
                     sync, ConfigRegistry; config-only enums
  Workflow/          state machines built from the snapshot (WorkflowFactory,
                     WorkflowProvider), HasPlace for the objects they move
  Notification/      subscription rules, mail builders, digest
  GitLab/            webhook payload, reference parser, related enums
  Command/           console commands (app:config:sync, digest, …)
```

Rules:

- **Writes are commands.** Every mutation is a Messenger message in
  `Message/<Domain>/` (readonly, `#[Assert]` constraints) handled by
  `MessageHandler/<Domain>/` on the dedicated `command.bus`, whose
  middlewares validate the command and wrap the handler in a Doctrine
  transaction (the flush is theirs, handlers only persist). Callers go
  through `Message/CommandBus::dispatch()`, which returns the handler's
  result and rethrows the handler's own exception. Controllers, Live
  components, webhooks and console commands build a command and dispatch
  it. They contain no business rules. Authorization failures are Symfony's
  `AccessDeniedException`; business refusals are a domain exception next to
  the handler (`IssueException`).
- **Reads are queries.** A repository answers "give me this entity". As soon
  as a read depends on filters, on visibility or spans several entities, it
  is a class in `Query/<Domain>/` with its own filter DTO. Queries never
  mutate; commands never return data beyond the created identifier.
- **Side effects go through events.** A handler dispatches a domain event
  with the EventDispatcher inside the transaction. Whatever must be atomic
  with the write, such as the journal, is written in the handler or by a
  synchronous listener. Whatever may fail independently, such as mail, is
  re-dispatched as an asynchronous Messenger message with
  `DispatchAfterCurrentBusStamp` so it only leaves after commit.
- **One authorization service.** `Security/AccessControl` takes the acting
  user as an explicit argument and resolves the effective role (direct
  membership, then workspace membership, then global role, the broadest
  wins). Handlers call it directly. Voters are
  thin adapters over it for `#[IsGranted]` and Twig.
- **Entities carry intent.** Prefer `moveTo()`, `makePrivate()`, `close()`
  over generic setters. Entities never receive services.
- **Only `Config/` and `Workflow/` are self-contained subtrees**, because they
  have no equivalent in a standard Symfony project.
- **No catch-all folders.** No `Service/`, no central `Enum/`, no `Dto/`.
  An enum or DTO lives next to what gives it meaning: `Criticality` and
  `PlaceStatus` in `Entity/Enum/` because they are persisted, `FieldType` and
  `MigrationPolicy` in `Config/`, webhook payloads in `GitLab/`,
  `IssueFilter` in `Query/Issue/`, form data objects in `Form/`. When an enum
  has two owners, persistence wins and `Config/` imports from `Entity/Enum/`.
- **Templates** follow screens, not PHP namespaces: `templates/issue/show.html.twig`,
  `templates/components/` for UX components.

### Configuration and snapshot

Workflows, roles, global permissions, fields and link types live in one PHP
configuration file outside the repository, loaded from an environment
variable (see `config/instance.example.php`). Trackers, projects,
memberships and releases live in database. Criticality
levels and statuses are fixed enums in code. Notification rules and GitLab
settings are not part of the file yet: add them when those features are
built, shaped by their actual consumer.

The file is never read at runtime. It is loaded, validated, normalised into
readonly definition objects, diffed against the last `ConfigSnapshot`, and
applied by `app:config:sync` (idempotent, locked with `symfony/lock`,
`--dry-run`). The snapshot is the source of truth in production. The sync is
also a plain service so that a future back-office can reuse it.

An issue's state is stored as a place identifier string, not a foreign key.
Places separate a stable technical id from a label; a removed place either
declares `migrate_from` on its successor or the sync refuses (strict policy
by default). The journal is never migrated.

## Conventions

- Code, identifiers, comments, commit messages and this file are in English.
- **One language per instance**, set by `CAIRN_LOCALE` (wired to
  `framework.default_locale`). There is no per-user locale: issues are
  written in one language, so the interface follows it. Two kinds of text:
  - Interface strings written in code (menus, buttons, validation and mail
    templates, enum labels such as `PlaceStatus`) go through translation
    keys and `translations/messages.<locale>.yaml`. Keys are named by
    domain (`status.open`, `issue.action.create`), never English sentences.
    No hardcoded labels in Twig or PHP, even though only French is shipped
    in V1. `debug:translation fr` lists missing and unused keys.
  - Content written by the agency or the users (configuration labels,
    hints and prompts; project, tracker, issue and comment text) is data in
    the instance language. It is displayed as-is and never passed to the
    translator.
- Follow https://symfony.com/doc/current/best_practices.html:
  - PHP attributes for all framework metadata: `#[Route]`,
    `#[MapRequestPayload]`, `#[IsGranted]`, `#[Assert\...]`, `#[AsCommand]`,
    `#[AsEventListener]`, `#[AsMessageHandler]`, `#[AsLiveComponent]`. No YAML
    or XML routing.
  - Autowiring and autoconfiguration. Type-hint constructor arguments; use
    `#[Autowire]` and `#[Target]` where a type-hint can't express it. A YAML
    service definition is the last resort.
  - Controllers extend `AbstractController` and stay thin.
  - Constructor property promotion; `readonly` for DTOs, commands, events and
    definition objects. Don't mark a service `readonly` if it may become lazy.
  - Use the framework before writing infrastructure: Form, Validator,
    Serializer, Messenger, Security voters, `symfony/lock`, Scheduler, Twig
    `path()`/`url()`.
- Never call `new \DateTime*()`, `date()` or `time()` in application code.
  Entities use the `Symfony\Component\Clock\now()` function, services inject
  `ClockInterface`; tests freeze time with `ClockSensitiveTrait::mockTime()`.
- **No migrations before the first release.** Until then the schema is
  derived from the entities: apply changes with
  `doctrine:schema:update --force` (or drop and recreate), and don't write
  anything under `migrations/`. Once the first release is tagged, every schema
  change goes through `make:migration` then `doctrine:migrations:migrate`,
  never `doctrine:schema:update` or SQL by hand.
- `.env` holds defaults only. Real secrets go in `.env.local` or the secrets
  vault, read via `%env(...)%`.

## Everyday workflow

- Run the app with `symfony serve -d`, commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available). In dev and test
  the database is SQLite under `var/` (see `DATABASE_URL` in `.env`); the
  Docker Compose Postgres service is there for testing against a real
  server when needed (`docker compose up -d database`, the Symfony CLI then
  injects its `DATABASE_URL`).
- `CAIRN_CONFIG` points to the instance configuration file; `.env` defaults
  to the committed `config/instance.example.php`. After changing it, run
  `symfony console app:config:sync --dry-run`, then without `--dry-run`.
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- Prefer `bin/console make:*` with every argument passed up front and
  `--no-interaction`; makers prompt on a terminal by default, which hangs a
  non-interactive shell. If a maker still needs input, hand-write the code.
- Discover, don't guess: `bin/console about`, `debug:router`,
  `debug:container`, `debug:autowiring <name>`, `debug:config <bundle>`,
  `config:dump-reference <bundle>`, `lint:container`, `lint:twig templates/`,
  `lint:yaml config/`. Read the installed source under `vendor/` and the docs
  at https://symfony.com/doc/current/.

## Testing

Functional tests extend `WebTestCase`; handler and service tests extend
`KernelTestCase` and run against a real database: `tests/bootstrap.php`
builds the test schema from the entities and `dama/doctrine-test-bundle`
rolls every test back. Run `castor test` (arguments are passed to PHPUnit,
e.g. `castor test --filter Codec`). A feature isn't done until it has a
test that exercises it the way a caller would: an HTTP request for a
controller, a dispatched command for a handler, a query call for a query.
Not just "it didn't throw".

## Quality tools

Project tasks are defined in `castor.php` and run with the Castor CLI
(install it from https://castor.jolicode.com, it is not a Composer
dependency):

- `castor qa`: everything below. Run it before handing work
  over; it must pass.
- `castor cs`: php-cs-fixer with the `@Symfony` and `@Symfony:risky` rulesets
  plus `declare(strict_types=1)` in every file (`--check` to only report).
- `castor phpstan`: PHPStan level 8 with the Symfony and Doctrine
  extensions (`phpstan.dist.neon`). PHPDoc types are not treated as certain,
  so runtime checks on hand-written configuration values stay meaningful.
- `castor test`: PHPUnit.
