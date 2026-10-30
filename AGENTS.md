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
- Auth: SecurityBundle `form_login` on `/login` (email + password, stateless
  CSRF, remember me), logout on `/logout`; every other route requires
  `ROLE_USER`. Emails are stored lowercase and `UserRepository` loads the
  user. `Security/UserChecker` refuses deactivated accounts. Create
  accounts with `bin/console app:user:create`, which asks everything
  interactively. Invitation of external users by email and agency
  SSO come later.
- Workflow engine: the Symfony Workflow component, with state machines
  built at runtime from the configuration snapshot stored in database, not
  from YAML (`framework.workflows` stays empty). They enforce structure and
  dispatch the workflow events; who may trigger a transition is decided by
  `AccessControl`, never by a workflow guard.

Install missing capabilities with `composer require <package>` and let the
Flex recipe register the bundle and generate its config. Don't hand-edit
`config/bundles.php` or hand-write a bundle's base config. Planned additions: `symfony/scheduler`.

## Vocabulary

Use the English term in code, the translated one in the UI (through translation
keys, see below; the French column is what a French instance shows). Never
mix them.

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

Standard Symfony layout plus a command/query separation carried by two
Messenger buses. No modules, no hexagonal layers, no event sourcing. A
Symfony developer must find their bearings by opening `src/`.

```
src/
  Entity/            single flat folder; persisted enums in Entity/Enum/
  Repository/        one per entity: find by id/key and simple finders only
  Message/           Command/<Domain>/ write commands, Query/<Domain>/ screen
                     reads and their result objects; CommandBus and QueryBus
  MessageHandler/    exact mirror of Message/, one handler per message
  Event/             domain events: IssueCreated, IssueTransitioned, …
  EventListener/     journal recorder, notification dispatcher, …
  Controller/        one folder per zone of the application (Project/, Admin/,
                     Security/, Webhook/), application-level pages at the root
  Form/              form types and their data objects
  Security/          voters, AccessControl, authenticators
  Twig/Components/   PHP-backed Twig components, when a component needs logic
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
  `Message/Command/<Domain>/` (readonly, `#[Assert]` constraints) handled by
  `MessageHandler/Command/<Domain>/` on the dedicated `command.bus`, whose
  middlewares validate the command and wrap the handler in a Doctrine
  transaction (the flush is theirs, handlers only persist). Callers go
  through `Message/CommandBus::dispatch()`, which returns the handler's
  result and rethrows the handler's own exception. Controllers, Live
  components, webhooks and console commands build a command and dispatch
  it. They contain no business rules. Authorization failures are Symfony's
  `AccessDeniedException`; business refusals are a domain exception next to
  the handler (`IssueException`).
- **Forms feed commands.** A form binds to a mutable data object in
  `Form/` (`IssueData`) that carries the input constraints; the controller
  turns a valid submission into the readonly command and dispatches it.
  The command keeps its own constraints as the guard of the bus. A
  submitted, invalid form is rendered with status 422, which Turbo needs.
  Fields driven by the configuration are built in the form type from the
  definitions with `translation_domain: false`: their labels are content.
  When the shape of the form depends on a choice made on the same page
  (the tracker of a new issue), a Live component hosts it
  (`#[AsLiveComponent]` in `src/Twig/Components/`, `ComponentWithFormTrait`)
  and dispatches the command from its `LiveAction`; the controller only
  renders the page. Such a form carries no CSRF token: the live endpoint
  requires a custom header that keeps cross-origin requests out.
- **Reads are queries.** A repository answers "give me this entity" (find
  by id or key, simple finders, bulk updates). As soon as a read depends on
  filters, on visibility or spans several entities, it is a message in
  `Message/Query/<Domain>/` implementing `Query<TResult>` (readonly, the
  filters as public properties, `#[Assert]` constraints) handled by
  `MessageHandler/Query/<Domain>/` on the `query.bus` (validation only, no
  transaction). Callers go through `Message/QueryBus::ask()`, typed by the
  query. The handler holds all of its SQL, query builder included: nothing
  is split into repositories or helper services until a second handler
  needs the same piece. Queries never mutate; commands never return data
  beyond the created identifier.
  A query returns entities. It returns a result object next to it only
  when the screen needs what no entity carries (a merge of several
  tables, values resolved from the configuration), and that object
  carries the entities instead of copying their fields: `IssueDetail`
  holds the `Issue` plus its timeline and fields.
- **URLs carry keys, not ids, and one route per need.** A project page is
  `/MD/SPT/issues`: the workspace key, the project key, then the screen.
  An action receives it as `#[CurrentProject] Project $project`, resolved
  by `Controller/Project/CurrentProjectResolver` from the two route
  parameters (404 when unknown), and the controller class carries
  `#[IsGranted('view', subject: 'project')]`. Archived projects are
  refused by `AccessControl` for every permission, so no controller
  checks it. A list with another scope is
  another route of the same controller (`/issues/assigned`,
  `/issues/created`, `/issues/tracker/{id}`: trackers have no key), never
  a query parameter that switches the view: the route fixes the scope and
  the filters it offers. Filter values are a
  `Form/` object mapped with `#[MapQueryString]`, so the framework does the
  typing and refuses unknown values.
- **Issue lists are one query.** `SearchIssues` serves every list. The
  controller builds it whole: the viewer and the scope (project, workspace,
  assignee, author) from where it stands, the filters from the request,
  never the scope from the URL. It returns a
  `Repository/Pagination` of `Issue` entities with tracker, project,
  workspace and assignee joined, 50 per page. Place labels come from the
  configuration through the `issue_place()` Twig function.
- **Visibility is written twice and tested as one.** `AccessControl::canSee()`
  decides for one issue, `SearchIssuesHandler::restrictToVisible()` applies
  the same rule in SQL. Change both together: `SearchIssuesVisibilityTest`
  compares them for every user of the development dataset.
  The same holds for private comments: `AccessControl::canSeeComment()`
  in PHP, `ShowIssueHandler` in SQL, compared by `ShowIssueVisibilityTest`.
- **Side effects go through events.** A handler dispatches a domain event
  with the EventDispatcher inside the transaction. Whatever must be atomic
  with the write, such as the journal, is written in the handler or by a
  synchronous listener. Whatever may fail independently, such as mail, is
  re-dispatched as an asynchronous Messenger message with
  `DispatchAfterCurrentBusStamp` so it only leaves after commit.
- **One authorization service.** `Security/AccessControl` takes the acting
  user as an explicit argument and resolves the effective role (direct
  membership or workspace membership, the broadest wins; being internal
  opens every project to reading but grants no role). Handlers call it directly. Voters are
  thin adapters over it for `#[IsGranted]` and Twig.
- **Entities carry intent.** Prefer `moveTo()`, `makePrivate()`, `close()`
  over generic setters. Entities never receive services.
- **Only `Config/` and `Workflow/` are self-contained subtrees**, because they
  have no equivalent in a standard Symfony project.
- **No catch-all folders.** No `Service/`, no central `Enum/`, no `Dto/`.
  An enum or DTO lives next to what gives it meaning: `Criticality` and
  `PlaceStatus` in `Entity/Enum/` because they are persisted, `FieldType` and
  `MigrationPolicy` in `Config/`, webhook payloads in `GitLab/`, the
  generic `Pagination<T>` in `Repository/` because repositories and query
  handlers both return it, form data objects in `Form/`. When an enum
  has two owners, persistence wins and `Config/` imports from `Entity/Enum/`.
- **Controllers and templates are grouped by zone.** A zone is a part of
  the application with its own URL prefix and its own shell:

  | Zone        | URL                                    | Shell                       | Controllers            | Templates              |
  |-------------|----------------------------------------|-----------------------------|------------------------|------------------------|
  | application | `/`, `/issues/…`, `/workspaces`, `/MD` | header only                 | `Controller/` root     | `templates/` root      |
  | project     | `/MD/SPT/…`                            | header + project navigation | `Controller/Project/`  | `templates/project/`   |
  | admin       | `/admin/…`                             | header + admin navigation   | `Controller/Admin/`    | `templates/admin/`     |
  | security    | `/login`                               | none                        | `Controller/Security/` | `templates/security/`  |

  Each zone has its base template in `templates/layout/<zone>.html.twig`
  (`project.html.twig`, `app.html.twig`), which renders the shell and
  exposes the `page_title` and `content` blocks; a page extends it and
  fills them. The root of `Controller/` holds application-level pages
  only, never a controller that found no home. Components are not
  grouped by zone but by what they display (`Layout/`, `Issue/`,
  `Project/`): a component serving two zones must not lie about either.

### Frontend

- Tailwind v4, configured in CSS (`assets/styles/app.css`: `@theme`, `@utility`,
  `@source`), built by `symfonycasts/tailwind-bundle` (`tailwind:build`,
  started as a `symfony serve` worker). No `tailwind.config.js`.
- UI elements are **anonymous Twig components** in `templates/components/Ui/`,
  grouped in folders (`Status/`, `Form/`, `Filter/`…), with `{% props %}`,
  and used with the `Ui:` prefix (`<twig:Ui:Button>`, `<twig:Ui:Form:Input>`).
  This is the UI kit, and exactly what Storia catalogues. Components with a
  PHP class (`#[AsTwigComponent]`, `#[AsLiveComponent]` in
  `src/Twig/Components/`) keep their template beside the kit, in
  `templates/components/<Group>/`, and carry no `Ui:` prefix
  (`<twig:Issue:Actions>`, `<twig:Layout:Header>`): the prefix alone tells
  a building block from a component that reads data or acts.
  Classes always go through a CVA recipe (`html_cva`): base classes, one
  variant group per prop (booleans as `true`/`false` keys; in
  `compoundVariants` compare with real booleans, `[true]`), and the root
  element renders
  `class="{{ recipe.apply({…}, attributes.render('class'))|tailwind_merge }}" {{ attributes }}`
  so that a class passed by the caller overrides the component's instead
  of piling up. Inner elements that vary use their own recipe.
  Props are what changes the look; technical HTML attributes of a form
  control (`name`, `value`) are not props but plain attributes forwarded to
  the inner `<input>` with `attributes.only('name', 'value')`, the root
  getting `attributes.without('name', 'value', 'class')`.
  Variables a component sets for itself carry its name: the main recipe is
  `<component>Classes` (`buttonClasses`), an inner element's
  `<component><Element>Classes` (`dropdownMenuClasses`), other values
  `<component><Name>` (`avatarInitials`). The content blocks of a component
  see its variables, so a bare `text` or `item` would shadow the caller's. Keep them small; a PHP
  class comes only when behaviour needs it, under the same name.
- A component earns its place with a choice of classes (variants, tones,
  sizes) or a structure; a single element with fixed classes is not a
  component, write the classes. Components take scalars as props and
  **content as blocks**: compose `<twig:Ui:Avatar>`s inside `<twig:Ui:AvatarStack>`,
  `<twig:Ui:Filter:Option>`s inside `<twig:Ui:Filter:Menu>`, rather than passing a
  list of objects to loop over. A list is acceptable only when the
  component computes something from it (`StackedBar`).
- Every component has its view in `storia/components/<group>/<name>.yaml`
  (a folder is a navigation group: never give a view the same name as a
  folder, so `Ui:Dropdown:Item` lives in `dropdownItem.yaml` next to
  `dropdown.yaml`)
  with 2 to 5 meaningful variants. A variant shows one state or mode and is
  named after it (`default`, `checked`, `with_icon`, `dark`), never after the
  example content and never two axes at once; a secondary axis such as size
  is tried in the Controls tab. YAML `blocks` are printed as raw HTML, so
  a variant that composes other components is a local Twig template next to
  the YAML (`storia/components/filter/menu.html.twig`). That template holds
  one composition and takes every value from `args`; it never branches on
  the variant (no `{% if example == … %}`): what changes between variants
  belongs in the YAML.
  Shared data lives in `storia/data/`. The
  catalogue is at `/storia` (`castor storia` for live reload). Its
  snapshots in `tests/storia/snapshots/` run with the test suite; review a
  diff before `bin/console storia:test --update`.
- Symfony forms render through `templates/form/theme.html.twig`, registered
  globally in `config/packages/twig.yaml`: each block (`form_row`,
  `form_widget_simple`, `textarea_widget`, `choice_widget_collapsed`,
  `choice_widget_expanded`, `checkbox_row`, buttons) delegates to a `Ui:Form:*`
  component, so the look of a field lives in one place. Each rendering has a
  `form:` view in `storia/components/formTypes/` (named after the rendering:
  `choice_radio`, `choice_checkboxes`), which Storia renders as `default`,
  `disabled` and `error`. Types the framework lacks live in `src/Form/Type/`
  with their own block prefix (`ToggleType` → `toggle_row`, `toggle_widget`).
- Form controls style their disabled state: `disabled:` utilities on the
  field itself, `has-[:disabled]:` on the label that wraps a checkbox or a
  radio.
- The shell is `Ui:Layout:Shell` (blocks `header`, `sidebar`, `breadcrumb`,
  content) with `Ui:Layout:Sidebar`, `Ui:Layout:Sidebar:Section` and
  `Ui:Layout:Sidebar:Item`: low-level pieces without data. `Layout:Header`
  and `Layout:ProjectNav`, which display data, are PHP-backed components
  (`#[AsTwigComponent]` in `src/Twig/Components/Layout/`). Inside a block
  passed to a nested component, `this` is that nested component: read your
  own `this.*` into a variable before opening it.
- Storia holds components only. Pages are not mocked in `storia/pages/`:
  they are built directly as real templates with their controllers.
- Icons: `<twig:ux:icon name="lucide:x" />` or `ux_icon('lucide:' ~ name)`,
  imported locally into `assets/icons/lucide/` with `ux:icons:import`.
- **Components of one page keep each other up to date through a domain
  event.** An action that changed the data dispatches its command, redraws
  in place and emits it (`issue:changed`); every component of the page
  listens with a `#[LiveListener]` that just redraws. No redirect from a
  live action when other components hold state worth keeping (a comment
  being written).
- **Toasts have one host.** `Ui:Toaster`, placed once in `base.html.twig`,
  shows the flash messages the page starts with and adds a toast for every
  `toast:show` browser event; a live component sends one with
  `dispatchBrowserEvent('toast:show', ['message' => …])`.
- JavaScript is Stimulus only, one small controller per behaviour in
  `assets/controllers/` (`dropdown`, `sidebar`, `drawer`,
  `toast`, `toaster`, `autosubmit`, `autofocus`); write a new one when a component needs it, not before. No
  inline scripts.

## Configuration and snapshot

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
    No hardcoded labels in Twig or PHP. English is the default language and
    French is shipped too; `CAIRN_LOCALE` picks one per instance.
    `debug:translation fr` lists missing and unused keys.
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
- `castor fixtures` resets the dev database and loads `src/Story/AppStory`
  (Foundry): workspaces, projects, trackers, users and issues close to the
  mockups, created through the real commands under a mocked clock. It
  applies `config/instance.example.php`, whose workflows it is written for.
  Every user's password is `cairn`; `admin@cairn.test` is an administrator.
  Factories live in `src/Factory/` and are also meant for tests.
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
controller, a command or query dispatched on its bus for a handler.
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
