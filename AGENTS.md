# AGENTS.md

Cairn is an issue tracker for a web agency and its clients: a single
instance holds every client (a workspace each, several projects per
workspace), several issue categories per project, each with its own
workflow, and its internal users and those of the client work on the same
issues, each seeing what their role allows. This file is the reference for
anyone, human or agent, who changes the code: the decisions taken and the
rules that keep them.

## Stack and decisions

Symfony 8.1 on PHP 8.4, installed from `webapp-pack`. Check `composer.json`
for the exact versions and `symfony.lock` for which recipes ran. Already in
place: Doctrine ORM + migrations, Messenger (Doctrine transport), Form,
Validator, Serializer, Security, Twig, Mailer, Notifier, Translation,
Stimulus, Turbo, Scheduler, Reprise (`symfony/reprise`) for the assets,
API Platform (`api-platform/symfony`, without its Doctrine bridge) for the
REST API, and Mercure (`symfony/mercure-bundle`) for the optional real time.

Decisions already taken:

- Persistence: Doctrine ORM, schema compatible with both MariaDB and
  PostgreSQL. What is deleted is deleted physically (no soft delete, no
  bin), and three rules say what may be:
  - **What is the current state or configuration, not history, goes
    freely**, by whoever manages it: a removed attachment (file and row,
    the journal keeps its name), a deleted comment (row and files, the
    journal keeps its text, author and date), a revoked membership, a
    removed link between issues (the journal of both keeps it), an issue
    subscription a person ends, a code repository a manager unlinks (the
    code references it produced stay), a password token once used,
    replaced or long expired, the view someone keeps of an issue list
    (`ListView`), a notification once its mail left
    (`PendingNotification`), the picture of an account once replaced, an
    automation rule (`AutomationRule`: what it did stays in the journal
    and in its executions), a release (`DeleteRelease`: its issues
    are left without one, which their journal says), a line of a
    budget (`BudgetLine`: written by hand, corrected or deleted by
    whoever manages the budgets), and a project template with its
    trackers and its rules (`DeleteTemplate`: the projects made from it
    keep what they were given).
  - **What holds history is archived, and deleted only while empty.** A
    tracker is archived (`UpdateTracker::$archived`: nothing new goes in
    it, its issues stay seen and changed as before, by clients too) and
    deleted only without any issue (`DeleteTracker`); an account is
    deactivated, and deleted only when it left no trace (`DeleteUser`,
    `UserRepository::hasHistory()`). Anonymising an account is not done.
    What the configuration holds follows the same idea: a role, a
    workflow, a step, a field or a link type is deleted only once
    nothing uses it (see [Configuration](#configuration)).
  - **History goes only on purpose, with its container.** An issue is
    erased with all that hangs from it under `issue.delete`
    (`DeleteIssue`); a project or a workspace, by an administrator and
    only once archived (`DeleteProject`, `DeleteWorkspace`), with
    everything they hold; a budget, by whoever manages the budgets and
    only once closed (`DeleteBudget`), with its lines. `Repository/IssueEraser` is the one place that
    knows what hangs from an issue and in which order it goes, in a few
    bulk statements whatever the number (measured: two seconds for
    20,000 issues and their 200,000 lines, so no worker and no "being
    deleted" state); `MessageHandler/Command/Project/ProjectRemoval` adds
    what belongs to a project. Bulk statements run no Doctrine listener,
    so the files go afterwards: `RemoveFiles`, dispatched with
    `DispatchAfterCurrentBusStamp`, runs once the transaction committed
    and is dropped with it; a file it could not remove is taken by
    `AttachmentPruner` the next day. A link to an erased issue goes
    without a word in the history of the other one, a reference to it in
    a text elsewhere reads as plain text, and the key of a deleted
    project is free again. Deleting notifies nobody.
- Interface: server-rendered Twig + Turbo + UX Twig/Live Components, Tailwind
  for styling, no JavaScript framework: an issue list is a GET form and
  a Turbo frame, the kanban is Live components with SortableJS in a
  Stimulus controller, the rich-text editor is TipTap driven by a
  Stimulus controller.
- Auth: SecurityBundle `form_login` on `/login` (email + password, stateless
  CSRF, remember me), logout on `/logout`; every other route requires
  `ROLE_USER`, except `/password/…`. Emails are stored lowercase and
  `UserRepository` loads the user. `Security/UserChecker` refuses
  deactivated accounts and `User::isEqualTo()` ends their open sessions;
  `login_throttling` blocks an email or an address after five failures.
  Nobody types a password for someone else: an account is created without
  one (`CreateUser`) and `InviteUser` mails a link, valid a week, to the
  page that sets it (`Controller/Security/PasswordController`); it can be
  sent again while the account has no password, each link replacing the
  previous one. An account without a password cannot sign in (Symfony
  refuses a null password). "Forgot password?" on the login page
  (`RequestPasswordReset`, link valid an hour, a lost invitation
  included) answers the same page whatever the address: unknown,
  deactivated or asked again within five minutes, nothing is sent. `ResetPassword` consumes
  the token, with every other link of the account, and sets the password,
  which ends the open sessions. Signed in, someone changes their
  password on the Security page of their account (`ChangePassword`, see
  [Account](#account)), confirmed by the current one. The
  single-use tokens are `symfonycasts/reset-password-bundle`'s
  (`Entity/PasswordToken`, hashed verifier), behind `Security/PasswordTokens`,
  the only class that talks to it; the mails are `Notification/AccountMailer`.
  `bin/console app:user:create` (interactive) creates the first
  administrator with a password, before any mailer exists. An SSO for
  internal users comes later.
- API: a REST API under `/api` for internal users, served by
  API Platform over the same commands and queries as the pages, signed in
  with personal tokens (see [API](#api)), and an MCP server on `/mcp`, on
  the same tokens and the same resources, for their assistants.
- Configuration: the roles, their permissions, the steps, the workflows,
  the fields and the link types of an instance are rows of its database,
  managed by its administrators under `/admin/configuration` (see
  [Configuration](#configuration)). No file, no sync: a new instance
  installs a starter configuration once, and a later version changes the
  tables with Doctrine migrations.
- Workflow engine: the Symfony Workflow component, with state machines
  built at runtime from the configuration stored in database, not
  from YAML (`framework.workflows` stays empty). They enforce structure and
  dispatch the workflow events; who may trigger a transition is decided by
  `AccessControl`, never by a workflow guard.

Install missing capabilities with `composer require <package>` and let the
Flex recipe register the bundle and generate its config. Don't hand-edit
`config/bundles.php` or hand-write a bundle's base config.

## Vocabulary

Use the English term in code, the translated one in the UI, through
translation keys (see [Conventions](#conventions)). Never mix them. The UI
columns are what the English and the French catalogues show.

| Code             | UI (en)           | UI (fr)          | Meaning                                                          |
|------------------|-------------------|------------------|------------------------------------------------------------------|
| `Issue`          | Issue             | Demande          | The tracked object. Key `WORKSPACE-PROJECT-123`.                 |
| `Tracker`        | Category          | Catégorie        | Type of an issue within a project, bound to one workflow. In DB. |
| `Workflow`       | Workflow          | Workflow         | Places + transitions + the fields asked. Managed in the administration. |
| `Place`          | Step              | Étape            | Where an issue stands (Symfony Workflow term). Declared once for the instance, used by the workflows that name it. |
| `Transition`     | Action            | Action           | Named button that moves an issue to another place.               |
| `PlaceStatus`    | (internal)        | (interne)        | Family of a place: Open, InProgress, Waiting, Closed. PHP enum. Never shown to users, who see places: it orders lists, hides finished issues, feeds statistics. Only the configuration pages of the administration show it. |
| `Criticality`    | Criticality       | Criticité        | Low, Normal, High, Blocking. First-class column, not a field.    |
| `Field`          | Field             | Champ            | Global attribute, enabled per tracker.                           |
| `Link`           | Link              | Lien             | Related, Duplicates/DuplicatedBy, Blocks/BlockedBy.              |
| `Workspace`      | Workspace         | Espace           | Group of projects sharing memberships.                           |
| `Project`        | Project           | Projet           |                                                                  |
| `Role`           | Role              | Rôle             | Managed in the administration, with its permissions.             |
| `Membership`     | Access            | Accès            | Role of a user on a project or a workspace. No organisation entity: a client is a workspace. |
| `internal` flag  | Internal/External | Interne/Externe  | Internal user (who runs the instance) vs external user (a client). |
| `company`        | Company           | Société          | Free text on a user, suggested from the values already typed.   |
| `private`        | Private           | Privé            | Hidden from external users.                                      |
| `Release`        | Release           | Version          | Planned delivery, optional per project.                          |
| `JournalEntry`   | History           | Historique       | Immutable journal, keeps the labels of the time.                 |
| `Attachment`     | File              | Fichier          |                                                                  |
| `ConfigChange`   | History (configuration) | Historique (configuration) | A change made to the configuration: when, by whom, a sentence. |
| `Import`         | Import            | Import           | An export archive of another tracker brought into a project, once. |
| `AutomationRule` | Rule (Automations) | Règle (Automatisations) | A rule of a project that acts on its issues by itself: a trigger, conditions, actions. |
| `AutomationRun`  | Execution         | Exécution        | What a rule did on an issue, once: applied, skipped or stopped.  |
| `referent`       | Referent          | Référent         | The member a role answers through on a project; designated by hand, on an access. |
| `ProjectTemplate` | Template         | Modèle           | What a new project may start with: trackers (`TemplateTracker`) and automation rules (`TemplateRule`), copied when it is created. |
| `Budget`         | Budget            | Budget           | An amount of time a client committed to on a project (an open order), optional per project. |
| `BudgetLine`     | Line              | Ligne            | What a budget is debited of, written by hand: a quantity, a status, an issue or a label. |

## Architecture

Standard Symfony layout plus a command/query separation carried by two
Messenger buses. No modules, no hexagonal layers, no event sourcing. A
Symfony developer must find their bearings by opening `src/`.

```
src/
  Entity/            single flat folder; persisted enums in Entity/Enum/
  Repository/        one per entity: find by id/key and simple finders, never
                     the viewer; plus the DQL pieces reads share (IssueOrder,
                     SearchText, Pagination, EntityList)
  Message/           Command/<Domain>/ write commands, Query/<Domain>/ screen
                     reads and their result objects; CommandBus and QueryBus
  MessageHandler/    exact mirror of Message/, one handler per message
  Event/             domain events, one per command that changes an issue:
                     IssueCreated, IssueTransitioned, CommentAdded, …
  EventListener/     Doctrine entity listeners (AccessControlResetter,
                     AttachmentFileRemover)
  Controller/        one folder per zone of the application (Project/, Admin/,
                     Security/), application-level pages at the root; the
                     webhook endpoint is the Webhook component's controller
  Form/              one folder per domain, as in Message/: form types,
                     their data objects and what reads a query string
                     (IssueListFilters); reusable field types in Type/
  Security/          voters, AccessControl and its SQL twin Visibility,
                     authenticators, PasswordTokens (invitation and reset links)
  Twig/Components/   PHP-backed Twig components, when a component needs logic
  Config/            the configuration as the application reads it: Definition/
                     (immutable), DefinitionLoader, ConfigRegistry,
                     ConfigValidator, StarterKit; config-only enums
  Workflow/          state machines built from the configuration (WorkflowFactory,
                     WorkflowProvider), HasPlace for the objects they move
  Notification/      who follows an issue (SubscriptionRules), which events
                     notify (NotificationDispatcher), what each may read of a
                     change (ChangeFilter), when the grouped mails leave
                     (ProjectMailSender), the mails (IssueMailer,
                     AccountMailer)
  Realtime/          the signal that an issue changed, published to a Mercure
                     hub when there is one (IssueBroadcaster, ProjectTopics)
  Api/               the REST API, self-contained: Resource/ the classes API
                     Platform exposes and their inputs, State/ the providers
                     and processors that ask the buses, Mapper/ what turns
                     entities into resources, Mcp/ the tools of the MCP
                     server (see API)
  Forge/             the forges (GitLab, GitHub later): ForgeApi and its
                     locator, IssueKeyParser, the neutral remote events in
                     Event/, CodeReferenceConsumer; one folder per forge
                     (GitLab/: request parser, API client)
  Import/            the imports of an export archive: ImportArchive and
                     ImportManifest (the format), ImportAnalyser,
                     ImportMapping, ImportRunner, ImportIssueBuilder, the
                     ImportsDirectory
  Automation/        the automation rules: Definition/ what a rule may say,
                     without the database (TriggerType, ConditionType,
                     ActionType and their Parameters, Target, RuleDefinition,
                     RuleChecker in a RuleContext); at the root what reads a
                     project and acts (RuleEngine, RuleConditions,
                     RuleExecutor, Targets, TimeRuleRunner, RuleContexts,
                     RuleSentence)
  Budget/            the one place that converts the minutes budgets are
                     stored in (Quantities)
  Markdown/          rendering of the text users write, references resolved
  Attachment/        where attachment files live (AttachmentStorage)
  Command/           console commands (app:config:install, app:automations:run, …)
```

### Code organisation

- **Entities carry intent.** Prefer `moveTo()`, `makePrivate()`, `close()`
  over generic setters. Entities never receive services.
- **Only `Config/`, `Workflow/` and `Api/` are self-contained subtrees**:
  the first two have no equivalent in a standard Symfony project, the
  third is another door on the same application and keeps to itself what
  only it needs.
- **No catch-all folders.** No `Service/`, no central `Enum/`, no `Dto/`.
  An enum or DTO lives next to what gives it meaning: `Criticality` and
  `PlaceStatus` in `Entity/Enum/` because they are persisted, `FieldType` and
  `TransitionStyle` in `Config/`, forge payloads in `Forge/Event/`, the
  generic `Pagination<T>` in `Repository/` because repositories and query
  handlers both return it, form data objects next to their form type in
  `Form/<Domain>/`. When an enum
  has two owners, persistence wins and `Config/` imports from `Entity/Enum/`.
- **Controllers and templates are grouped by zone.** A zone is a part of
  the application with its own URL prefix and its own shell:

  | Zone        | URL                                    | Shell                       | Controllers            | Templates              |
  |-------------|----------------------------------------|-----------------------------|------------------------|------------------------|
  | application | `/`, `/issues/…`, `/projects`          | header only                 | `Controller/` root     | `templates/` root      |
  | account     | `/account/…`                           | header + account navigation (`Layout:AccountNav`) | `Controller/` root | `templates/account/` |
  | project     | `/MD/SPT/…`                            | header + project navigation | `Controller/Project/`  | `templates/project/`   |
  | admin       | `/admin/…`                             | amber header + admin navigation (`Layout:AdminNav`) | `Controller/Admin/`    | `templates/admin/`     |
  | security    | `/login`                               | none                        | `Controller/Security/` | `templates/security/`  |
  | api         | `/api/…`                               | none: JSON                  | none: `Api/`           | none                   |

  Each zone has its base template in `templates/layout/<zone>.html.twig`
  (`project.html.twig`, `app.html.twig`, `account.html.twig`, `admin.html.twig`), which renders the shell and
  exposes the `page_title` and `content` blocks; a page extends it and
  fills them. The root of `Controller/` holds application-level pages
  only, never a controller that found no home. Components are not
  grouped by zone but by what they display (`Layout/`, `Issue/` for the
  page of one issue, `IssueList/`, `Board/`): a component serving two
  zones must not lie about either.
- **URLs carry keys, not ids, and one route per need.** A project page is
  `/MD/SPT/issues`: the workspace key, the project key, then the screen.
  An action receives it as `#[CurrentProject] Project $project`, resolved
  by `Controller/Project/CurrentProjectResolver` from the two route
  parameters (404 when unknown), and the controller class carries
  `#[IsGranted('view', subject: 'project')]`. Archived projects, and the
  projects of an archived workspace (`Project::isReadOnly()`), are
  refused by `AccessControl` for every permission and every transition,
  so no controller checks it; the project layout shows a banner saying so. A list with another scope is
  another route of the same controller (`/issues/assigned`,
  `/issues/created`, `/issues/tracker/{id}`: trackers have no key), never
  a query parameter that switches the scope: the route fixes the scope and
  the filters it offers, the query string only arranges what it shows
  (filters, sort, grouping, list or kanban).

### Writes and reads

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
  the handler (`IssueException`). The refusals the administration shows
  as they are (`WorkspaceException`, `ProjectException`,
  `MembershipException`, `UserException`), and those of the releases
  and links pages (`ReleaseException`, `IssueLinkException`), implement
  `TranslatableInterface`: the message is for the logs, `trans()` gives
  the reason to the user, in a form error or a live component's `error`.
- **One message to create a resource, one to change it.** `CreateX`
  creates, `UpdateX` changes, PATCH style: a property left null keeps
  its value (`UpdateIssue`, `UpdateUser`, `UpdateWorkspace`,
  `UpdateProject`; `UpdateIssue::NOBODY` unassigns). The handler checks
  every change against its own rule before applying any, so one refusal
  refuses them all, then journals each change. A screen sends the
  properties it edits, an API will expose one PATCH per resource; states
  such as archived or active are properties, not actions. A message of
  its own is kept for what is not a change of state: a business action
  (`ApplyTransition`, `MoveIssue`, `ChangeTracker`), a sub-resource (comments, files,
  `GrantMembership` / `RevokeMembership`). Don't split an `UpdateX` per
  property or per form: a page edits a resource with one form.
- **Forms feed commands.** A form binds to a mutable data object in
  `Form/<Domain>/` (`Form/Issue/IssueData`) that carries the input constraints; the controller
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
- **A read has three homes, by what it depends on.** A repository
  answers "give me this entity": find by id or key, the simple finders of
  one entity, the counts and bulk updates the configuration commands need.
  It never takes the viewer, nor a flag that stands for them
  (`includePrivate`): what it returns is the same whoever asks, so a
  handler, a component, a controller or a console command may call it.
  As soon as a read depends on who is looking (visibility, privacy,
  rights), on filters typed by the user, or merges several entities, it
  is a message in `Message/Query/<Domain>/` implementing `Query<TResult>`
  (readonly, the filters as public properties, `#[Assert]` constraints)
  handled by `MessageHandler/Query/<Domain>/` on the `query.bus`
  (validation only, no transaction), and the handler is the only place
  that applies `Security/Visibility`. Callers go through
  `Message/QueryBus::ask()`, typed by the query; a component that needs a
  read depending on the viewer asks the bus (`ListTrackers`), never a
  repository with a flag. The handler holds all of its SQL, query builder
  included: nothing is split into repositories or helper services until
  a second handler needs the same piece. Those shared pieces are the
  third home: `Repository/` also holds the DQL that several reads share,
  `IssueOrder`, `SearchText`, `Pagination`, and `EntityList::of()`, which
  turns the result of an object-hydration query into a typed list.
  Queries never mutate; commands never return data beyond the created
  identifier.
  **A query returns entities, not read models.** It returns a result
  object next to it only when the screen needs what no entity carries (a
  merge of several tables, counts, values resolved from the
  configuration), and that object carries the entities instead of
  copying their fields: `IssueDetail` holds the `Issue` plus its timeline
  and attachments, `ProjectSummary` the `Project` plus its counts. This
  is a decision, taken at the end of V1: the application is one
  server-rendered instance on one database, without an API, its
  entities hold no collection, and every handler joins what the screen
  reads, so a second model of the same tables would change with every
  screen and would break the pairing of `AccessControl` with its SQL
  twin, which reason on the same objects. The REST API did not change
  it: its handlers are the same, and what it shows is a class per
  resource in `Api/Resource/`, filled from the entities the queries
  return, so that the contract given to programs does not move with the
  entities. Revisit the decision only for a dashboard heavy in
  aggregates or a separate read store. What
  only the configuration resolves (the labels of an issue's field
  values, `InstanceDefinition::fieldValues()`) is not a query: components
  call it.
- **The handler records a change; side effects listen to it.** A command
  that changes an issue ends with one call to
  `IssueHandlerSupport::record()`, given the domain event of `Event/`
  (`IssueCreated`, `IssueUpdated`, `IssueTransitioned`, `IssueMoved`,
  `CommentAdded`, `CommentEdited`, `CommentDeleted`, `AttachmentAdded`,
  `AttachmentRemoved`, `IssuesLinked`, `IssuesUnlinked`) carrying the
  issue, the actor and the journal entries it wrote: `record()` persists
  the entries, touches the issue and dispatches the event with the
  EventDispatcher, inside the transaction, so a listener may still write
  (`SubscriptionRules`). One event per command and per issue: linking
  dispatches one for each end; `RankIssue`, which arranges the kanban,
  records nothing, and `DeleteIssue`, which leaves no journal to write
  in, dispatches `IssueDeleted` itself, for the real time only. Nothing has an id when the event fires;
  `Notification/NotificationDispatcher` keeps the events it listens to
  until Doctrine's `postFlush`, then dispatches `NotifyIssueChange` (ids
  only) on the default bus with `DispatchAfterCurrentBusStamp`: the
  message leaves after the command's transaction committed, and is
  dropped with it when the command fails. If that deferred dispatch
  itself fails, `CommandBus` logs it and still returns the handler's
  result, since the change is committed. One command is the exception:
  `ImportIssue` writes an imported issue without `record()`, which would
  date it of today and notify; it dispatches no event, writes its
  journal line itself and subscribes through `SubscriptionRules::subscribe()`.
  An event has an actor, or the name of the automation rule that acted
  (`IssueEvent::$rule`, see [Automations](#automations)), or neither when
  a forge did: `record()` counts as someone acting (`Issue::$lastActivityAt`)
  whatever is not a rule, and `updatedAt` moves with everything.

### Authorization and visibility

- **One authorization service.** `Security/AccessControl` takes the acting
  user as an explicit argument and resolves the effective role (direct
  membership or workspace membership, the broadest wins; being internal
  opens every project to reading but grants no role). Handlers call it directly. Voters are
  thin adapters over it for `#[IsGranted]` and Twig.
- **Visibility is written twice and tested as one.** `AccessControl::canSee()`
  decides for one issue, `Security/Visibility::restrictIssues()` applies
  the same rule in SQL; `canView()` and `restrictProjects()` do the same
  for projects, and `restrictTrackers()` hides the private trackers from
  external users, for `ListTrackers` and the issue lists alike. The two
  classes live side by side in `Security/` because they are one rule.
  Every handler that lists issues, trackers or projects goes through
  `Visibility`. Change both sides together: `SearchIssuesVisibilityTest`
  and `ListVisibleProjectsTest` compare them for every user of the
  development dataset.
  The same holds for private comments and their files:
  `AccessControl::canSeeComment()` and `canSeeAttachment()` in PHP,
  `ShowIssueHandler` in SQL, compared by `ShowIssueVisibilityTest`, and
  for the links of an issue: `ListLinks` keeps those whose other end
  the viewer sees, through `Visibility`, compared with `canSee()` by the
  same test.
- **Who edits what: the author, or the right on others.** The content of
  an issue (title, description, fields, files on the issue) is its
  author's as long as their role still lets them create issues there;
  `issue.edit` grants it on the issues written by others. The body and
  files of a comment are its author's as long as they may still comment;
  `comment.edit` grants it on the comments of others, and deleting is the
  same right. One method per object decides, `AccessControl::canEditIssue()`
  and `canEditComment()`: handlers call it, components show the controls
  only when it says yes, and the config example gives both rights to
  project managers. A rewritten description or comment, and a deleted
  comment, are journaled with the text before and after
  (`changed` with `from`/`to`, `comment_edited`, `comment_deleted`), shown
  in the thread behind a disclosure. The `journal_label` Twig filter
  (`Twig/JournalLabelExtension`) turns an entry into its sentence, for
  the thread (`Issue:JournalEntry`) and the mails alike: the only place
  that reads what each type recorded.
- **Deleting an issue is a right, not the author's.** `issue.delete`
  (project managers and developers in the example) erases an issue for
  what should never have been written: a test, a mistake, a secret pasted
  in a description. The bin of `Issue:Header` asks in a dialog, saying how
  many comments and files go, then leads to the list of the project. A
  client never deletes, even their own issue; they ask.

### The issue page

- **The workflow is the contract with the client; internal users may
  leave it, and one rule says how.** `AccessControl::movesFor()` lists where a
  user may take an issue from its place, in the order of the workflow:
  one `PlaceMove` per action of their role that leads to a place and,
  for a place no action of theirs leads to, a move outside the workflow
  when they hold `issue.force_place` (internal-only). The step menu and
  the kanban read it to show what is offered; `MoveIssue` ("take the
  issue to this place") is the one place that applies it: its handler
  takes the action of the actor that leads there when there is one
  (`IssueHandlerSupport::transition()`, the one path a transition
  takes, shared with `ApplyTransition`: journaled as the action, the
  first when several lead there) and writes the place without the state
  machine only otherwise (journaled `moved`). So the step menu, the
  kanban and the selection page leave the same trace, and a caller
  dispatches `ApplyTransition` only when it names the action or sends a
  comment with it. On the issue page `Issue:Activity` ends the thread with two
  boxes. The decision lists the transitions the viewer may apply from
  the issue's place (`AccessControl::transitionsFor()`), internal or
  not, one line each: a button in the `style` the configuration gives
  the transition and its `description`. An action applies at once,
  without a comment; when its `requiresComment` binds the viewer, the
  decision turns into the `commentPrompt`, an editor and the button, and
  the comment is sent with the action. `requiresComment` binds external
  users only (`AccessControl::commentRequired()`, the one place that
  says so): an internal user applies any transition without a word. The
  comment box below is a comment and nothing else; whoever wants both
  does both. The actions of others are never shown, nor the place an
  action leads to: clients act because they are asked to choose, not
  for the step. There is no "whose turn" indicator: the action offered
  says it. The header
  shows the path the issue took, as `JournalEntryRepository::findPlaceChanges()`
  reads it from the journal with the labels of the time, private entries
  included: a transition made with a private comment hides its comment
  and its journal line from external users, never the place it led to,
  which they read in the properties. In the side panel the step is the
  first row of `Issue:Properties`: read by external users, a menu for
  internal users, listing the places of the workflow in its order, the
  current one checked, a place an action leads to named after the
  action (one row per action when several lead there), the others
  greyed. Its last row, for whoever holds `issue.change_tracker`
  (internal-only too), leads
  to the other trackers of the project and then to the places of the
  chosen one (the `panels` controller, everything in the page), which
  dispatches `ChangeTracker` and journals `tracker_changed`: no action
  leads from one workflow to another. No reason is
  asked: the journal line is the trace. The selection page offers the
  same move: a place every issue has, each staying in its tracker, or
  a place of a tracker valid for every issue
  (`SelectionActions::$places` and `$moveTrackers`; per issue,
  `MoveIssue` when no tracker was chosen or it is already in it,
  `ChangeTracker` otherwise).
- **Links read from each side.** An `IssueLink` goes from a source to a
  target with a link type of the configuration: the source reads its
  label (Blocks), the target its inverse (Blocked by); a symmetric type
  exists once whatever the direction. `AddLink` and `RemoveLink` need
  `issue.link`; linking across projects is for internal users, and the
  journal entry of a link (`linked`, `unlinked`, on both issues, with
  the other's key and title of the time) is private when the other issue
  is private or of another project. `Issue:Links` offers the inverse
  labels too and then links the other way. A link type that links still
  use cannot be deleted (`DeleteLinkType`).
- **Releases are optional, without a setting.** A `Release` belongs to
  one project, its name unique there; `ReleaseStatus` (open, delivered,
  abandoned, in `Entity/Enum/`) is a property of `UpdateRelease`, and
  only an open release takes issues, those of a delivered or abandoned
  one staying in it. An issue is in one release at most, set by
  `UpdateIssue::$releaseId` (`issue.set_release`) and journaled with the
  names of the time. A project without any release shows none of it:
  no Release property on the issue, no filter nor grouping in its lists,
  and the Releases page (`/MD/SPT/releases`, `Project/ReleaseController`)
  is in its navigation only for whoever may create the first
  (`release.manage`). A release is created (`/releases/new`) and changed
  (`/releases/{id}`, where an open one is also abandoned, and any one
  deleted: `DeleteRelease` takes it out of each issue it held through
  `record()`, as if the actor had done so by hand) in a dialog
  over the list. Clients read the releases, their progress counted
  over the issues they see (`ListReleases`, through `Visibility`).

### Issue lists, kanban and bulk actions

- **An issue list is an address: a route for its scope, a query string
  for its arrangement.** `SearchIssues` serves every list. The route
  fixes a `ListScope` (`Twig/Components/IssueList/`: project, tracker,
  assignee, author, the filters offered as `dimensions`, the bare path
  of the list) that the browser cannot change; `Form/Issue/IssueListFilters`
  reads the query string (filter values, `sort`, `order`, `group`,
  `page`, `view`) and keeps what it understands: a value the list does
  not know is ignored, never an error. `IssueList/ListChoices` gives
  what the list offers its viewer (places, trackers, members, releases,
  groupings) and is the one place that builds the `SearchIssues`, for
  the table and for the columns of the kanban alike. The controllers
  (`Project/IssueListController`, `MyIssuesController`) only build the
  scope; `Controller/ShowsIssueList` answers for all of them.
  The page is drawn once. `IssueList:Filters` is a GET form outside the
  `issues` Turbo frame (`templates/issue/_results.html.twig`): the
  `filters` controller submits it to the frame on every change, so only
  the results are asked for (a request carrying `Turbo-Frame: issues`
  is answered with the frame alone), the address follows
  (`data-turbo-action="advance"`, so the back button walks the filters)
  and the menus stay as the user left them; since the form is not
  redrawn, the controller keeps their counts and the "clear" button up
  to date. Inside the frame the headers that sort and the pager are
  links to the same list arranged otherwise (`ListScope::url()`), every
  other link leaves it (`target="_top"`), and hidden fields repeat the
  sort to the form. What only the screen knows never goes to the
  server: rows are checked by the `selection` controller, groups folded
  by `fold` (kept in `sessionStorage` per list and grouping). A filter,
  a sort or a grouping goes back to page 1.
  Users filter and group by place, never by status. Places are those
  of the instance, named by their id alone: a list mixing workflows
  offers each place its workflows use once
  (`InstanceDefinition::placesIn()`), and the issues of two trackers
  standing on "In progress" are one group. Filters, groups and the sort
  by place follow the order of the steps of the instance; only a kanban and the
  step menu follow the order of a workflow. The SQL
  order is `Repository/IssueOrder`: the group first, then the sorted
  column (`IssueSort`), then the default order (status, criticality,
  latest activity). A grouped list (`IssueGrouping`: place, criticality,
  assignee, project across projects, release in a project that has
  some) gets the total of each group over
  every page (`Pagination::$groupTotals`); `IssueList:Table` cuts its
  page into groups (`IssueGroup::split()`). The key of a group is written in
  SQL (`IssueOrder::groupKey()`) and in PHP (`IssueGrouping::keyOf()`),
  compared for every grouping by `SearchIssuesGroupingTest`. The query
  returns a `Repository/Pagination` of `Issue` entities with tracker,
  project, workspace and assignee joined, 50 per page. Place labels come
  from the configuration through the `issue_place()` Twig function. The
  home page keeps its own compact panels, outside these components.
- **The address alone means "my view of this list".** A list remembers
  how each person last arranged it (`ListView`: the user, the bare path
  of the list, the query string, without the page nor the words
  searched). After a change made on the page, the `list-view`
  controller tells the list, with a PUT at its own address
  (`RememberListView`); a GET never writes. A page request for the bare
  address is redirected to the remembered one, so the links of the
  navigation stay bare and the address shown is always the arrangement
  shown; an address with parameters shows as it says and remembers
  nothing, so opening one someone shared leaves one's own view alone,
  and a frame request is never redirected (clearing every filter shows
  the list as it comes, and forgets the view). The navigation of a
  project counts what each list holds as it comes (`CountProjectIssues`:
  the open issues the viewer sees, in one query), never as the view
  remembered arranges it.
- **The kanban is a view of a tracker's list, and loads column by
  column.** The list of a tracker shows as a table or as a kanban
  (`view=board`, two radios of the filter form, a `Ui:Filter:Segments` rail at the right of the title), never
  across trackers since a kanban has one workflow; both read the same
  filters, the place filter, the sort and the grouping applying to the
  table only. `Board:Columns` draws one `Board:Column` per place with
  `loading="lazy"`: it loads when it shows, asks
  `ListChoices::searchColumn()` for its place only and grows by batches
  (`perPage`, the `load-more` controller); a closed place starts
  folded. There is no pagination of the whole board, and nothing loads
  it whole. Each card lists the places the viewer may move it to and,
  among them, those outside the workflow (`AccessControl::movesFor()`),
  so the `board` controller refuses the others while dragging, without
  asking the server, and a column reached outside the workflow is
  dashed. `Board:Move` is the only component that acts: it
  finds the move again, dispatches `MoveIssue` when there is nothing to
  ask, otherwise asks in a `Ui:Dialog` (a required comment, several
  actions to the same place) and applies the transition chosen, and
  sends the browser
  `board:moved` once the move is settled. The move is optimistic: the
  card stays where it was dropped; applied, the event brings the places
  it may now go to and the controller updates the card and the counts
  of the two columns without reading them again; refused or cancelled,
  the controller puts the card back.
- **A column is in the order people give it.** `Issue::$boardRank` is
  the place of an issue in its column, the highest on top, compared
  within one tracker and one place only; the board reads it as a sort
  like another (`IssueSort::Rank`, descending), that no table offers
  (`ListScope::sort()`) and the API refuses: criticality does not order
  a column, and the lists know nothing of the rank. An issue that enters
  a place arrives on top, a step (`Issue::RANK_STEP`) over the highest
  rank there: `IssueHandlerSupport::arrive()`, called wherever a place is
  taken (creation, `transition()`, forced place, change of tracker).
  Whoever holds `issue.rank` (the internal roles in the example; not
  internal-only, a client role may order its backlog) drags a card within
  its column, closed places included, or drops it between two cards of
  another: the `board` controller sends the two cards it stands between,
  and `RankIssue` gives it the rank halfway between the one above (else
  the one below) and what follows it in the whole column, so a filtered
  column, hidden private issues or a column loaded by batches give one
  answer. When no rank fits, or the neighbour shares its rank,
  `IssueRepository::spreadRanks()` ranks the column anew once; so does a
  column that took the issues of a step removed from its workflow
  (`RemoveWorkflowPlace`, `moveFromPlace()`), since two columns count their ranks alike. `Board:Move` ranks after the move, two ordinary
  commands; a card moved by someone who may not rank goes on top (`top`
  in `board:moved`). Ranking is the one issue command that does not call
  `record()`: no journal entry, no event, `updatedAt` untouched, since
  arranging the board is not an activity on the issue. Accepted: two
  people ranking at once, the last one wins and the other sees it when
  the column loads again.
- **Bulk actions go through a selection page.** Rows of `IssueList:Table`
  are checked page by page; the selection bar is a GET form that sends
  the selection page the ids and the filtered list to come back to
  (`from`, kept only when it is a path of this site,
  `Form/Issue/IssueSelectionRequest`). The
  page has a route per zone, `/MD/SPT/issues/selection` for the lists of
  a project and `/issues/selection` for those across projects, and one
  template, `issue/selection.html.twig`, which extends the layout of the
  zone (the project's when the controller passes `project`): both
  layouts expose the same blocks. The controller resolves where to come
  back (`back`: the list, or the zone's default). There `IssueList:Selection`
  lists the issues, each with a cross that takes it out, and beside them
  the changes to apply, which add up: every property is left alone until
  chosen in its menu, as in the side panel of an issue. `ListSelectionActions`
  says what the selection allows, which is strictly what applies to every
  issue: the rights (`issue.assign`, `issue.set_criticality`,
  `issue.set_private`, `comment.create`, `comment.set_private`) that hold
  on every project of the selection, the people offered for assignment
  being the members of all of them, and, when the selection holds one
  project, its open releases and the trackers every issue may go to
  (`SelectionActions::$moveTrackers`). The step is for internal users, and of
  the page of a project only. Its menu opens on the places every issue
  has in its own tracker (`SelectionActions::$places`: a place is the
  same in each workflow that uses it, so a selection mixing trackers
  goes to "To test" with each issue staying where it is), then, behind
  "Change category…", the places of those trackers, under their
  tracker. Each line of the list shows how the issue will get there
  (the action that leads there, outside the workflow, or by changing
  tracker). No transition is named in bulk: a
  client answers issue by issue. An issue the viewer no longer sees drops
  out of the selection. Applying dispatches the ordinary commands for
  each issue, in this order and each in its own transaction: the step
  (`MoveIssue` when no tracker was chosen or the issue is already in
  it, `ChangeTracker` otherwise), one `UpdateIssue` carrying the properties
  chosen, `AddComment`, then `FollowIssue` or `UnfollowIssue` (last,
  since commenting subscribes its author). There is no bulk
  command: a refusal stops its issue where it is and leaves the others
  done (`DispatchesCommands::refusal()`), and the page then reports, on
  the line of each issue, what it got and what was refused and why; the
  refused ones can be taken again with the same changes. The only
  feedback while the commands run is Live's `data-loading` on `apply`.
  The common comment is written in the editor, with the suggestions of
  the project when there is one, and carries no file nor pasted image,
  since a pending file is claimed by one issue only.

### Budgets

- **A budget is an account of a project, and it is optional.** A project
  has budgets once its general settings give it a unit
  (`Project::$budgetUnit`, `BudgetUnit`: days or hours); without one it
  shows none of it, those it had are kept, and every budget command is
  refused there (`BudgetException::unlessBudgeted()`). A `Budget` is an open
  order: a name unique in the project and a volume; it is closed
  (`UpdateBudget::$closed`: it keeps its lines and takes no new one),
  deleted only once closed, with its lines (`DeleteBudget`, from the
  dialog that changes it), and may end below zero.
- **A line is written by hand, and only a rule of the project ties it
  to the workflow.** A
  `BudgetLine` holds a quantity, a status (`BudgetLineStatus`: to
  estimate, pending, approved, refused) and what it is for: an issue of
  the project, a label, or both; an issue may have several lines, on
  one budget or on several. Whoever manages the budgets writes the line
  (`AddBudgetLine`), marks its status as the client answers and corrects
  it (`UpdateBudgetLine`, `BudgetLine::mark()`), or deletes it
  (`RemoveBudgetLine`). The budgets
  know nothing of the workflow: an action of a workflow marks the lines
  of its issue only where an automation rule of the project says so
  (`ActionType::MarkBudgetLines`, see [Automations](#automations)). The
  issue is left alone either way: these handlers, and that action, never
  call `IssueHandlerSupport::record()`, so there is no journal entry, no
  event and no mail. Do not bring an estimate back into the issue, its
  workflow or its history: the budgets pages are the whole of it.
  `BudgetException::unlessComplete()` is what a line must hold, for both
  handlers: an issue or a label, and a quantity once it is to approve or
  approved (`BudgetLineStatus::needsQuantity()`). A line keeps its
  quantity in every status: to estimate, it is a draft or the estimate
  to do again, and may have none, as a line refused before anyone
  estimated it; zero is a quantity like another, so taking a quantity
  away is said apart (`UpdateBudgetLine::$withoutQuantity`), and nothing
  else does. The issue of a line
  is chosen when it is written and never changes.
- **Only an approved line is consumed.** `BudgetLineRepository::totalsByBudget()`
  is the one place that says what lines count for: approved, they are
  what the budget consumed; pending, they wait and show apart; to
  estimate or refused, they count for nothing. What is left is the
  volume less what was consumed (`BudgetSummary::remaining()`).
- **Quantities are minutes; the unit is only what is shown.** Volumes
  and lines are integers of minutes whatever the unit of the project,
  so a project changes its unit at any time and nothing is written
  again. `Budget/Quantities` is the one place that converts what is
  typed in the unit and formats what is read (`quantity(project)` in
  Twig). A form shows a quantity to two decimals, which does not always
  read back to itself once the unit changed (30 minutes show as 0.07 d,
  which is 29): a form that edits a quantity converts it with
  `fromInput()`, given the quantity the field stood for, so a field left
  as shown rewrites nothing. A day lasts `CAIRN_HOURS_PER_DAY` hours (7)
  for the whole instance, and changing it changes every quantity shown
  in days. A quantity is bounded so that it fits its column, and the two
  bounds are named there: `MAX_TYPED` for the forms, in the unit, and
  `Quantities::MAX_MINUTES` for the commands, which is the first in days
  of twenty-four hours, so that what a form accepts the commands accept
  whatever a day lasts (more than 0 hours, at most 24, or `Quantities`
  refuses to start).
- **Everything is read and marked on one page.** The Budgets page
  (`/MD/SPT/budgets`, `Project/BudgetController`) shows what the open
  budgets amount to together (`Budget:Totals`), then each open budget
  with all of its lines, the latest first (`Budget:Card`), then the
  closed ones as a history, one row each. A budget also has a page of
  its own (`/budgets/{id}`), the same card alone: it is where a closed
  budget is read, and where the card of an issue leads. The bar of a
  card is the volume: what was consumed, then what waits for an approval
  in amber (`pending` of `Ui:ProgressBar`), never past its end. Whoever manages
  the budgets marks the status of a line from its menu, in the card
  (`Card::mark()`, an `UpdateBudgetLine`): the card redraws and emits
  `budget:changed`, which the totals listen to. A status that asks for a
  quantity the line does not have is a link to the dialog of the line
  instead, that status chosen (`?status=`) and the quantity focused.
  Everything else is written in the dialogs of the page, which lead back
  to the page they were opened from: the card of the page of a budget
  says so in their address (`from=budget`, `Card::$standalone`), without
  it they lead to the Budgets page. Nothing is
  paginated nor loaded later: open budgets are few and show every line,
  the totals come from SQL, and a closed budget costs one row.
- **Two permissions govern all of it, and next to nothing is hidden.**
  `budget.manage` creates and changes the budgets, deletes a closed one
  and writes their lines; `budget.view` reads. `AccessControl::canReadBudgets()` is the
  one place that says who reads (either permission, on a project that
  has budgets), for the pages, their entry in the navigation, the two
  live components (checked each time they are drawn) and the card of an
  issue; reading outlives the archiving of the project, which refuses
  every permission, writing does not. On the page of an issue, the
  read-only card of the lines that name it (`Issue:Budget`, a live
  component that only redraws on `issue:changed`, since a rule may mark
  the lines when an action is applied, through `ListIssueBudgetLines`) shows the time they amount
  to, approved and to approve. Every reader gets every line, so the
  balance is the same for all; the one thing a reader may miss is the
  issue of a line: a private issue may be named by a line, which then
  shows to who does not see it with its quantity and "Private issue",
  without key nor title (`BudgetStatement::hides()`, decided by
  `AccessControl::canSee()` in `ShowBudgetHandler`), and the quantity of
  a line to estimate, which counts for nothing: a draft, read by
  internal users and by whoever manages the budgets, shown greyed, and
  as "—" to the others until the line is to approve
  (`AccessControl::canSeeQuantity()`, the one place that says so, through
  `BudgetStatement::quantityOf()` and `IssueBudgetLines::quantityOf()`).
  The lines of an
  erased issue go with it (`IssueEraser`), those of a deleted project
  with their budgets (`ProjectRemoval`). Outside for now: the API, the
  issue lists, imports.

### Notifications

- **Notifications are subscriptions, and the events are the policy.**
  `IssueSubscription` says who follows an issue; the recipients of a
  change are its subscribers minus the actor (all of them when an
  automation rule acted: the mail then names the rule). `Notification/SubscriptionRules`
  listens to the events and subscribes the author at creation, the
  members whose effective role has `followsNewIssues` in the configuration
  (the project managers in the example), the assignee when assigned, the
  author of a comment and the people a text mentions
  (`MarkdownRenderer::mentionedUserIds()`), never someone who cannot see
  the issue, or the private comment that mentions them. Unsubscribing
  deletes the row (`UnfollowIssue`); taking part again subscribes again.
  `Issue:Follow`, a sibling of `Issue:Header` on the issue page, is the
  only setting; `Issue:Subscribers` lists them in the side panel through
  `ListSubscribers`, which keeps those who still see the issue. The
  `#[AsEventListener]` attributes of `NotificationDispatcher::collect()`
  are the whole list of what notifies: an event it does not listen to
  (`CodeReferenced`, written by the forges) notifies nobody, without
  touching the journal.
- **A mail tells what happened on a project, once things calmed down.**
  `NotifyIssueChangeHandler`, on the `async` transport, sends nothing: it
  queues the change for each recipient who may read something of it
  (`PendingNotification`: the recipient, the issue, the actor, the ids
  of the journal lines and of the comment; `Notification/ChangeFilter`
  reads a change again from its ids and says what one person reads of
  it: a private journal line or
  comment goes to internal users only, nothing visible means no row, so
  that what a client cannot read never delays their mail).
  `Notification/ProjectMailSender`, a Scheduler task every thirty
  seconds, groups what waits by person and project and sends a group
  once nothing new came for it for three minutes, or when its oldest
  change waited fifteen; one minute and three as soon as a blocking
  issue is among them (the four constants of the class,
  `ProjectMailSender::isDue()`). An action followed by a comment, a card
  moved four times, thirty issues changed from the selection page are
  one mail. Each group is one transaction that starts by deleting its
  rows (`PendingNotificationRepository::delete()`): the database lets
  one sender delete them, so two workers, or a run by hand during a
  scheduled one, never send a change twice, without a lock. The rows
  are then read through `ChangeFilter` again (rights move, and an issue
  the recipient stopped following or an account deactivated since is
  left out) and the mail message lands in the Doctrine transport in
  the same transaction; with another transport for the mails, a commit
  that fails after the mail left would send it again. A mail that
  cannot be built is logged as an error, its rows keep waiting and the
  others leave. A row that waited for more than a day
  (`ProjectMailSender::MAX_AGE`: a worker down, a mail that kept
  failing) is dropped with a warning in the logs instead of being sent:
  the issue keeps its history, a mail is only worth sending while it is
  news. The mail has
  one format, whether one issue changed or ten
  (`IssueMailer::send()`, `IssueChanges` and `IssueChange`): the project
  as a heading, then one card per issue, blocking ones first, with each
  command's journal lines (`journal_label`) and comment, rendered for
  the recipient by `MarkdownRenderer`, links made absolute, and the
  description of an issue created in the meantime. The subject names
  the client and the project (`[Maison Dupont / Online shop]`), then
  the issue, or the keys of the first three. There is no thread per
  issue any more. One setting per person, `User::$mailGrouping`
  (`MailGrouping`, in `Entity/Enum/`, chosen on the Notifications page
  of the account): a
  mail per project, the default, or a mail per issue, which only
  changes what `ProjectMailSender::groups()` puts together: the same
  queue, the same waits counted per issue, the same mail with one card.
  A daily digest (the same queue with a longer wait) and a chat channel
  per project would each be another reader of the queue. One template per mail
  (`templates/email/project_changed.html.twig`, `invitation.html.twig`,
  `password_reset.html.twig`, over `_layout.html.twig`, whose `card`
  block the project mail replaces with its own cards); the account
  mails are sent by their handler, the mail message landing in the
  Doctrine transport inside the command's transaction, so it leaves only
  if the command commits; until the worker sends it, and in `failed`
  after three refusals, the row holds the link in clear, so `failed` is
  purged, never kept. A worker must consume `async` and
  `scheduler_default` in production, as `messenger:consume async
  scheduler_default` does in dev: without the second, changes queue and
  no issue mail ever leaves, and no automation rule that waits for time
  ever runs; `app:notifications:send` sends what is due
  by hand, `--now` everything that waits. `MAILER_FROM` and
  `DEFAULT_URI` are set per environment.

### Real time

- **Real time is a signal, and it is optional.** With a Mercure hub
  (`MERCURE_URL`), the lists and the kanbans of a project follow what
  others do; without one nothing is published and no page listens
  (`Realtime/ProjectTopics::enabled()`, the one place that says so).
  `Realtime/IssueBroadcaster` listens to the domain events as
  `NotificationDispatcher` does, waits for `postFlush`, and dispatches a
  Mercure `Update` with `DispatchAfterCurrentBusStamp`, routed to
  `async`: the worker publishes, so a hub that is slow or down never
  holds a request. An update carries no content: the id of the issue,
  its tracker, its place and the place it just left. A page that hears it reads again
  what it shows through the queries it was drawn with, so `Visibility`,
  the filters and the rights are written nowhere else; never broadcast
  HTML or data (no Turbo Stream broadcast): everyone would get the same.
  Even a signal says something, so a project has two topics, both
  private: `projects/{id}/internal` hears every change,
  `projects/{id}` only those an external user may see (the issue is not
  private, and the change carries something else than a private comment
  or private journal lines; a transition always counts, since its place
  shows). An issue that just left their sight (made private, moved to
  another tracker) is named once more on it, by its id alone. A page
  is given what it listens to, never more: `realtime_topics(project)`
  (`Twig/RealtimeExtension`: both topics for an internal user, one for a
  client, none without a hub) for the address of the hub, built by the
  bundle's `mercure()` Twig function, and `realtime_token(project)`, a
  token signed for the hub that allows those topics only, for twelve
  hours. The controller sends it in an `Authorization` header, which
  the browser's `EventSource` cannot do, hence `event-source-polyfill`.
  No cookie: a browser has one for all its tabs, so a tab on another
  project would take the right away from the first. The hub must refuse
  anonymous subscribers, or a topic could be heard by its name alone.
  The `live-updates` controller sits on the `issues` frame of a
  project's list (`issue/_results.html.twig`): it hears the viewer's
  own changes too (they may have another tab open), gathers them for a
  moment and waits for a hidden tab to show. The list of a tracker leaves the changes of
  the other trackers alone. A kanban redraws the `Board:Column` that
  holds the card and those of the place the issue left and of the one
  it is at, never while a card is
  dragged or being settled; a table reloads its frame (without telling
  `list-view` to remember), unless rows are checked: it then shows
  "The list has changed" and waits to be asked. Outside for now: the
  lists across projects (they would need a topic per person), the page
  of an issue, and the rank (`RankIssue` records no event). In dev the
  hub is the one built into FrankenPHP (`docker/Caddyfile`); the tests
  run without a hub, except those that give themselves one
  (`IssueBroadcasterTest`, `LiveUpdatesTest`).

### Automations

- **A rule is a trigger, conditions and actions, from closed lists.** An
  `AutomationRule` belongs to a project, holds on every tracker or on the
  one it is limited to, and keeps what it says as an array
  (`Automation/Definition/RuleDefinition`): one trigger (`TriggerType`), conditions
  that must all hold (`ConditionType`), actions done in order
  (`ActionType`). No branch, no script, no variable. Each case of the
  three enums declares the values it asks for as `Parameter`s of a
  `ParameterKind` (a choice, days, a place, a place or an action of the
  rule's tracker, a criticality, a target, a text, a flag), possibly
  several at once (`Parameter::$multiple`: the step holds on any of
  them; the conditions on the place and on the criticality do), and the kind
  alone decides how the value is checked (`RuleChecker`) and which field
  edits it (`Form/Automation/RuleType`): a new type of step is an enum
  case, its labels (`automation.trigger|condition|action.<value>`, its
  sentence in `automation.sentence.*`) and its effect in the one class
  that reads the enum (`RuleEngine::triggers()`, `RuleConditions`,
  `RuleExecutor`), never a form. A step that names an action of a
  workflow, or moves an issue to a place, needs the rule limited to a
  tracker. A trigger or a condition names places by their id, and a
  place is the same in every workflow that uses it: without a tracker
  the rule holds on every tracker that has the place, and the step
  condition takes several and holds on any of them. The form offers the
  places of the workflows in reach, each once
  (`RuleContext::places()`); a rule limited to a tracker is only
  offered the places of that tracker.
- **A rule names roles, not people.** Whoever an action aims at is a
  `Target`: the referent of a role, the author, the last
  internal user who wrote on the issue, nobody, or a named member
  (`user:<id>`, the only target tied to one project). `Automation/Targets`
  resolves it on an issue and says why when nobody is behind it. A
  **referent** is a mark on a `Membership` (`Membership::$referent`,
  `SetReferent`, one per role where the membership applies): nobody is
  one by default, a project membership answers for that project, a
  workspace membership for every project of the workspace that names
  none of its own (`Security/Referents`), and changing the role takes
  the mark away. The referent a project designates replaces the one of
  its workspace there, and the workspace answers again when that mark no
  longer holds (`Security/RoleReferent`, from `Referents::resolve()`: both
  marks of a role and which one answers). Referents are managed in one
  place: the card that opens `Membership:Grants` on a project and on a
  workspace (`Grants::getReferentRows()`), one row per role: who answers,
  on a project designated there or inherited and whom they replace, and
  a menu to designate among the members who hold the role there (the
  direct members, on a project) or to go back to nobody, or to the
  workspace's. The rows of the lists carry neither badge nor button for
  it. Only the administration marks a workspace membership. Two holders of a role on a workspace cannot each be the
  referent of one project: give them their access per project. This is
  what a project template needs: its rules are copied as they are.
- **Where a rule is written is a context.** `RuleChecker`, the form and
  `RuleSentence` only know a `RuleContext`: the trackers with their
  workflow, the roles, the people. `RuleContexts::ofProject()` gives the
  one of a project; `ofTemplate()` gives the one of a project template
  (its own trackers, no people, so a rule there names roles only), and
  the three serve both as they are. Rules are written
  under `project.configure` on `/MD/SPT/settings/automations`
  (`Controller/Project/AutomationController`: the list, switched on and
  off in place, the editor, the executions), with `CreateRule`,
  `UpdateRule` (PATCH; `active` is a property) and `DeleteRule`, which
  carry an optional `actorId` like the other project configuration. The
  editor is the live component `Automation:RuleForm`: a Symfony form
  rebuilt at each change from what the page holds
  (`RuleData::fromValues()`, which drops the values of the type a row no
  longer has and the choices `RuleChecker::allows()` no longer accepts
  there), in the shape a rule is kept in, so nothing is converted
  between the form and the commands; rows added and removed by live
  actions, and the rule said
  in one sentence beside it (`RuleSentence`, also `rule_sentence()` in
  the list). No dynamic-form library: the redraw is the dependency. The
  editor lists private trackers and the members, so it checks
  `project.configure` each time it is drawn (`RuleForm::guard()`), not
  only the page that holds it; every choice is offered by its value,
  since two members may bear the same name. What the editor does
  wherever the rule is written is the trait
  `Twig/Components/Automation/EditsRule` and the one template
  `components/Automation/RuleForm.html.twig`; `Template:RuleForm`, the
  editor of a rule of a project template, adds its guard, its context
  and its commands. The handlers of both share what they do alike:
  `MessageHandler/Command/Automation/RuleWriting` reads and checks what
  a rule says, `MessageHandler/Command/Tracker/ListOrder` numbers the
  trackers of a project or of a template (`Entity/Positioned`).
- **A rule acts without an author, through the usual doors.**
  `RuleEngine` listens to the domain events inside the command's
  transaction, after the other listeners (its `#[AsEventListener]`s are
  the whole list of what may trigger a rule), and gives each rule whose
  trigger and conditions match to `RuleExecutor`. A rule is written by
  whoever configures the project, so it asks no right and follows no
  workflow (it puts an issue on a place whether an action leads there or
  not), but it changes the issue as a handler does: the intent methods,
  journal entries without author that keep the name of the rule
  (`JournalEntry::byRule()`, `data.rule`), a comment without author
  signed the same way (`Comment::byRule()`, `Comment::$signature`), and
  `IssueHandlerSupport::record()` with the usual event, no actor and the
  rule's name. So the assignee is subscribed, the mails leave (to every
  follower, under the name of the rule: `NotifyIssueChange`,
  `PendingNotification` and `IssueChange` carry `rule` beside a nullable
  actor) and the lists redraw. What cannot be done is skipped and
  reported, and so is an action that throws (logged, reason `error`):
  a rule must not fail the command that triggered it. Only an error of
  the database goes up, since the transaction it broke cannot go on. Rules do not go through the issue commands, which are
  what a person may do; do not add an `actorId: null` to those.
  One action is not a change of the issue: `MarkBudgetLines` marks the
  lines of the open budgets that name the issue and stand in one of the
  statuses the rule starts from (`from`, several) as the one it gives
  (`status`), each keeping its quantity. A rule does not estimate: a
  line without a quantity stays where it is when the status asks for
  one (`no_quantity` when none could be marked), and a project without
  budgets skips the action (`no_budget`). Nothing is journaled nor
  recorded, as when a manager marks a line, so nobody is told and no
  rule follows: the execution is the trace.
- **Rules follow one another, three deep.** What a rule does is an
  event, so another rule may react. A chain is everything that follows
  from one thing someone did (or from one rule that time started): a
  rule runs once per issue in it, and the rule that would be the fourth
  inside one another is not run (`RuleEngine::MAX_CHAIN`); an execution
  says so. A rule itself holds twenty conditions and twenty actions at
  most (`RuleChecker::MAX_STEPS`).
- **Every execution is kept.** An `AutomationRun` is written when a rule
  whose trigger and conditions matched ran or was stopped: the rule
  under the name it had, the issue, what triggered it, its `RunOutcome`
  (applied; partial when some actions could not be done; skipped when
  none could; stopped) and one line per action (`StepResult`) with its
  result and, when skipped, the reason
  (`no_referent`, `not_a_member`, `unknown_place`…). A rule that did not
  match leaves nothing. The Executions page lists them
  (`ListAutomationRuns`); the page of the rules warns of a role its
  active rules aim at that has no referent (`MissingReferents`). A rule
  that stopped meaning something (a step taken out of its workflow)
  blocks nothing: the configuration commands know nothing of the rules, and
  the rule is skipped where it cannot act, which its executions say.
- **Time is counted from the last person.** A rule may wait for
  "no activity for N days" instead of an event. `Issue::$lastActivityAt`
  moves when a person or a forge acts, never when a rule does, so the
  reminder one rule writes does not put off the closing another
  promises. `Automation/TimeRuleRunner`, a Scheduler task every hour,
  finds for each such rule the open issues untouched for that long on
  which it has not run since (`AutomationRuleRepository::findDueIssues()`,
  which reads the executions), checks the conditions, and dispatches one
  `RunRule` per issue, each its own transaction; the handler asks the
  same SQL again for that issue (`isDueOn()`), so "due" is written
  once. An execution of any outcome counts: a rule skipped for want of a
  referent does not try again every hour, it waits for someone to act. A rule acts once, then waits for
  someone to touch the issue. `app:automations:run` does the same by
  hand, `--dry-run` only counts. Outside for now: triggers from the
  forges (a merge request merged), an action that changes the tracker,
  rules in the API.

### Forges

- **Code references come from the forges, for internal users only.** A
  project links the repositories of a forge (`CodeRepository`: forge,
  path `group/project` kept in lower case, a secret of its own generated
  by Cairn) on `/MD/SPT/settings/repositories`, under `project.configure`.
  `UpdateRepository` (PATCH: `installed`, `regenerateSecret`) installs
  the webhook on the forge through its API with the token of the
  instance (`FORGE_GITLAB_URL`, `FORGE_GITLAB_TOKEN`) or removes it;
  without a token the page shows the URL (`ForgeApi::deliveryUrl()`,
  built from `DEFAULT_URI`) and the secret to paste by hand.
  `UnlinkRepository` deletes the link after confirming in place; the
  references stay. Deliveries land on the `symfony/webhook` route
  `/webhook/gitlab`, outside the `main` firewall:
  `Forge/GitLab/GitLabRequestParser` refuses a token that is not shaped
  like one of ours before reading the body, names the repository from
  the payload, authenticates the delivery against the secret of each
  link of that path (unknown or wrong: 403, never an instance-wide
  secret), tames every text it reads (scalars only, no NUL byte, cut to
  the columns, at most 100 commits) and emits one neutral event per link
  matched (`Forge/Event/PushReceived`, `MergeRequestReceived`); a kind it
  does not read is accepted (202) and ignored. `ConsumeRemoteEventMessage`
  travels on `async`, so the worker does the writing:
  `Forge/CodeReferenceConsumer` finds the issue keys
  (`Forge/IssueKeyParser`: commit messages, merge request title,
  description and source branch) and dispatches one `RecordCommits` per
  issue (its new commits, journaled as one `commits_pushed` entry) or one
  `RecordMergeRequest` per issue (`merge_request_opened|merged|closed|
  reopened`, journaled on a change of state only; merged is final). The
  handlers are the authority on what a key is worth: an unknown key, a
  key of another project, a read-only project end quietly. A
  `CodeReference` is unique per issue, forge, repository, kind and id: a
  commit pushed again or a delivery replayed writes nothing. These
  journal entries have no author and are private; the commands carry no
  actor and `record()` a `CodeReferenced` event that no notification
  listens to. No transition is ever applied. The issue page shows each
  entry as a card (`Ui:CodeCard`, read by `Issue:JournalEntry` from the
  entry's data) and the side panel lists the merge requests and the
  commits by branch (`Issue:CodeReferences`, through
  `ListCodeReferences`, which answers `[]` to an external viewer).
  Accepted: a hook left on the forge when a write fails after the API
  call, a second hook on a second "install", hooks of an archived
  project that nobody can remove from the UI. GitHub comes as
  `Forge/GitHub/` with its own parser and API client behind the same
  `ForgeApi` and events.

### API

- **The API is a second door, not a second application.** Everything
  under `/api` is API Platform, with `doctrine: false`: a resource is a
  readonly class of `Api/Resource/` (`IssueResource`, short name
  `Issue`), never an entity, and each operation names its provider or
  its processor in `Api/State/`. A provider builds the query with
  `Security/Viewer` and asks the `QueryBus`; a processor builds the
  command with the viewer as `actorId`, dispatches it through
  `Api/State/CommandDispatcher`, then reads the result again through
  the query, so the answer is what this viewer sees. Neither holds a
  rule: rights, visibility and refusals are those of the handlers.
  What the API shows is built in `Api/Mapper/`, one small class per
  resource that needs services: `IssueMapper` (fields, files, the
  actions offered and where they lead, decided with `AccessControl`
  for the viewer), `CommentMapper`, `LinkMapper`, `TrackerMapper`, and
  `Refs` for what every resource names the same way (a person, whose
  email is for internal users, a file, another issue, their addresses). A
  resource that only copies what a query returned builds itself
  (`ProjectResource::of()`, `ReleaseResource::of()`). The values nested
  in a resource are readonly classes of `Api/Resource/Value/`
  (`UserRef`, `FileRef`, `PlaceRef`…), never arrays, so the OpenAPI
  schema describes them; in JSON-LD they carry a `@type` and no `@id`
  (`gen_id: false`). `Api/State/Lookup` turns what a URL names into
  what the viewer may see (`issue()` with `AccessControl::canSee()`,
  `detail()` through `ShowIssue`).
- **URLs carry the keys of the application.** A project is `MD-SPT`,
  an issue `MD-SPT-12`; comments, links, trackers and releases have
  their id. What the viewer may not see does not exist: 404 from
  `Lookup`, before any command. The lists a program reads are
  `GET /api/issues`, whose filters become a `SearchIssues` (a filter
  the API does not understand is a 400 or a 422, never ignored as in
  the pages), the projects (`ListVisibleProjects`) and, under a project,
  its trackers with what their workflow asks of an issue (the archived
  ones too, marked: their issues are still read and moved), its
  members and its releases. A list leaves the description out; an issue
  comes with it, its fields, its files and the
  transitions `AccessControl::transitionsFor()` offers; its comments,
  links, history and the viewer's subscription are sub-resources.
- **Writes are the commands, one operation each.** `POST /issues`
  (`CreateIssue`), `PATCH /issues/{key}` (`UpdateIssue`),
  `POST /issues/{key}/transitions` (`ApplyTransition`: the action is
  named, a comment may come with it), `PUT /issues/{key}/step`
  (`MoveIssue`: the step is named, the handler finds the way),
  `PUT /issues/{key}/tracker` (`ChangeTracker`), comments
  (`AddComment`, `EditComment`, `DeleteComment`), links (`AddLink`,
  `RemoveLink`), `PUT`/`DELETE /issues/{key}/subscription`
  (`FollowIssue`, `UnfollowIssue`) and
  `POST /projects/{key}/attachments` (`UploadAttachment`, multipart: the
  file waits until its uuid is named in the `attachments` of a write).
  A file is read back at `GET /attachments/{uuid}/content` (its
  `contentUrl`), decided by `canSeeAttachment()` and answered by
  `AttachmentStorage::serve()` like the page of the application, whose
  address (`url`) only a session opens.
  The input of an operation is a class of `Api/Resource/` (`…Input`)
  with the properties of the command minus the actor, and its own
  constraints. PATCH is a JSON merge patch: `UpdateIssueProcessor` reads
  which properties the request names, so a property left out neither
  changes nor asks for its right, and `"assignee": null` or
  `"release": null` become `UpdateIssue::NOBODY`.
  `CommandDispatcher` is the pendant of `DispatchesCommands`: an invalid
  command is a 422 listing its violations, a domain refusal a 422 with
  the reason (translated when the exception can be), a missing right
  the handler's `AccessDeniedException`, 403. The administration, the
  configuration of a project and bulk actions are not
  exposed: add an operation when a program needs it.
- **A token signs a request, the account opens the API.** The `api`
  firewall (`^/api`, stateless, before `main`) knows one way in: a
  personal token in `Authorization: Bearer`, checked by
  `Security/ApiTokenHandler`. No session opens it, so no CSRF token is
  needed. An `ApiToken` belongs to a user, keeps the SHA-256 of its
  secret (`cairn_` and 64 hex digits, shown once at creation), a scope,
  an optional expiry and when it was last used (written at most once an
  hour, by the repository: authenticating is not a command); it is
  revoked, never deleted. The API is for internal users: the access is
  the account's, not a role on a project, so there is no permission for
  it. `AccessControl::canUseApi()` says so, checked when a token is
  created and at every request, as is the `UserChecker`: a deactivated
  account, or one made external, loses the use of its tokens; an account
  still waiting for its password cannot sign in, so holds none. A token
  acts as its user and may only narrow what they do: its scope
  (`ApiTokenScope`: `read`, or `write` which reads too) is asked by
  `access_control` for every request of `/api` by its method
  (`api.read` for GET and HEAD, `api.write` otherwise) and answered by
  `Security/Voter/ApiScopeVoter`, so no operation declares it.
  `Security/ApiRateLimiter` keeps the tokens of a user, together, to 300
  requests a minute
  (`config/packages/rate_limiter.yaml`), 429 beyond. Even so, nothing
  in `Api/` assumes an internal viewer: opening the API to clients
  would be a change of `canUseApi()` alone.
- **The MCP server is a few tools over the same code.** `/mcp`
  (`symfony/mcp-bundle`, the `cairn` server of `config/packages/mcp.yaml`,
  HTTP only) lets the assistant of an internal user, Claude Code first,
  read and move issues along. A tool is an API Platform operation
  (`api-platform/mcp`, experimental): a class of `Api/Mcp/` (`…Tool`)
  carries `#[McpTool]`, its properties are the arguments, and its
  processor in `Api/Mcp/State/` hands them to what the REST API already
  has: the processors of `Api/State/` (`create_issue`, `update_issue`,
  `add_comment`, `move_issue`, `change_tracker`: a tool of its own, so
  that an assistant leaves the workflow of an issue only when asked to),
  `IssueCollectionProvider::search()`
  (`search_issues`: the filters of `GET /api/issues`), `Lookup` and the
  mappers. What a tool answers is a resource of the API, or one of the
  classes that put several together so that one call is enough:
  `IssueView` (the issue, its comments with their files, its links:
  `get_issue`), `ProjectView` (the project, its trackers, members and
  releases: `get_project`) and `IssueList`. `get_attachment` hands the file
  itself over, since its address needs a session or a token the
  assistant's shell does not have: an image as an image (a screenshot is
  seen), a short text in UTF-8 as text, anything else as its bytes (an
  embedded resource), whatever its size or encoding, one file per answer. It
  is the same door as the API: the `api` firewall and its tokens,
  `canUseApi()`, the rate limiter. Every request being a POST, the scope
  is asked by each tool (`security: "is_granted('api.read')"` or
  `api.write`), which also hides from a `read` token the tools that
  write. Each tool sets `validate: true` (the constraints of its class;
  off by default for a tool), answers plain JSON as text only
  (`structuredContent: false`: an assistant pays for every character,
  and for the schema of every answer otherwise) and a refusal comes back
  as the sentence the REST API gives (`exception_to_status` names the
  handlers' `AccessDeniedException` for that). A comment written through
  a tool is private unless the call says otherwise
  (`AddCommentTool::$private`): what an assistant writes is for the team
  first. An assistant fills the arguments it does not use with
  nulls and empty strings, so neither means anything: `update_issue`
  unassigns with the word `none` (`UpdateIssueTool::NONE`), never with a
  null, and `search_issues` drops an empty filter. `allowed_hosts` is the host of `DEFAULT_URI`, the bundle
  refusing any other; sessions are the bundle's files in the cache
  directory, bound to the user, so several application servers need its
  `cache` store. Links, files to send, subscriptions and deletion are left to the API: add a tool when an assistant
  needs it. `/account/mcp` (`AccountController::mcp()`) gives the command
  that plugs Claude Code.
- **Tokens are managed by their owner** on `/account/tokens`
  (`Controller/ApiTokenController`, in the sidebar of the account of
  those who may use the API): `CreateApiToken` returns a `NewApiToken`, the token and
  the secret that is stored nowhere, carried once to the next page in a
  flash of its own, with a button copying it (the `clipboard`
  controller) and a request to try; `RevokeApiToken` confirms in place.
  The form offers lifetimes (`Form/ApiToken/ApiTokenData::LIFETIMES`, 90 days by
  default) rather than a date; revoked and expired tokens stay listed
  apart.
- **The documentation is a page of the application.** `/api/docs`
  (Swagger UI, OpenAPI at `/api/docs.jsonopenapi`) is left out of the
  `api` firewall: it is read signed in, in every environment, by the
  internal users (`ROLE_INTERNAL` in `access_control`), and the tokens
  page leads to it. A token does not open it, a session does not open
  the rest of `/api`: to try an operation there, paste a token in
  "Authorize". Answers are JSON-LD by default, plain JSON on
  `Accept: application/json`, errors `application/problem+json`; null
  properties are left out.

### Account

- **Everyone has their account, a zone of its own; who they are stays
  the administration's.** The pages under `/account` share
  `layout/account.html.twig` and the sidebar `Layout:AccountNav`, one
  entry and one address per subject, reached from "My account" in the
  user menu: Profile (`/account`), Notifications, Security
  (`Controller/AccountController`) and, under Integrations for whoever
  may use the API, the tokens (`/account/tokens`,
  `ApiTokenController`) and how to plug an assistant on the MCP server
  (`/account/mcp`). A
  new subject is a route, a template extending the layout (`page_title`,
  `content`, and `page_intro`, `page_actions`, `page_width` when
  needed) and an item of the sidebar, never a card added to another
  page. The profile shows the name, the email and the company without
  a field: only an administrator changes them, in `/admin`.
  Notifications is one choice, two `Ui:Form:RadioCard`s, sent as an
  `UpdateUser` carrying `actorId`: with an actor the handler accepts
  the account of that actor only, and only its `mailGrouping`. Security
  is `ChangePassword`, which checks the current password (the form does
  too, `UserPassword`, so the refusal shows under its field) and drops
  the reset links still out; the new password ends every session of the
  account (`User::isEqualTo()`), so the controller signs the person in
  again on the one they asked from (`Security::login()`). An account
  may try five changes in fifteen minutes (the `password_change`
  limiter, counted by the controller before the form is read, right
  password or not): an open session is not a way to guess the current
  password.
- **A picture replaces the initials, everywhere.** `ChangeAvatar` takes
  a PNG, JPEG or WebP image up to 2 MB, or nothing to remove it;
  `Attachment/AvatarStorage` keeps it under `ATTACHMENTS_DIR/avatars`
  with a name of its own (`User::$avatar`), so a new picture is a new
  address, served by `/avatars/{name}` to whoever is signed in and kept
  by browsers for good. The image is not processed on the server (the
  PHP image has neither GD nor Imagick): the `avatar` controller cuts it
  to a 256 px square in the browser before sending it, and a browser
  that cannot sends the file as chosen, which `object-cover` crops.
  A person is drawn with `<twig:User:Avatar :user="…" size="…" />`,
  the one place that turns a `User` into the props of `Ui:Avatar`
  (name, kind, and the picture as `src`, from `avatar_url(user)`,
  `Twig/AvatarExtension`; nobody is the unassigned avatar); the colour
  of the kind stays as a ring around a picture. Only `Ui:BoardCard`,
  which takes scalars, is given the address itself. Mails and the API
  keep to names.

### Administration

- **The administration is guarded at its door.** Everything under
  `/admin` requires `ROLE_ADMIN` (`access_control` in `security.yaml`,
  and `#[IsGranted('ROLE_ADMIN')]` on each `Controller/Admin/`
  controller). Its commands (workspaces, projects, memberships,
  accounts) carry no actor, like `CreateUser` which the console
  dispatches with nobody signed in; their handlers enforce the rules of
  the data: a role exists in the configuration, an `internalOnly` role
  never goes to an external user (who cannot become external while they
  hold one), a role granted on a project widens the one inherited from
  its workspace, the last active administrator stays one. Keys never
  change once created. An archived project, or workspace, is deleted for
  good from a page of its own (`/admin/projects/MD/SPT/delete`, reached
  from its row; `/admin/workspaces/MD/delete`, from its settings), which
  counts what goes with it (`ShowProjectContents`) and asks for its key
  to be typed again (`Form/Admin/DeletionType`); an account that left no
  trace is deleted from its page, confirmed in place.
  A **project template** (`/admin/templates`,
  `Controller/Admin/TemplateController`) is what a new project may start
  with: trackers (`TemplateTracker`: what a tracker says, without a
  project) and automation rules (`TemplateRule`), written through the
  commands of `Message/Command/Template/`, one page per tab (Categories,
  with `Template:TrackerOrder` and `Form/Tracker/TrackerType`;
  Automations; Settings, where it is deleted, confirmed in place). The
  form of a new project offers the templates when there are some
  (`CreateProject::$templateId`), and
  `MessageHandler/Command/Project/TemplateCopy` builds the trackers and
  the rules of the project in the transaction of its creation, each rule
  limited to the tracker made from the one it named, switched on or off
  as it was. It is a copy and nothing more: the project keeps no trace
  of its template, changing or deleting the template changes no
  project, and a template is never applied to a project that exists. A
  role its rules aim at has no referent on the new project until someone
  is designated, which the page of its rules says (`MissingReferents`).
  The live components of the templates check `ROLE_ADMIN` themselves:
  their endpoint is not under `/admin`.
  Project configuration and memberships seen from
  a project belong to its manager, in the project zone, not here:
  the Configuration section of the project navigation (`Layout:ProjectNav`),
  one item and one page per subject: General (`/MD/SPT/settings/general`),
  Categories (`/MD/SPT/settings`), Access (`/MD/SPT/access`), Automations
  (`/MD/SPT/settings/automations`) and Repositories, the access
  guarded by `project.manage_members`, the rest by `project.configure`.
  A new subject is a route, a page extending
  `project/settings/base.html.twig` and an item of that section, never a
  tab: the page fills `settings_title` and `settings_content`, and when
  it needs them `settings_count`, `settings_intro`, `settings_actions`
  (its main action, at the right of the title) and `settings_tabs`, all
  drawn by `Ui:PageHeader`. They
  reuse the administration's commands with an optional `actorId`: null
  is the administration, a set actor is checked by
  `MessageHandler/Command/ProjectActor` (one call per handler), may
  act on that project only (never on a workspace membership) and never
  archives, since an archived project grants nobody the right to bring
  it back. On the Access page someone is added from one field
  (`Membership:Grants` in project mode, in a dialog its `adding` opens:
  the button of the page header calls `open`, and `?add=1` opens the
  page with it). What is typed searches every account of the instance
  (`SearchUsers`: names, company, address; `Grants::SUGGESTIONS` shown,
  and how many more match), the arrows and Enter moving among them (the
  `suggestions` controller); each says what it may be given
  (`Grants::stateOf()`), and one that cannot be chosen shows with the
  reason, a deactivated account included, so that nobody creates it
  again. An account chosen gets a role; an address no account uses is
  offered as a new account (an address shaped `first.last@…` fills in
  its name, which the person adding only checks) and gets an account,
  the role and an invitation, three ordinary commands in a row (`CreateUser` with `actorId` and
  `projectId`, which refuses an internal or administrator account,
  `GrantMembership`, `InviteUser` to a direct member of that project),
  a refusal stopping the chain where it is. The administration picks the
  person, or the workspace or project, in a `Ui:Picker`. The component
  gives the accounts of the instance to whoever asks its endpoint, so it
  checks who draws it each time (`Grants::guard()`:
  `project.manage_members` on a project, `ROLE_ADMIN` otherwise). Both permissions show what external users never see (private
  trackers, every account of the instance), and `issue.force_place` and
  `issue.change_tracker` leave the workflow clients are shown, so the
  configuration grants
  them to internal-only roles: `Permission::internalOnly()` says which,
  `GrantPermission` refuses the rest; `issue.delete`, which cannot be
  undone, is internal-only too. A tracker is archived
  (`UpdateTracker::$archived`: no new issue, out of the creation form and
  of the trackers an issue may change to, but still in the navigation,
  at its place, with its list and its kanban), moved (`position`), or
  deleted while it holds no issue (`DeleteTracker`, with the rules
  limited to it): the buttons under its settings, each saying what it
  does.
  The configuration of the instance has its own section of
  `Layout:AdminNav`, under `/admin/configuration`, one page per subject
  (see [Configuration](#configuration)).
- **An import brings an archive in, once, silently, and knows no other
  tracker.** Under `/admin/imports` (`Controller/Admin/ImportController`).
  An archive is a zip in Cairn's own format, written by an export script
  that lives outside this repository, one per tool to leave:
  `manifest.json` declares, by ids of the source, the people (an email
  each), roles, workflows with their statuses, trackers, priorities,
  fields, link types and versions; `issues.ndjson` holds one issue per
  line with its comments, files and links; `files/` holds the files.
  Texts carry the tokens `{{issue:ID}}`, `{{user:ID}}` and `{{file:ID}}`.
  `Import/ImportManifest` and `ArchiveIssue` read it strictly, and
  `/admin/imports/format` shows it with the example of
  `templates/admin/import/example/`, which `ImportArchiveTest` checks
  is valid: change the three together. The tests import their own
  archive, `tests/Import/Fixture/`, a copy that changes with them. A zip
  is sent from the administration within PHP's upload limit, or dropped
  in `IMPORTS_DIR` (`Import/ImportsDirectory`); an imported one moves to
  its `archive/`, and `work/<id>/` holds what an import extracted. An
  `Import` goes to an existing project, one unfinished import per
  project and per archive. It is analysed (`ImportAnalyser`: every issue
  checked against the manifest and counted), then configured in steps,
  one route each (content, mapping, accounts, summary), which feed
  `ConfigureImport` in the shapes of `ImportMapping`, guessed from the
  names first; `ImportMappingChecker` refuses only what the run could
  not make up for (a tracker sent nowhere known, a status in use
  without a place, no issue left): closed issues,
  files and versions or not; each tracker to a tracker of the project,
  to one to create on a workflow, or nowhere; each status to a place of
  that workflow; each field to a field of a fitting type, to the end of
  the description, or nowhere; priorities and link types to Cairn's.
  The mapping page is the live component `Import:Mapping`: the statuses
  to place follow the trackers chosen above them, redrawn at each
  change, and nothing is kept until it is saved. A role is chosen per
  person, on the accounts page only, offered at first as the role of
  Cairn that has the label of theirs in the archive
  (`ImportMapping::guessedRole()`). A person whose
  email is an account is linked to it; for the
  others the import creates an external account without a password,
  deactivated by default or active (to invite afterwards from its
  page). Everyone gets the role chosen for them on the project,
  deactivated accounts included: they cannot sign in, and reactivating
  one gives its access back; an access already there is never changed.
  So an import is made without a word to the client, its accounts
  activated once the project is ready: a deactivated account keeps the
  open issues assigned to it, and only someone left without a role
  loses them, which the accounts page (the open issues assigned to each
  person, `ImportAnalysis::openAssigned()`) and the summary say before
  the run.
  Then it runs (`ImportRunner`): the numbers of every imported issue
  reserved in one block (`Project::reserveIssueNumbers()`, so a key is
  known before its issue is written), accounts, access, trackers and
  releases, the issues in the order of the archive, their links, then
  the archive is put away. Each issue is one `ImportIssue`, built by
  `ImportIssueBuilder`: its dates
  and authors of then, its place without the workflow and on top of its
  column, its comments, files and subscriptions, what named it before
  on `Issue::$importedRef` (searched by `SearchIssues`, shown in the
  header) and a private `imported` journal line. Nothing notifies. The
  work goes in batches of about twenty seconds: `AdvanceImport` on
  `async` runs one and sends the next, acting only with
  the `runToken` of the last start, so a message of an earlier start or
  delivered twice does nothing; a failure fails the import with its
  reason instead of being thrown, and `StartImport` resumes it where it
  stopped: every item leaves an `ImportRecord` (done, or refused with
  the reason), and an item recorded is never written again. What the
  report must say beside an item that was written is a record too,
  under an id of its own that no archive can use (`1001#twice`: a
  second issue with that id, left out; `bug#environment`: a field its
  workflow does not use, kept but shown nowhere; `v1#dueOn`: a date of
  a version that cannot be read, replaced by today's). Only the
  versions of the issues taken become releases, and a refusal of the
  analysis names the issue by its id, never by a line. The
  analyser and the runner write their own bookkeeping; what lands in the project goes through
  commands, and the unit of work is cleared after each item. The page
  of an import follows it with `Import:Progress` (`data-poll`); there is
  no way to stop a run, only to abandon an import that is not running.

## Frontend

- Assets are built by Vite through Symfony Reprise, not AssetMapper.
  `vite.config.ts` declares one entry, `app` (`assets/app.js`, which
  imports the CSS), and the Reprise plugin registers the Stimulus
  controllers from `assets/controllers.json` and `assets/controllers/`.
  JavaScript dependencies are npm packages in `package.json` (`npm
  install <package>`, lock file committed); Symfony UX packages point to
  their `vendor/` copy with `file:`. Templates render the entry with
  `reprise_entry_link_tags('app')` and `reprise_entry_script_tags('app')`.
  The build goes to `public/build/` (`npm run build`, `castor
  assets:build`); in dev the `vite` service of the Docker stack serves
  the assets with hot reload. Tests render no asset tags (`strict_mode` is
  off in `test`), so they need no build.
- Tailwind v4, configured in CSS (`assets/styles/app.css`: `@theme`, `@utility`,
  `@source`), built by Vite with `@tailwindcss/vite`. No `tailwind.config.js`.
- **Colours are roles, light and dark.** Every colour is a token of
  `@theme` in `app.css`, one per line, with its light and its dark value
  (`--color-surface: light-dark(#ffffff, #161b22)`): grounds (`canvas`,
  `surface`, `overlay` for what floats, `subtle` for hovers, `sunken`,
  `raised`, `inverse`), text (`strong`, `body`, `muted`, `faint` for icons
  and counts, `ghost` for separators, `on-inverse`), lines (`line-subtle`,
  `line`, `line-strong`), the brand (`brand` for text, `brand-solid`,
  `brand-soft`, `brand-line`, `focus`) and one family per tone (`red`,
  `red-soft`, `red-line`, `red-solid`; blue, amber, emerald, violet,
  orange, sky). Templates and controllers write `bg-surface text-muted`,
  never a step of the palette (`bg-white`, `text-gray-500`): those would
  stay the same in the dark theme, and `PaletteClassesTest` refuses them.
  Slate is the colour of what is dark in both themes (the header, code
  blocks); white text sits on solid fills. A new role comes with both
  values and its pairs in `ColourRolesTest`, which checks the contrast of
  each text on each ground it is written on, in both themes. Markdown
  (`.prose`), fields left without classes (`@tailwindcss/forms`) and the
  workflow diagram (`MermaidDiagram` names the roles, the
  `workflow-diagram` controller resolves them) read the same roles.
  Mails keep their inline light colours.
- **The theme is the system's unless chosen.** `color-scheme` on `<html>`
  picks the side of every `light-dark()`: `light dark` by default, one of
  them when `data-theme` is set. The choice (light, dark, system) is a rail
  of the user menu (`Layout:Header`, the `theme` controller), kept by the
  browser in the `cairn_theme` cookie, not on the account; `base.html.twig`
  renders `data-theme` from it (`theme_choice()`, `Twig/ThemeExtension`),
  so a page never starts in the other theme and the sign-in pages follow
  it too. Turbo keeps the attributes of `<html>` across visits.
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
  getting `attributes.without('name', 'value', 'class')`. The exception
  is the live binding: a `data-model` on a nested component binds that
  component to the parent, not its input, so a form control takes a
  `model` prop and renders it as its control's `data-model`
  (`Ui:Form:Editor`, `Input`, `Combobox`, `Checkbox`, `Select`, `Textarea`, `Ui:Filter:Option`,
  `Search`).
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
  catalogue is at `/storia`, reloaded live by the `storia` service. Its
  snapshots in `tests/storia/snapshots/` run with the test suite; review a
  diff before `castor qa:storia --update`. The bundle has no theme switch: its
  previews follow the system, so the dark theme is seen in Storia by
  switching the system's, or with the DevTools' `prefers-color-scheme`
  emulation; the snapshots hold classes, not colours.
- Symfony forms render through `templates/form/theme.html.twig`, registered
  globally in `config/packages/twig.yaml`: each block (`form_row`,
  `form_widget_simple`, `textarea_widget`, `choice_widget_collapsed`,
  `choice_widget_expanded`, `checkbox_row`, buttons) delegates to a `Ui:Form:*`
  component, so the look of a field lives in one place. Each rendering has a
  `form:` view in `storia/components/formTypes/` (named after the rendering:
  `choice_radio`, `choice_checkboxes`), which Storia renders as `default`,
  `disabled` and `error`. Types the framework lacks live in `src/Form/Type/`
  with their own block prefix (`ToggleType` → `toggle_row`, `toggle_widget`;
  `PickerType`, a choice searched in a list, → `picker_widget`).
- Form controls style their disabled state: `disabled:` utilities on the
  field itself, `has-[:disabled]:` on the label that wraps a checkbox or a
  radio.
- The shell is `Ui:Layout:Shell` (blocks `header`, `sidebar`, `breadcrumb`,
  content) with `Ui:Layout:Sidebar`, `Ui:Layout:Sidebar:Section` and
  `Ui:Layout:Sidebar:Item`: low-level pieces without data. `Layout:Header`,
  `Layout:ProjectNav`, `Layout:AccountNav` and `Layout:AdminNav`, which display data, are PHP-backed components
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
  being written). The components share `Twig/Components/DispatchesCommands`:
  `tryCommand()` dispatches and turns a refusal (domain, access,
  validation) into the `error` the component shows, in its own words
  when the exception is translatable, `announce()` emits the event with
  a toast. They read the signed-in user from
  `Security/Viewer`, as do the layout components and the Twig extensions;
  a live component extends `AbstractController` only when it redirects
  (`Issue:CreateForm`).
  The live components of a page are siblings laid out by the page
  template, never nested in one another: when a parent redraws while an
  element it holds has the focus, the morph replaces the child with its
  empty placeholder (`Issue:Description` and `Issue:Files` share a box in
  `project/issue.html.twig` for that reason).
- **The side panel of the issue page is a stack of cards.** `project/issue.html.twig`
  lays the page out as a grid: the header, then the panel, then the body,
  so that on a phone the properties follow the title; on a wide screen the
  panel takes the right column, sticks under the header and scrolls on its
  own when taller than the screen. `Issue:Properties` opens with the
  tracker, then one row per property, the step first: a property the viewer
  may change is a `Ui:Dropdown` opened from its value, each choice a
  `Ui:Dropdown:Item` that dispatches `change` with the property and the
  value; the menu spans the row so that it opens within the column, and
  the members get a `list-filter` field when they are many. The other
  cards (`Issue:Fields`, `Issue:Links`, `Issue:CodeReferences`,
  `Issue:Subscribers`) are a `Ui:Section`: an icon in a tinted tile, the
  title, a count and, while folded, a one-line summary (the first link,
  the first merge request and its state, the avatars of the subscribers).
  A foldable card is a plain `<details>`, nothing more: Live puts back
  the attributes the browser changed when it redraws a component, so the
  fold the reader chose survives the redraws; code and subscribers start
  folded. A card that must stay open (the fields while editing) passes
  `foldable` false.
- **Toasts have one host.** `Ui:Toaster`, placed once in `base.html.twig`,
  shows the flash messages the page starts with and adds a toast for every
  `toast:show` browser event; a live component sends one with
  `dispatchBrowserEvent('toast:show', ['message' => …])`.
- **Text users write is markdown.** Descriptions and comments are stored
  as CommonMark and edited in `Ui:Form:Editor` (the `editor` controller,
  TipTap from npm), bound to a hidden textarea: a form
  field is `Form/Type/MarkdownType`, a live component passes `model`.
  They are displayed with the `markdown` Twig filter
  (`Markdown/MarkdownRenderer`), never raw: HTML is escaped, unsafe
  links dropped. Two references are written in the text itself:
  `#MD-SPT-12` links an issue, but only for a reader who may see it
  (text otherwise), and `@[Claire Martin](user:12)` mentions a person
  with their current name. The editor suggests both from
  `Controller/Project/SuggestController`, which reuses `SearchIssues`
  and `ListProjectMembers` so that visibility stays written once; a
  template gives an editor its endpoints with `editor_urls(project)`
  (`Twig/EditorExtension`, which `MarkdownType` asks too).
- **Files are attachments, in three states.** Any file up to 10 MB
  (PHP's upload limits are set on the server). A file
  is *pending* while what it goes with is being written (`UploadAttachment`):
  an image pasted in an editor (sent by the editor to
  `/{ws}/{p}/attachments`), a file chosen for a comment or a new issue (a
  live action). Only its uploader sees it. The command that saves the
  text claims it (`IssueHandlerSupport::claimAttachments()`): the files
  sent along and those its markdown points to. `CreateIssue` and
  `UpdateIssue` (its description) give them to the issue, `AddComment` and
  `ApplyTransition` to the comment, so the files of a private comment are
  private. The Files block (`Issue:Files`) adds to the issue directly
  (`AddAttachment`), journaled. A file follows
  what it goes with: who may edit the issue changes its files, who may
  edit the comment changes the comment's.
  `AccessControl::canSeeAttachment()` decides, `ShowIssueHandler` filters
  the same in SQL, compared by `ShowIssueVisibilityTest`.
  `/attachments/{uuid}/{filename}` shows images and makes anything else
  a download. Removing a file (`RemoveAttachment`) deletes it:
  `EventListener/AttachmentFileRemover` takes the file when the row goes.
  Pending files nobody took and files without a row are deleted after a
  day by `Attachment/AttachmentPruner`, a daily Scheduler task
  (`messenger:consume scheduler_default`, the `messenger` service in
  dev, a worker to run in production); `app:attachments:prune --dry-run`
  runs it by hand.
  A file input inside a live component goes through the `file-picker`
  controller, so that Live has registered the files before the action
  sends them; inside a live `<form>`, it stays out of the form.
- **Discreet actions are icon buttons shown on hover.** Editing and
  deleting a title, a description or a comment go through
  `Ui:IconButton` (`pencil`, `trash-2`), in a `group` with the
  `hover-actions` utility: visible on hover and focus, always on touch
  screens. A deletion confirms in place, never through `confirm()`. What
  waits for a choice opens a `Ui:Dialog` rendered by the live component
  while its state says so (`closeAction` names the live action of the
  backdrop, the cross and Escape; the `dialog` controller focuses the
  first field). A `Ui:Dropdown:Item` without `href` is a
  button, for a live action in a menu.
- **A page of a project opens with the same band, its main action at the
  right.** `Ui:PageHeader` draws the title of the page, its count, a
  sentence under it, the actions at its right and the tabs on its bottom
  edge, like the bar of an issue list: the Releases page and every page
  of the configuration (`project/settings/base.html.twig`) use it, so
  "New release", "New tracker", "Add someone", "New rule" and "Link a
  repository" stand in the same place. The bar of an issue list
  (`IssueList:Filters`) opens with it too, borderless, its views as the
  actions. The page of the trackers is the page of the first one, the
  others listed beside it (`/settings` redirects there).
- **Creating: a dialog to name, a page to compose.** A creation opens a
  dialog when its form fits the screen without scrolling (four fields or
  so), has a fixed shape (no rows added or removed, no preview) and is
  filled without reading anything else: a release, a tracker, a
  repository, someone on a project. Otherwise it is a page: an automation
  rule. What was created in a dialog is changed in the same dialog; when
  the thing has a page of its own (a tracker), the creation leads to it.
  A dialog of a page rendered by a controller has its own address (`/new`,
  `/{id}`), which renders the page with it open, and loads over the page
  as it is through the `modal` Turbo frame every page of a project has
  (the `modal` block of `layout/project.html.twig`, empty unless a page
  fills it with `project/_form_dialog.html.twig`): the links that open a
  dialog carry `data-turbo-frame="modal"`, and the address does not
  move. A request made for the frame is answered with that block alone
  (`Controller/OpensDialog::renderWithDialog()`: what only the
  whole page reads is a closure it does not call), so the block reads
  nothing the rest of the page sets. The form names its address as its
  action (the `action` option: an empty one would send it to the page
  behind) and, on a page that may hold another form of the same type,
  has a name of its own (`new_tracker`). It is sent within the frame:
  refused, the dialog alone is drawn again (422); accepted, the page it
  redirects to answers the frame its empty self, flash unread, and the
  `modal-frame` controller visits that page, which shows it up to date
  with its flash, and from where the reader was when it is the page the
  dialog was opened over. Every page a dialog redirects to must answer the frame
  that way. Closing (`closeHref`: the backdrop, the cross, Cancel,
  Escape) empties the frame without a request (`dialog#dismiss`), or
  follows the link when the page was opened at the address of the
  dialog. A live component opens its own dialog from its state; a link
  outside it calls its action through the `live-trigger` controller, its
  address (`?add=1`, which the page passes to the component, then
  dropped from the address bar) opening the page with the dialog
  (`Membership:Grants`).
- **Choosing among many is a `Ui:Picker`, typing with suggestions a
  `Ui:Form:Combobox`.** `Ui:Picker` is a field-like button that opens a
  `Ui:Dropdown` with a search field narrowing the `Ui:Dropdown:Item`s
  it is given (`list-filter`), grouped in `Ui:Picker:Group`s; each item
  calls a live action that records the choice, and the button shows its
  `label`. Everything is in the page, filtered in the browser: enough
  for the accounts, workspaces and projects of an instance. What has no
  bound is searched on the server instead, never cut to a first page:
  with `src` (the `search_url` of a `PickerType`) what is typed goes to
  that address as `q`, and the items it answers, drawn by a template of
  the page that owns them, replace the list (`list-filter` with a `url`);
  it answers a few and says how many more match. The field then has no
  list of choices: `Form/Type/SearchedChoiceLoader` looks up the value
  submitted with a closure of the controller, which answers only what
  the search could have offered. The issue of a budget line is chosen so
  (`BudgetController::issues()`).
  `Ui:Form:Combobox` (the `combobox` controller, `CompanyType` in forms)
  is a text input whose `suggestions` are offered as one types; what
  matches none stays as typed. Neither needs a JavaScript dependency.
- JavaScript is Stimulus only, one small controller per behaviour in
  `assets/controllers/` (`dropdown`, `sidebar`, `drawer`, `dialog`,
  `toast`, `toaster`, `autosubmit`, `autofocus`, `editor`, `file-picker`,
  `workflow-diagram`, `sortable`, `list-filter`, `combobox`, `board`,
  `load-more`, `clipboard`, `picker`, `filters`, `selection`, `fold`, `list-view`, `live-updates`, `theme`, `panels`, `avatar`, `live-trigger`, `modal-frame`, `suggestions`); a heavy one is marked `/* stimulusFetch: 'lazy' */`
  (`editor` with TipTap, `workflow-diagram` with Mermaid, `board` with
  SortableJS) so that Vite
  splits its imports into chunks only fetched on a page that uses it; write a new one when a component needs it, not before. No
  inline scripts.

## Configuration

Roles, permissions outside the workflows, steps, workflows, fields and
link types are rows of the database, in tables named `config_…`, changed
by the administrators under `/admin/configuration`. Trackers, projects,
memberships and releases live in database too, managed elsewhere.
Criticality levels, statuses, permissions, field types and the looks of
an action are fixed enums in code. The forges are not part of it: the
instance token and URL are environment variables (`FORGE_GITLAB_URL`,
`FORGE_GITLAB_TOKEN`), the linked repositories and their secrets live in
database.

- **Everything has a key, fixed once created.** A role, a step, a field,
  a link type and a workflow are identified by a short string (`client`,
  `to_test`), made from the label when they are created
  (`ConfigWriting::key()`) and never changed; an action has one within
  its workflow. It is their primary key, what the data stores (the role
  of an access, the workflow of a tracker, the type of a link, the place
  of an issue, the values of its fields), what the journal, the API and
  the automation rules name. Labels change freely. The entities are
  `Role` and `RolePermission`, `Place`, `Field` (its choices in a JSON
  list), `LinkType`, `Workflow` with `WorkflowPlace`, `WorkflowField`
  and `Transition` (`TransitionSource`, `TransitionRole`): no entity
  holds a collection, each row names its owner. The columns of the data
  that name a role, a workflow or a link type stay plain strings for the
  entities and the queries, and get a foreign key in the schema
  (`EventListener/ConfigurationReferences`), so the database refuses an
  unknown one and the deletion of one in use. The place of an issue has
  none: a key cannot say "a step of the workflow of its tracker".
- **The application reads an immutable definition, never the rows.**
  `Config/DefinitionLoader` is the one place that knows how the tables
  fit together: it builds the `InstanceDefinition` (and its
  `WorkflowDefinition`s, `PlaceDefinition`s…) that everything else asks
  `ConfigRegistry` for. It is read once per request, and per message for
  a worker, without a shared cache, so a change made anywhere is seen by
  every process at its next request. An instance nobody configured has
  an empty definition (`InstanceDefinition::isEmpty()`): no page fails,
  the configuration pages offer the starter configuration. Places are
  declared once for the instance and a workflow lists those it uses, in
  the order of its kanban: a place has one label and one status wherever
  it is used, which is what lets a list across trackers filter and group
  by place. Whoever holds a `WorkflowDefinition` asks the instance for
  what a place says (`InstanceDefinition::place()`, `placesOf()`, or
  `WorkflowProvider`).
- **A change is a command, in force as soon as it ends.** There is no
  draft and no publication: `Message/Command/Config/` has one message
  per change (`CreateRole`, `UpdateRole`, `DeleteRole`,
  `GrantPermission`, `RevokePermission`, the same three for a place, a
  field, a link type, a workflow and a transition, `AddWorkflowPlace`,
  `RemoveWorkflowPlace`), without an actor: only administrators reach
  them. So every command must leave a configuration the application can
  run. The handlers refuse what the data relies on, in their own words
  (`ConfigChangeException`, translatable): a role someone holds or an
  action names, a workflow a tracker of a project or of a template
  follows, a link type links use, a step or a field a workflow uses are
  not deleted; an internal-only permission goes to internal-only roles;
  a role becomes internal-only once no external user holds it; a single
  action leads from one step to another. Then every handler ends with
  `ConfigWriting::settle()`, which writes the change, reads the whole
  configuration again and gives it to `ConfigValidator`: a fault refuses
  the command, whatever the handler forgot to check. A handler removes
  the rows that hang from what it deletes itself, so that the entity
  manager does not go on holding them.
- **Two changes touch issues, and say so.** An issue keeps a copy of the
  status of its place (`Issue::$status`, which the lists sort and filter
  on): `UpdatePlace` gives a new status to the issues standing there
  (`IssueRepository::alignStatus()`), closing those that enter the
  closed status and reopening those that leave it. `RemoveWorkflowPlace`
  takes a step out of a workflow: the issues standing there, in the
  trackers that follow it, go in bulk to the step the command names
  (`moveFromPlace()`), and the command is refused while some stand there
  and no step is named; the actions that led to the step go with it,
  those that started from it start from their other steps or go too.
  The initial step never leaves. Neither writes in the journal of the
  issues nor notifies anybody. The step stays in the instance, deleted
  apart once no workflow uses it (`DeletePlace`). Removing a choice of a
  field is free: an issue that holds it shows the value as stored.
- **A new instance installs its starter configuration, once.**
  `Config/StarterKit` describes it as an `InstanceDefinition` (four
  roles, three workflows, twelve steps, six fields, three link types)
  whose labels come from the `starter` translation catalogue, read in
  the language of the instance when it is installed, then stored as data
  like any other; it also creates the project template "Web project".
  `InstallConfiguration` writes a whole definition into the empty
  tables in one command, checked by `ConfigValidator` first, and refuses
  an instance that has a configuration: nothing is ever compared nor
  applied again, and a later version of the kit changes no instance that
  exists. `bin/console app:config:install` and the button the
  configuration pages show while the instance has none both call
  `StarterKit::install()`. The development data and the tests install
  theirs the same way.
- **The pages are lists arranged in place and dialogs.** One controller
  per subject in `Controller/Admin/` (`RoleController`,
  `WorkflowController`, `PlaceController`, `FieldController` for the
  fields and the link types, `ConfigurationController` for the starter
  configuration and the history), sharing `EditsConfiguration`: a
  refusal is the error of the form of the dialog, or a flash for a
  deletion. A list is the live component `Config:Order` (`kind` says
  which: roles, steps, fields, link types, the steps and the actions of
  a workflow), dragged to reorder, each row leading to its dialog; the
  rights are ticked in `Config:PermissionMatrix`, one command per box;
  the fields a workflow asks for are arranged in `Config:WorkflowFields`.
  The three check `ROLE_ADMIN` themselves: their endpoint is not under
  `/admin`. Dialogs have an address of their own and load in the
  `modal` frame of the administration layout
  (`admin/configuration/_dialog.html.twig`). The page of a workflow
  holds its steps, its actions, its fields, its diagram
  (`Workflow/MermaidDiagram`, `Workflow:Diagram`) and the trackers that
  follow it; the dialog that takes a step out says how many issues stand
  there, asks where they go and lists the actions that go or change.
- **Every change leaves a line.** `ConfigJournal`, called by `settle()`,
  writes a `ConfigChange`: the date, the name of whoever is signed in
  (nobody for the console and the installation) and a sentence in the
  language of the instance (`admin.configuration.history.*`), with the
  names of the time. The History page lists them. It is a trace: there
  is no version to go back to, and no export of a configuration yet.
- **A later version changes the configuration with migrations.** A new
  column, or a permission to give the roles that hold another, is a
  Doctrine migration written in SQL against the `config_…` tables, never
  through the entities. A new `Permission` case is granted to nobody but
  the administrators until a migration or an administrator grants it.

## Conventions

- Code, identifiers, comments, tests, fixtures, commit messages and this
  file are in English. French lives only in the translation catalogues
  (`translations/*.fr.yaml`, the labels of the starter configuration
  in `starter.fr.yaml` among them).
- **"Agency" is not a term of the application.** Cairn is built for one,
  but what it knows is a user's `internal` flag: say *internal* and
  *external* users in code, comments, tests and this file, *Interne* and
  *Externe* in the interface. *Client* stays where it names a role of
  the configuration, a workspace, or fixture data.
- **One language per instance**, set by `CAIRN_LOCALE` (wired to
  `framework.default_locale`). There is no per-user locale: issues are
  written in one language, so the interface follows it. Two kinds of text:
  - Interface strings written in code (menus, buttons, validation and mail
    templates, enum labels such as `PlaceStatus`) go through translation
    keys and `translations/messages.<locale>.yaml`. Keys are named by
    domain (`status.open`, `issue.action.create`), never English sentences.
    No hardcoded labels in Twig or PHP. English is the default language and
    French is shipped too; `CAIRN_LOCALE` picks one per instance.
    `debug:translation fr` lists missing and unused keys (keys built
    from a value, such as `permission.<value>.label`, show as unused).
    Every `Permission` case comes with `permission.<value>.label` and
    `.help` in each catalogue; `PermissionTest` checks it.
  - Content written by the administrators or the users (configuration labels,
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

## Development environment

Development runs in Docker (`compose.yaml`, image built from `Dockerfile`),
driven by Castor (`castor.php` and `.castor/`): Docker with Compose v2 and
Castor are the only things needed on the host. Commands run inside the
`cli` container, never on the host.

- `castor install` builds the image, installs the Composer and npm
  dependencies, creates the dev and test databases, applies the schema,
  loads the development data and starts the stack. `castor start` and
  `castor stop` afterwards; `castor list` shows every task.
- The services: `php` (FrankenPHP, <http://localhost:8000>, with its
  built-in Mercure hub at `/.well-known/mercure`), `cli` (the same image
  doing nothing: where castor runs the console, Composer, npm, the tests
  and the linters, so that the web server restarting never stops them),
  `messenger`
  (`messenger:consume async scheduler_default`: notification mails,
  real-time signals and scheduled tasks, the automation rules that wait
  for time among them), `vite` (the Vite dev server with hot reload, port 5173),
  `storia` (live reload of `/storia`), `database` (PostgreSQL 18) and
  `mailer` (Mailpit, <http://localhost:8025>). `HTTP_PORT`, `VITE_PORT`
  and `MAILPIT_PORT` change the published ports. `compose.yaml` injects
  `DATABASE_URL`, `MAILER_DSN`, `DEFAULT_URI`, `MERCURE_URL` and
  `MERCURE_PUBLIC_URL`; other overrides go in `.env.local`.
- Commands in the container: `castor console <command>` (`bin/console`),
  `castor composer …`, `castor npm …`, `castor sh`. The `messenger`
  worker loads the code once: `castor workers:restart` after changing
  what it runs (`castor workers:watch` does it on every change).
- `castor db:fixtures` resets the dev database and loads
  `src/Story/AppStory` (Foundry): workspaces, projects, trackers, users
  and issues created through the real commands under a mocked clock, with
  nothing drawn at random: two loads give the same database. It installs
  the starter configuration (`Config/StarterKit`, in the language of
  `CAIRN_LOCALE`), whose workflows it is written for.
  Every user's password is `cairn`; `admin@cairn.test` is an
  administrator. The online shop of Maison Dupont (`MD-SPT`) has budgets,
  in days: two open orders, with lines in every status. It then loads
  `src/Story/VolumeStory`, the Natura
  catalogue (`NC-CAT`): 200 issues without threads, to try the lists over
  several pages. Tests load `AppStory` only. Factories live in
  `src/Factory/` and are also meant for tests.
- Configuration: sign in as `admin@cairn.test` and open Administration >
  Configuration to change the roles, the workflows, the steps and the
  fields; History shows what was changed. `castor app:config:install`
  installs the starter configuration into a dev database that has none
  (the fixtures already do).
- Mails land in Mailpit: a comment on a followed issue shows up there
  three minutes later, with whatever else happened on the project in
  between; `castor console app:notifications:send --now` sends it at
  once.
- API: create a token on <http://localhost:8000/account/tokens> (any
  internal user), then `curl -H "Authorization: Bearer cairn_…"
  http://localhost:8000/api/issues?assignee[]=me`; the operations are
  listed on <http://localhost:8000/api/docs>, signed in as an internal
  user.
- MCP: with a token, `claude mcp add --transport http cairn
  http://localhost:8000/mcp --header "Authorization: Bearer cairn_…"`
  (the command is on <http://localhost:8000/account/mcp>), then ask
  Claude Code for an issue by its key.
- Forges: `FORGE_GITLAB_TOKEN` is empty by default, so a linked
  repository shows the URL and secret to paste in GitLab by hand; a token
  in `.env.local` (with `FORGE_GITLAB_URL`) makes the page install the
  webhook itself. To try a delivery without GitLab, POST a payload of
  `tests/Forge/Fixture/` to `/webhook/gitlab` with `X-Gitlab-Event` and
  `X-Gitlab-Token` set to the repository's secret; the `messenger`
  worker records it.
- Automations: every project of the development data has four rules
  (Configuration > Automations) and its manager as referent. Sign in as a
  client and create or comment an issue to see the first two act; `castor
  console app:automations:run` runs those that wait for time at once
  (`--dry-run` counts what is due). Administration > Templates holds
  "Web project", the same three trackers and four rules, to create a
  project with.
- Imports: zip `templates/admin/import/example/` with two files `files/501` and
  `files/502` to get an archive to try, drop it in `var/imports_dev/`
  (`IMPORTS_DIR`) and follow Administration > Imports; the `messenger`
  worker analyses and runs it.
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- Prefer `castor console make:*` with every argument passed up front and
  `--no-interaction`; makers prompt on a terminal by default, which hangs a
  non-interactive shell. If a maker still needs input, hand-write the code.
- Discover, don't guess: `bin/console about`, `debug:router`,
  `debug:container`, `debug:autowiring <name>`, `debug:config <bundle>`,
  `config:dump-reference <bundle>`, `lint:container`, `lint:twig templates/`,
  `lint:yaml config/`. Read the installed source under `vendor/` and the docs
  at https://symfony.com/doc/current/.

## Testing

Functional tests extend `WebTestCase`; handler and service tests extend
`KernelTestCase` and run against a real PostgreSQL database (`app_test`):
`tests/bootstrap.php` builds the test schema from the entities and
`dama/doctrine-test-bundle` rolls every test back. Sequences are not rolled
back, so a test never assumes an id: it reads it from the entity. Tests own
their data: handler tests build what they check with `AccessFixtures` and
`IssueFixtures`, page and component tests load `tests/Scene/ShopScene` (one
shop, its people and ten issues under the starter configuration);
handler tests install the small configuration of
`tests/Config/Fixture/DefinitionFactory` (`KernelHelpers::applyConfiguration()`). API tests extend `tests/Api/ApiTestCase`: the
same scene, a token created through `CreateApiToken`, requests sent with
it as a program would; those of the MCP server extend
`tests/Api/Mcp/McpTestCase`, which sends JSON-RPC messages to `/mcp`. The development story is loaded only by the tests
that compare a PHP rule with its SQL over a rich dataset
(`SearchIssuesVisibilityTest`, `ShowIssueVisibilityTest`,
`ListProjectMembersTest`, `ListVisibleProjectsTest`) and by `AppStoryTest`.
Tests, fixtures and their data are in English, the default locale; the
French catalogue is checked by key (`PermissionTest`), not through the
pages.

Run `castor test`: the whole suite, in parallel with ParaTest, one
process per core. Each process has its own database (`app_test1`…, the
`TEST_TOKEN` suffix of `doctrine.yaml`, created by `tests/bootstrap.php`),
its own folders for the files and the imports, its own application cache
pool and its own locks (`.env.test`, `cache.yaml`): a test that reads or
writes anything else shared between processes, a file under `var/` above
all, will fail one run in ten. With arguments, PHPUnit runs alone, as the
CI does (`castor test --filter IssueListTest`). The Storia snapshots run with the suite; after a
deliberate change of a component, review the diff and rewrite them with
`castor qa:storia --update`. A feature isn't done until it has a test that
exercises it the way a caller would: an HTTP request for a controller, a
command or query dispatched on its bus for a handler. Not just "it didn't
throw".

## Quality tools

`castor qa` runs every check the CI runs (`.github/workflows/ci.yaml`) and
must pass before handing work over:

- `castor cs`: php-cs-fixer with the `@Symfony` and `@Symfony:risky` rulesets
  plus `declare(strict_types=1)` in every file (`--dry-run` to only report).
- `castor phpstan`: PHPStan level 8 with the Symfony and Doctrine
  extensions (`phpstan.dist.neon`). PHPDoc types are not treated as certain,
  so runtime checks on hand-written configuration values stay meaningful.
- `castor lint`: `lint:container`, `lint:twig` and `lint:yaml`.
- `castor lsp`: Symfony Language Tools (`.symfony-lsp.json`), which checks
  routes, services, templates and translation keys against the running
  `cli` container; it downloads the pinned release unless `symfony-lsp`
  is on the `PATH`.
- `castor test`: PHPUnit, Storia snapshots included.

The CI also builds the assets (`npm ci && npm run build`).
