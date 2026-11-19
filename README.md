# Cairn

Cairn is an issue tracker for a web agency and its clients. One instance holds every client: each client is a workspace grouping its projects, and the people of the agency and of the client work on the same issues, each seeing what their role allows.

![The issues of a project, with their category, step, criticality and assignee](docs/screenshots/issues.jpg)

| An issue | The kanban of a category |
|---|---|
| ![An issue: description, files, activity, fields and links](docs/screenshots/issue.jpg) | ![The kanban of the bugs of a project, one column per step](docs/screenshots/kanban.jpg) |

- **Issues by category**: each project has its trackers (bug, feature, internal task…), each bound to a workflow whose places and actions are defined in the instance configuration. Clients are asked to choose an action (accept, refuse…), never to move a status.
- **Clients and agency side by side**: private issues, comments and trackers stay internal; roles are granted on a workspace or a project.
- **Lists, kanban and bulk actions**: filters, sorting and grouping kept in the address, a kanban per tracker, a selection page to act on many issues at once.
- **Releases, links, files, mentions**: Markdown descriptions and comments with issue references and mentions, attachments, links between issues, optional releases per project.
- **Notifications by email**: whoever follows an issue receives what they may see of each change, threaded per issue.
- **GitLab integration**: commits and merge requests mentioning an issue key show on the issue (GitHub to come).
- **Import from another tracker**: an export archive (a zip in a documented format, written by a script of your own for Redmine, Jira…) is brought into a project from the administration, with its issues, comments and files, their dates and authors. You match its trackers, statuses, fields and roles with yours, choose which accounts to create, and follow the import; nobody is notified.
- **Automations**: per project, rules made of a trigger (an issue created, a comment, an action, a step reached, a change of criticality, no activity for some time), conditions and actions (assign, move, set the criticality, subscribe, comment, make private), from closed lists: no script.
- **Budgets**: an amount of time a client committed to on a project, in days or hours, debited by lines written by hand; a line counts once approved, and the client reads the same balance as the team.
- **Project templates**: the trackers and the rules of a template are copied into a new project.
- **Single sign-on**: optionally, an OpenID Connect provider (Google, Entra, Keycloak…) signs in the accounts an administrator chose; the others keep their password.
- **REST API and MCP server**: the people of the agency connect their scripts and their AI assistants with personal tokens; a program reads and does what its user may.

The interface ships in English and French; an instance runs in one language (`CAIRN_LOCALE`).

## Stack

Symfony 8.2 on PHP 8.5, Doctrine ORM (PostgreSQL, or MariaDB), Messenger for the emails and the scheduled tasks, the Symfony Workflow component for workflows built from the configuration, Twig with Symfony UX (Turbo, Live Components, Stimulus), Tailwind CSS 4 and Vite through [Symfony Reprise](https://github.com/symfony/reprise), [API Platform](https://api-platform.com) for the REST API.

## Status

Cairn has no release yet, and is not meant for production until it has one:

- The database schema is derived from the entities (`doctrine:schema:update`) and may change at any commit, without a migration: there is no upgrade path, and an instance installed today may have to be created again. Migrations start with the first release.
- Symfony 8.2 is still a beta: until its stable release, Cairn installs beta versions of the framework (`minimum-stability: beta` in `composer.json`).

## Installation

How to run Cairn for real: from its Docker image, or on a server of your own. For development, use the Docker stack described in [Local development](#local-development) instead.

### With Docker

The image `ghcr.io/julienj/cairn` holds the application, its dependencies and the built assets, served by FrankenPHP. The same image is the web server and the worker; it runs on PostgreSQL or MariaDB, on amd64 and arm64. [`compose.prod.yaml`](compose.prod.yaml) is an example to start from: the web server, the worker and PostgreSQL.

Put it in a folder, with a `.env` file next to it:

```dotenv
APP_SECRET=<random string, e.g. openssl rand -hex 32>
MERCURE_JWT_SECRET=<another one, of 32 characters or more>
POSTGRES_PASSWORD=<password of the database>
DEFAULT_URI=https://cairn.example.com
SERVER_NAME=cairn.example.com
TZ=Europe/Paris
```

`TZ` is the timezone of the instance (UTC without it).

Then start it, create the schema and the first administrator:

```bash
docker compose -f compose.prod.yaml up -d
docker compose -f compose.prod.yaml exec web bin/console doctrine:schema:update --force
docker compose -f compose.prod.yaml exec web bin/console app:user:create
```

The worker restarts until the schema is there. Sign in: the administration offers to install the starter configuration.

**HTTPS.** With a domain name as `SERVER_NAME`, the web server gets its certificate by itself; the ports 80 and 443 must be reachable from the Internet. Behind a proxy that holds the certificate instead: `SERVER_NAME=:80`, `SYMFONY_TRUSTED_PROXIES=private_ranges` and `MERCURE_URL=http://web/.well-known/mercure`. The same three, with `DEFAULT_URI=http://localhost:8080` and `HTTP_PORT=8080`, try it on a laptop.

**Updating.** `docker compose pull`, `up -d`, then `doctrine:schema:update --force` again. The image never writes to the database by itself: the schema is always your command. `edge` follows the main branch and `0.x.y` is a release; there is no `latest`. Until 1.0 the schema has no migrations: `schema:update` adds what a version needs, but cannot rename nor convert, so back up before updating.

**Data.** Two things to back up: the database, and the `/data` volume (the attachments, the import archives, the certificates). The containers keep nothing else: sessions, locks and the shared cache are in the database, logs go to the standard output.

**Configuration.** Every variable of [Environment](#environment) is an environment variable of both containers; `compose.prod.yaml` lists the usual ones. Real time is on, through the hub built into the web server; for another hub give its addresses, in full, as `MERCURE_URL` and `MERCURE_PUBLIC_URL`, and for none an empty `MERCURE_URL`. For MariaDB, replace the `database` service and give a `DATABASE_URL` such as `mysql://user:password@database:3306/cairn?serverVersion=11.4.2-MariaDB&charset=utf8mb4`.

**Without Compose.** The image runs as an unprivileged user, on a read-only root filesystem if `/data` and `/tmp` are writable, and listens on port 8080 over plain HTTP unless `SERVER_NAME` says otherwise. `/healthz` answers without PHP nor the database. The worker is the same image with the command `bin/console messenger:consume async scheduler_default --time-limit=3600`; it shares `/data` with the web server and finishes its message when asked to stop. The built-in hub serves one web container: several need a hub of their own.

### On a server

#### Requirements

- PHP 8.5 with the `intl`, `pdo_pgsql` (or `pdo_mysql`), `ctype`, `iconv` and `zip` extensions, and Composer
- PostgreSQL 16+ (or MariaDB 10.11+)
- Node.js 22, only to build the assets
- A web server pointing at `public/` (Caddy, FrankenPHP, nginx + PHP-FPM…), with PHP's upload limits allowing 10 MB files (`upload_max_filesize`, `post_max_size`)

#### Steps

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                   # assets into public/build/

bin/console doctrine:database:create --if-not-exists
bin/console doctrine:schema:update --force
bin/console app:config:install            # starter configuration: roles, workflows, steps, fields
bin/console app:user:create               # first administrator
```

Besides the web server, one worker must keep running, under a process manager such as systemd or supervisor. It sends the notification emails and runs the daily tasks (removal of abandoned attachments):

```bash
bin/console messenger:consume async scheduler_default --time-limit=3600
```

### Instance configuration

Roles and their rights, workflows, steps, fields and link types live in the database and are managed by the administrators, under Administration > Configuration. `app:config:install` gives a new instance a starter configuration, in the language of `CAIRN_LOCALE`: four roles, three workflows (bug, feature, internal task) and a project template to create the first project with. It is installed once: everything it gave is then changed from the administration, and running the command again changes nothing. An instance may also start empty and be configured by hand.

### Environment

`.env` holds the defaults. Put the real values in `.env.local` (git-ignored); with Docker they are the environment variables of the containers:

```dotenv
APP_ENV=prod
APP_SECRET=<random string>
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/cairn?serverVersion=18&charset=utf8"
# Public URL, used in the links of the emails and of the forge webhooks
DEFAULT_URI=https://cairn.example
# Behind a reverse proxy or a load balancer: without them every request
# comes from the proxy, so sign-in throttling counts everyone as one
# address, and HTTPS is not seen
SYMFONY_TRUSTED_PROXIES=private_ranges
SYMFONY_TRUSTED_HEADERS=x-forwarded-for,x-forwarded-proto,x-forwarded-host,x-forwarded-port
MAILER_DSN=smtp://user:password@smtp.example.com:587
MAILER_FROM="Cairn <no-reply@cairn.example>"
# Language of the instance (en or fr)
CAIRN_LOCALE=en
# Where the attachments are stored, outside public/
ATTACHMENTS_DIR=/var/lib/cairn/attachments
# Optional: lets project managers install the GitLab webhooks from Cairn
FORGE_GITLAB_URL=https://gitlab.example
FORGE_GITLAB_TOKEN=<token with the api scope>
# Where the archives to import wait (Administration > Imports)
IMPORTS_DIR=/var/lib/cairn/imports
# Optional: single sign-on, see below
OIDC_PROVIDER_URI=https://accounts.google.com
OIDC_CLIENT_ID=<client id>
OIDC_CLIENT_SECRET=<client secret>
OIDC_NAME=Google
# Optional: real time, see below
MERCURE_URL=http://127.0.0.1/.well-known/mercure
MERCURE_PUBLIC_URL=https://cairn.example/.well-known/mercure
MERCURE_JWT_SECRET=<random string of 32 characters or more>
MERCURE_JWT_ISSUER=https://cairn.example
```

### Single sign-on (optional)

An instance may have one OpenID Connect provider. It is off unless `OIDC_PROVIDER_URI` (the issuer) is set: no page then offers it and `/login/sso` does not exist. Register Cairn at the provider with the redirect URI `DEFAULT_URI` + `/login/sso/callback`, over HTTPS; `OIDC_NAME` is how the button of the sign-in page names the provider.

How an account signs in is chosen on its page of the administration, one way or the other: an account of the provider holds no password and gets no password link. Nobody is created at sign-in and no claim grants a role: the provider signs in the accounts that exist, found the first time by the email it vouches for. The provider must send `email_verified: true` with that email: one that leaves the claim out (Entra ID does) binds no account. Signing out is local, and someone who left the directory keeps their session and API tokens until their account is deactivated in Cairn.

### Real time (optional)

With a [Mercure](https://mercure.rocks) hub, the issue lists and the kanbans of a project follow what other people change, without reloading. It is off unless `MERCURE_URL` is set: nothing is then published and no page opens a connection.

The hub only carries a signal (an issue of this project changed); each page then reads again what its viewer may see. The worker publishes it, so it must be running. The hub must:

- be reachable by the browsers at `MERCURE_PUBLIC_URL`. FrankenPHP and Caddy have the hub built in (a `mercure` directive, see [`docker/Caddyfile`](docker/Caddyfile)), on the same origin as Cairn; a hub on another origin must allow Cairn's with `cors_origins`;
- trust the tokens signed with `MERCURE_JWT_SECRET` by the issuer `MERCURE_JWT_ISSUER`, for publishing and for subscribing: each page is given a token for the topics it may listen to;
- refuse anonymous subscribers (no `anonymous` directive).

### Operations

**The worker.** A systemd unit that keeps it running; `--time-limit` makes it leave every hour, and systemd starts it again with fresh code and memory:

```ini
# /etc/systemd/system/cairn-worker.service
[Unit]
Description=Cairn worker (emails, scheduled tasks)
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/cairn
ExecStart=/usr/bin/php bin/console messenger:consume async scheduler_default --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

After deploying new code, `bin/console messenger:stop-workers` makes it restart.

**Backups.** Two things hold the data, and they go together: the database, and the folder of `ATTACHMENTS_DIR` (the files sent with issues and comments, and the pictures of the accounts). A file missing from a backup leaves an attachment that cannot be downloaded. `IMPORTS_DIR` only holds archives already imported or waiting to be.

**HTTPS.** Serve Cairn over HTTPS only: sessions, password links and API tokens travel with every request. Cairn sets the headers that depend on it (no framing, no content sniffing, referrer policy); `Strict-Transport-Security` belongs to the web server, which knows whether every subdomain is ready for it. Caddy and FrankenPHP redirect to HTTPS by themselves; with nginx:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
```

## API

The REST API lives under `/api` and is for internal users. Each user creates personal tokens on `/account/tokens` (user menu, "API tokens"): a token reads only or reads and writes, may expire, and is shown once. A program sends it with every request and acts as its user, with the same rights and the same visibility as in the pages.

```bash
curl -H "Authorization: Bearer cairn_…" "https://cairn.example/api/issues?project=MD-SPT&assignee[]=me"
curl -H "Authorization: Bearer cairn_…" -H "Content-Type: application/ld+json" \
     -d '{"transition": "qualify", "comment": "Reproduced."}' \
     https://cairn.example/api/issues/MD-SPT-12/transitions
```

Projects (`MD-SPT`) and issues (`MD-SPT-12`) are named by their key. An issue lists the `transitions` its user may apply; `GET /api/projects/{key}/trackers` says which fields a new issue needs. Answers are JSON-LD, or plain JSON with `Accept: application/json`. The operations are documented at `/api/docs`, for internal users signed in to Cairn; paste a token in "Authorize" to try them there. A user's tokens are limited, together, to 300 requests a minute.

### MCP server

The same tokens open an [MCP](https://modelcontextprotocol.io) server at `/mcp` (over HTTP, the token in the `Authorization: Bearer` header), for an AI assistant to work on the issues as its user: `search_issues`, `get_issue`, `get_project` and `get_attachment` read; `create_issue`, `update_issue`, `move_issue`, `change_tracker` and `add_comment` write and need a token that may. A comment written through it is private unless the assistant is asked to answer the client.

## Local development

Development happens in Docker, driven by [Castor](https://castor.jolicode.com/getting-started/installation/): Docker with Compose v2 and Castor are the only things to install on the host. PHP, Composer, Node.js and PostgreSQL run in the containers.

### First run

```bash
castor install
```

This builds the PHP image (FrankenPHP), starts the database, installs the Composer and npm dependencies, creates the `dev` and `test` databases, applies the schema and loads the development data, then starts the whole stack.

Cairn is then available at <http://localhost:8000>.

### Test accounts

`castor db:fixtures` reloads them with the rest of the development data. Every account signs in with its email and the password `cairn`, except Léa, who signs in through the identity provider (see [Single sign-on](#single-sign-on)).

Internal users:

| Account | Who | Where |
|---|---|---|
| `admin@cairn.test` | Camille Vasseur, administrator | Everything, and the administration |
| `thomas.girard@cairn.test` | Thomas Girard, project manager | Maison Dupont (`MD`) and Vallier Group (`GV`) |
| `yann.leroy@cairn.test` | Yann Leroy, project manager | Natura Cosmetics (`NC`) and Travelia (`TR`, archived) |
| `nadia.benali@cairn.test` | Nadia Benali, developer | Online shop (`MD-SPT`), Mobile app (`GV-APP`) |
| `lea.fontaine@cairn.test` | Léa Fontaine, developer, **single sign-on** | Online shop (`MD-SPT`), Supplier portal (`GV-PRT`), Back-office (`NC-BO`) |
| `marc.petit@cairn.test` | Marc Petit, developer | Intranet (`MD-INT`), Supplier portal (`GV-PRT`), Booking site (`TR-RES`) |

External users (clients):

| Account | Who | Where |
|---|---|---|
| `claire.martin@maison-dupont.test` | Claire Martin, client | Maison Dupont |
| `paul.roussel@maison-dupont.test` | Paul Roussel, client, read-only | Maison Dupont |
| `ines.garnier@vallier.test` | Inès Garnier, client | Vallier Group |
| `remi.colin@natura.test` | Rémi Colin, client | Natura Cosmetics |
| `sophie.bernard@travelia.test` | Sophie Bernard, client | Travelia (archived) |

The online shop of Maison Dupont (`MD-SPT`) is the richest project: releases, budgets, issues in every step. To see what a client sees next to what the team sees, open it as Claire and as Thomas.

### Single sign-on

The stack runs [Authelia](https://www.authelia.com) as the identity provider of the instance (`OIDC_*` in `.env.dev`): the sign-in page has a "Sign in with Authelia" button. It knows two people, both with the password `cairn`:

| Authelia user | Email | In Cairn |
|---|---|---|
| `lea` | `lea.fontaine@cairn.test` | Léa Fontaine, the one account told to sign in that way: she gets in |
| `hugo` | `hugo.marchand@cairn.test` | No account: refused on the sign-in page |

Authelia is served at <https://authelia.localhost:9443> with a certificate of the stack's own authority, so the browser warns the first time: accept it. To make another account sign in that way, change its sign-in method on its page of the administration and add a user with the same email to `docker/authelia/users_database.yml` (`castor docker:restart authelia`). The people and the client are declared in [`docker/authelia/`](docker/authelia/).

### The stack

`compose.yaml` runs seven services:

| Service | What it does |
|---|---|
| `php` | FrankenPHP serving the application on <http://localhost:8000>, with its built-in Mercure hub for real time |
| `messenger` | The worker: notification emails, real-time signals and daily tasks |
| `vite` | The Vite dev server, with hot reload of the CSS and JavaScript (port 5173) |
| `storia` | Live reload of the component catalogue at <http://localhost:8000/storia> |
| `database` | PostgreSQL 18 |
| `mailer` | Mailpit, catching every email sent: <http://localhost:8025> |
| `authelia` | The identity provider of the single sign-on, at <https://authelia.localhost:9443> through the `php` service |

The ports can be changed with `HTTP_PORT`, `VITE_PORT` and `MAILPIT_PORT` (e.g. in the shell, before `castor start`). Other overrides go in `.env.local` as usual.

### Everyday commands

Tasks are grouped by namespace; the most frequent ones have a short alias. `castor list` shows them all.

```bash
# The stack (docker:*)
castor start                    # docker:start, alias up
castor stop                     # docker:stop
castor logs messenger           # docker:logs: php, messenger, vite, storia, database, mailer or authelia
castor ps                       # docker:ps
castor docker:restart vite      # one service, or all of them without argument
castor docker:build             # rebuild the PHP image
castor docker:down --volumes    # remove everything, database included

# Inside the cli container (app:*)
castor console debug:router     # app:console
castor composer require foo/bar # app:composer
castor npm install foo          # app:npm
castor sh                       # app:sh
castor app:config:install       # starter configuration, into a database that has none

# Databases (db:*)
castor db:reset                 # apply the entity mapping to the dev and test schemas
castor db:fixtures              # reload the development data
castor db:psql                  # psql on the dev database

# Quality (qa:*)
castor test                          # qa:test: the whole suite, in parallel
castor test --filter=IssueListTest   # PHPUnit alone, with its arguments
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

FrankenPHP runs in worker mode: the kernel stays in memory between requests, and its workers start again by themselves when a file of `src/`, `config/`, `translations/`, an `.env` file or `composer.lock` changes (`FRANKENPHP_WORKER_CONFIG` in [`compose.yaml`](compose.yaml)). Vite and the Storia watcher pick up changes immediately. The `messenger` worker loads the code once: after changing something it uses, reload it.

```bash
castor workers:restart    # messenger:stop-workers; compose starts it again
castor workers:watch      # the same, automatically, on every change
```

### Trying the GitLab integration

Without `FORGE_GITLAB_TOKEN`, a repository linked in a project's settings shows the webhook URL and secret to paste in GitLab by hand. To try a delivery without GitLab, post a payload of `tests/Forge/Fixture/` to `/webhook/gitlab` with the `X-Gitlab-Event` and `X-Gitlab-Token` headers (the repository's secret); the `messenger` worker records it.

### Debugging

- `var/log/dev.log` and the web profiler at <http://localhost:8000/_profiler>.
- Xdebug is installed but off; start the stack with `XDEBUG_MODE=debug castor start` and let the IDE listen on port 9003.

## License

Cairn is released under the [MIT License](LICENSE).
