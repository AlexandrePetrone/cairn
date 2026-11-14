# Cairn

Cairn is an issue tracker for a web agency and its clients. One instance holds every client: each client is a workspace grouping its projects, and the people of the agency and of the client work on the same issues, each seeing what their role allows.

- **Issues by category**: each project has its trackers (bug, feature, internal task…), each bound to a workflow whose places and actions are defined in the instance configuration. Clients are asked to choose an action (accept, refuse…), never to move a status.
- **Clients and agency side by side**: private issues, comments and trackers stay internal; roles are granted on a workspace or a project.
- **Lists, kanban and bulk actions**: filters, sorting and grouping kept in the address, a kanban per tracker, a selection page to act on many issues at once.
- **Releases, links, files, mentions**: Markdown descriptions and comments with issue references and mentions, attachments, links between issues, optional releases per project.
- **Notifications by email**: whoever follows an issue receives what they may see of each change, threaded per issue.
- **GitLab integration**: commits and merge requests mentioning an issue key show on the issue (GitHub to come).
- **Import from another tracker**: an export archive (a zip in a documented format, written by a script of your own for Redmine, Jira…) is brought into a project from the administration, with its issues, comments and files, their dates and authors. You match its trackers, statuses, fields and roles with yours, choose which accounts to create, and follow the import; nobody is notified.
- **REST API**: the people of the agency connect their scripts with personal tokens; a program reads and does what its user may.

The interface ships in English and French; an instance runs in one language (`CAIRN_LOCALE`).

## Stack

Symfony 8.1 on PHP 8.4, Doctrine ORM (PostgreSQL, or MariaDB), Messenger for the emails and the scheduled tasks, the Symfony Workflow component for workflows built from the configuration, Twig with Symfony UX (Turbo, Live Components, Stimulus), Tailwind CSS 4 and Vite through [Symfony Reprise](https://github.com/symfony/reprise), [API Platform](https://api-platform.com) for the REST API.

## Installation

How to run Cairn on a server of your own. For development, use the Docker stack described in [Local development](#local-development) instead.

### Requirements

- PHP 8.4 with the `intl`, `pdo_pgsql` (or `pdo_mysql`), `ctype`, `iconv` and `zip` extensions, and Composer
- PostgreSQL 16+ (or MariaDB 10.11+)
- Node.js 22, only to build the assets
- A web server pointing at `public/` (Caddy, FrankenPHP, nginx + PHP-FPM…), with PHP's upload limits allowing 10 MB files (`upload_max_filesize`, `post_max_size`)

### Steps

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                   # assets into public/build/

bin/console doctrine:database:create --if-not-exists
bin/console doctrine:schema:update --force
bin/console app:config:sync               # applies the instance configuration (CAIRN_CONFIG)
bin/console app:user:create               # first administrator
```

Besides the web server, one worker must keep running, under a process manager such as systemd or supervisor. It sends the notification emails and runs the daily tasks (removal of abandoned attachments):

```bash
bin/console messenger:consume async scheduler_default --time-limit=3600
```

### Instance configuration

Roles, permissions, fields, link types and workflows live in a PHP file outside the repository, pointed to by `CAIRN_CONFIG`. Start from [`config/instance.example.php`](config/instance.example.php), or [`config/instance.example.fr.php`](config/instance.example.fr.php) for a French instance. The file is never read at runtime: `app:config:sync` validates it, shows what changes (`--dry-run`) and stores it in the database. Run it again after every change, and after upgrading Cairn: [`UPGRADE.md`](UPGRADE.md) lists what a new version changes in the file.

### Environment

`.env` holds the defaults. Put the real values in `.env.local` (git-ignored):

```dotenv
APP_ENV=prod
APP_SECRET=<random string>
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/cairn?serverVersion=18&charset=utf8"
# Public URL, used in the links of the emails and of the forge webhooks
DEFAULT_URI=https://cairn.example
MAILER_DSN=smtp://user:password@smtp.example.com:587
MAILER_FROM="Cairn <no-reply@cairn.example>"
# Instance configuration file and language (en or fr)
CAIRN_CONFIG=/etc/cairn/instance.php
CAIRN_LOCALE=en
# Where the attachments are stored, outside public/
ATTACHMENTS_DIR=/var/lib/cairn/attachments
# Optional: lets project managers install the GitLab webhooks from Cairn
FORGE_GITLAB_URL=https://gitlab.example
FORGE_GITLAB_TOKEN=<token with the api scope>
# Where the archives to import wait (Administration > Imports)
IMPORTS_DIR=/var/lib/cairn/imports
# Optional: real time, see below
MERCURE_URL=http://127.0.0.1/.well-known/mercure
MERCURE_PUBLIC_URL=https://cairn.example/.well-known/mercure
MERCURE_JWT_SECRET=<random string of 32 characters or more>
MERCURE_JWT_ISSUER=https://cairn.example
```

### Real time (optional)

With a [Mercure](https://mercure.rocks) hub, the issue lists and the kanbans of a project follow what other people change, without reloading. It is off unless `MERCURE_URL` is set: nothing is then published and no page opens a connection.

The hub only carries a signal (an issue of this project changed); each page then reads again what its viewer may see. The worker publishes it, so it must be running. The hub must:

- be reachable by the browsers at `MERCURE_PUBLIC_URL`. FrankenPHP and Caddy have the hub built in (a `mercure` directive, see [`docker/Caddyfile`](docker/Caddyfile)), on the same origin as Cairn; a hub on another origin must allow Cairn's with `cors_origins`;
- trust the tokens signed with `MERCURE_JWT_SECRET` by the issuer `MERCURE_JWT_ISSUER`, for publishing and for subscribing: each page is given a token for the topics it may listen to;
- refuse anonymous subscribers (no `anonymous` directive).

## API

The REST API lives under `/api` and is for internal users. Each user creates personal tokens on `/account/tokens` (user menu, "API tokens"): a token reads only or reads and writes, may expire, and is shown once. A program sends it with every request and acts as its user, with the same rights and the same visibility as in the pages.

```bash
curl -H "Authorization: Bearer cairn_…" "https://cairn.example/api/issues?project=MD-SPT&assignee[]=me"
curl -H "Authorization: Bearer cairn_…" -H "Content-Type: application/ld+json" \
     -d '{"transition": "qualify", "comment": "Reproduced."}' \
     https://cairn.example/api/issues/MD-SPT-12/transitions
```

Projects (`MD-SPT`) and issues (`MD-SPT-12`) are named by their key. An issue lists the `transitions` its user may apply; `GET /api/projects/{key}/trackers` says which fields a new issue needs. Answers are JSON-LD, or plain JSON with `Accept: application/json`. The operations are documented at `/api/docs`, for internal users signed in to Cairn; paste a token in "Authorize" to try them there. A user's tokens are limited, together, to 300 requests a minute.

## Local development

Development happens in Docker, driven by [Castor](https://castor.jolicode.com/getting-started/installation/): Docker with Compose v2 and Castor are the only things to install on the host. PHP, Composer, Node.js and PostgreSQL run in the containers.

### First run

```bash
castor install
```

This builds the PHP image (FrankenPHP), starts the database, installs the Composer and npm dependencies, creates the `dev` and `test` databases, applies the schema and loads the development data, then starts the whole stack.

Cairn is then available at <http://localhost:8000>. Every account of the development data has the password `cairn`:

| Account | Who |
|---|---|
| `admin@cairn.test` | Administrator |
| `thomas.girard@cairn.test` | Project manager of the Maison Dupont and Vallier workspaces |
| `nadia.benali@cairn.test` | Developer |
| `claire.martin@maison-dupont.test` | Client of Maison Dupont |

### The stack

`compose.yaml` runs six services:

| Service | What it does |
|---|---|
| `php` | FrankenPHP serving the application on <http://localhost:8000>, with its built-in Mercure hub for real time |
| `messenger` | The worker: notification emails, real-time signals and daily tasks |
| `vite` | The Vite dev server, with hot reload of the CSS and JavaScript (port 5173) |
| `storia` | Live reload of the component catalogue at <http://localhost:8000/storia> |
| `database` | PostgreSQL 18 |
| `mailer` | Mailpit, catching every email sent: <http://localhost:8025> |

The ports can be changed with `HTTP_PORT`, `VITE_PORT` and `MAILPIT_PORT` (e.g. in the shell, before `castor start`). Other overrides go in `.env.local` as usual.

### Everyday commands

Tasks are grouped by namespace; the most frequent ones have a short alias. `castor list` shows them all.

```bash
# The stack (docker:*)
castor start                    # docker:start, alias up
castor stop                     # docker:stop
castor logs messenger           # docker:logs: php, messenger, vite, storia, database or mailer
castor ps                       # docker:ps
castor docker:restart vite      # one service, or all of them without argument
castor docker:build             # rebuild the PHP image
castor docker:down --volumes    # remove everything, database included

# Inside the php container (app:*)
castor console debug:router     # app:console
castor composer require foo/bar # app:composer
castor npm install foo          # app:npm
castor sh                       # app:sh
castor app:config:sync --dry-run

# Databases (db:*)
castor db:reset                 # apply the entity mapping to the dev and test schemas
castor db:fixtures              # reload the development data
castor db:psql                  # psql on the dev database

# Quality (qa:*)
castor test --filter=IssueListTest   # qa:test
castor cs                       # qa:cs: php-cs-fixer fix (--dry-run to only report)
castor phpstan                  # qa:phpstan
castor lint                     # qa:lint: container, Twig and YAML
castor lsp                      # qa:lsp: Symfony Language Tools
castor qa:storia --update       # rewrite the component snapshots
castor qa                       # qa:all: everything the CI runs

# Assets (assets:*)
castor assets:build             # production build into public/build/
castor assets:clean             # back to the dev server
```

Schema changes go through `doctrine:schema:update` (`castor db:reset`) until the first release, not migrations.

### Quality tools

`castor qa` runs the same checks as the GitHub Actions workflow (`.github/workflows/ci.yaml`): php-cs-fixer (`@Symfony` and `@Symfony:risky`), PHPStan level 8, the Symfony linters, [Symfony Language Tools](https://github.com/symfony/language-tools) and PHPUnit, including the snapshots of the component catalogue. The tests run against PostgreSQL in the `test` database.

### Code changes and the worker

FrankenPHP, Vite and the Storia watcher pick up changes immediately. The `messenger` worker loads the code once: after changing something it uses, reload it.

```bash
castor workers:restart    # messenger:stop-workers; compose starts it again
castor workers:watch      # the same, automatically, on every change
```

### Trying the GitLab integration

Without `FORGE_GITLAB_TOKEN`, a repository linked in a project's settings shows the webhook URL and secret to paste in GitLab by hand. To try a delivery without GitLab, post a payload of `tests/Forge/Fixture/` to `/webhook/gitlab` with the `X-Gitlab-Event` and `X-Gitlab-Token` headers (the repository's secret); the `messenger` worker records it.

### Debugging

- `var/log/dev.log` and the web profiler at <http://localhost:8000/_profiler>.
- Xdebug is installed but off; start the stack with `XDEBUG_MODE=debug castor start` and let the IDE listen on port 9003.
