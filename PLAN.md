# Implementation plan

Source: [NOTES.md](NOTES.md). Tasks are done in phase order, within a phase — top to bottom. Mark `[x]` as they get done. Each task is an atomic step verifiable in a few minutes. `[S]`/`[C]` is the task's tier (simple/complex): it decides who does it, see CLAUDE.md, "Model selection".

## Architecture (brief)

- A self-hosted product, not SaaS: `git clone` + `docker compose up`. A single user. Target deployment — a home server, accessed from a phone via an already running Cloudflare Tunnel (`jobhunter.madbugs.dev`); the server is exposed to the internet — HTTPS and protection of the login/API are mandatory.
- PHP 8.5, Symfony 7.4, PostgreSQL, Doctrine ORM, EasyAdmin (settings and reference data), custom Twig pages on Symfony UX — Turbo/Stimulus via AssetMapper, without Node.js (job posting work screens, convenient on a phone), API Platform (job posting intake), Symfony Messenger on the Doctrine transport (queue in PostgreSQL, no RabbitMQ). Web server — FrankenPHP (Caddy + PHP in one container, Symfony in worker mode), plain HTTP behind Cloudflare Tunnel. Everything in Docker.
- The LLM is configured in the UI: provider, a model per task (CV analysis / prompt generation / matching), API key. Adapters behind `LlmClientInterface`, the first one — Gemini.
- Flow: Claude Code manually scans a platform → POST to an API Platform endpoint (protected by a key from the settings) → `JobPosting` with status `raw` → a message to Messenger → worker: keyword filter → (if passed) LLM matching using the platform prompt → result. Every transition is logged in `JobPostingStageLog`.
- Deliberately not now: see "Backlog".

## Phase 0 — Repository and environment

Docker: `.docker/` + `compose.yaml` + Makefile, designed for installation from a fresh clone.

- [x] [S] `git init`
- [x] [S] `.gitignore`: `.idea/`, `.DS_Store` (Flex will add the Symfony blocks when creating the skeleton)
- [x] [S] `.editorconfig`
- [x] [S] `.docker/php/Dockerfile`: the official FrankenPHP image `dunglas/frankenphp:1-php8.5-alpine`, extensions via `install-php-extensions` (`pdo_pgsql`, `intl`, `apcu`; OPcache is built into PHP 8.5), composer, user via `USER`/`USERID`
- [x] [S] `.docker/php/php.ini` — in the repository (not in `.gitignore`, otherwise the build from a fresh clone fails)
- [x] [S] `.docker/php/Caddyfile` — in the repository, plain HTTP (HTTPS is terminated by Cloudflare Tunnel), the admin endpoint stays on `localhost:2019` (the image's healthcheck depends on it)
- [x] [S] `compose.yaml`: `php` service (runs FrankenPHP, publishes `APP_PORT`), built from the repository root: `context: .` + `dockerfile: .docker/php/Dockerfile`
- [x] [S] `compose.yaml`: `postgres` service with a healthcheck, port not published in `compose.yaml`, `depends_on` with `condition: service_healthy`
- [x] [S] `compose.override.yaml` (dev only): Postgres port on `127.0.0.1:POSTGRES_PORT` for a local DB client
- [x] [S] Docker variables template in `.env` (`APP_NAME`, `APP_PORT`, `APP_USER`, `APP_USERID`, DB credentials, `POSTGRES_PORT`), defaults `APP_USER=app`, `APP_USERID=1000`
- [x] [S] Makefile: `init` (creates `.env.local` from the template if it doesn't exist, filling `APP_USERID` with the host's `id -u` and stopping with a clear error if it is 0; up; composer install), `upd`, `updb` (up with a rebuild), `down`, `ps`, `in`
- [x] [S] Remove the "once they exist" clause about Makefile targets from the "Running commands" section of `CLAUDE.md` (the targets now exist)
- [x] [S] Create a Symfony 7.4 skeleton inside the container
- [x] [S] Enable FrankenPHP worker mode for Symfony
- [x] [S] Verify: `make init` from a fresh clone succeeds, the start page opens in the browser

## Phase 1 — Dev tooling

Goal — as many automated checks as possible, so that errors are caught without manual review.

- [x] [S] Install `symfony/maker-bundle`
- [x] [S] Install PHPUnit 12, `phpunit.dist.xml` (`failOnWarning`, `failOnDeprecation`), one smoke test green
- [ ] [S] Install PHPStan, `phpstan.dist.neon` at level 10
- [ ] [S] Install `phpstan/phpstan-strict-rules`
- [ ] [S] Install `phpstan/phpstan-deprecation-rules`
- [ ] [S] Install `phpstan/phpstan-symfony`
- [ ] [S] Install PHP-CS-Fixer, a single config `.php-cs-fixer.dist.php` (`@Symfony` + `declare_strict_types` + `blank_line_before_statement` for `return` + `return_assignment`)
- [ ] [S] Install `squizlabs/php_codesniffer` with only the `Generic.Files.LineLength` rule (120), no other standards — CS-Fixer doesn't check line length
- [ ] [S] Install Rector, config for PHP 8.5 + Symfony code quality
- [ ] [S] Install `ergebnis/composer-normalize`, normalize composer.json
- [ ] [S] Install `symfony/browser-kit` + `symfony/css-selector`
- [ ] [S] Install `fakerphp/faker`
- [ ] [S] Install GrumPHP, `grumphp.yml`: composer, composer_normalize, composer audit, PHPStan, PHP-CS-Fixer, PHPCS (line length), Rector (dry-run), PHPUnit, Symfony linters (`lint:yaml`, `lint:container`, `lint:twig`)
- [ ] [S] Add `git` to the `php` image (GrumPHP calls git inside the container)
- [ ] [S] GrumPHP: run checks via `docker compose exec` (git on the host, PHP in the container)
- [ ] [S] Verify: a commit with a deliberate error is blocked, a clean one passes
- [ ] [S] Remove the "once they are installed" clause from the GrumPHP item in `.claude/agents/developer.md` (all checks now actually work)

## Phase 2 — Bundles

- [ ] [S] Doctrine ORM + Migrations, connection to PostgreSQL (`doctrine:database:create` succeeds); add `doctrine:migrations:migrate` to `make init`
- [ ] [S] Install `phpstan/phpstan-doctrine`
- [ ] [S] Install `doctrine/doctrine-fixtures-bundle`
- [ ] [S] Symfony Messenger + `symfony/doctrine-messenger`, `async` transport in PostgreSQL
- [ ] [S] `worker` service in `compose.yaml` (`messenger:consume async`, the same image as `php`, `healthcheck` disabled: there is no Caddy in it)
- [ ] [S] Symfony Security
- [ ] [S] EasyAdminBundle
- [ ] [S] AssetMapper
- [ ] [S] Symfony UX Turbo
- [ ] [S] Symfony UX Stimulus
- [ ] [S] Base layout for custom pages (responsive, shared, with navigation to the admin)
- [ ] [S] API Platform

## Phase 3 — User and login

- [ ] [S] `User` entity (email, password hash) + migration
- [ ] [S] Console command to create a user
- [ ] [S] Form login, the whole UI is behind the login
- [ ] [S] `login_throttling` — brute-force protection for the login
- [ ] [S] Empty EasyAdmin dashboard, opens after login

## Phase 4 — LLM settings

- [ ] [S] `LlmProvider` enum (only Gemini for now)
- [ ] [S] `LlmTask` enum (CV analysis / prompt generation / matching)
- [ ] [S] `LlmOperationStatus` enum (pending / done / failed): the state of an LLM call started from the UI and running in Messenger
- [ ] [S] Provider key entity (provider → apiKey) + migration
- [ ] [S] Per-task model selection entity (task → provider + model) + migration
- [ ] [C] Encryption of LLM API keys in the DB (`sodium`, encryption key in `.env.local`)
- [ ] [S] EasyAdmin CRUD for keys, the key is shown masked
- [ ] [S] EasyAdmin CRUD for model selection
- [ ] [C] `LlmClientInterface` + request/response DTOs
- [ ] [C] `GeminiClient`: text request
- [ ] [C] `GeminiClient`: structured output via JSON schema
- [ ] [C] Resolver "task → client + model" from the settings
- [ ] [S] Console command to check a key (test request to the model)

## Phase 5 — Platforms and skills

- [ ] [S] Platform access type enum
- [ ] [S] `Platform` entity + migration
- [ ] [S] Fixture: Upwork, LinkedIn, Glassdoor, Indeed
- [ ] [S] `Skill` entity + migration

## Phase 6 — CV: upload and analysis

- [ ] [C] `CvProfile` entity (original file, analysis status (`LlmOperationStatus`), analysis in the original language, analysis in English, `yearsExperience`, `seniority`, `salaryMin/Max/Currency`, raw LLM response) + migration
- [ ] [S] `CvLanguage` entity (one-to-many) + migration
- [ ] [S] `CvProfile` ↔ `Skill` relation (many-to-many) + migration
- [ ] [C] CV upload form (PDF only, form limit ≤ 10M: PHP's `upload_max_filesize`), saving the original file
- [ ] [C] CV analysis JSON schema + validation via `justinrainbow/json-schema`
- [ ] [C] CV analysis service: PDF directly into the LLM call (no text extraction) → validation → saving to `CvProfile`/`CvLanguage`/`Skill`
- [ ] [S] CV upload dispatches the analysis message; the Messenger handler runs the analysis service and sets the status (done / failed)
- [ ] [S] Analysis view page: shows the status, the Turbo frame refreshes until the analysis is done or failed

## Phase 7 — Per-platform prompts

- [ ] [S] Prompt source enum (generated / manually edited)
- [ ] [S] `Prompt` entity (platform, text, source, generation status) + migration
- [ ] [C] Generating a draft prompt from `CvProfile` for a platform (LLM call in a Messenger handler)
- [ ] [S] "Generate prompt" button: dispatches the message, the Turbo frame refreshes until the draft is ready
- [ ] [S] Manual prompt editing in EasyAdmin
- [ ] [S] `platforms/upwork.md`
- [ ] [S] `platforms/linkedin.md`
- [ ] [S] `platforms/glassdoor.md`
- [ ] [S] `platforms/indeed.md`

## Phase 8 — Job posting domain

- [ ] [C] `JobPosting` pipeline status enum
- [ ] [C] `JobPosting` entity with transition methods that protect invariants + migration
- [ ] [C] Unit tests for status transitions (including forbidden ones); delete the placeholder `tests/KernelBootTest.php`, real tests now cover the PHPUnit setup
- [ ] [S] `JobPostingStageLog` entity + migration
- [ ] [C] Domain events for transitions → written to `JobPostingStageLog`

## Phase 9 — Job posting intake (API Platform)

- [ ] [S] Generating/regenerating the API key in the settings
- [ ] [C] API key authenticator (header)
- [ ] [S] Rate limiter on the job posting intake endpoint
- [ ] [C] POST endpoint for creating a `JobPosting` (DTO with Assert)
- [ ] [C] Dispatching a message to Messenger on creation
- [ ] [S] Verify the endpoint with curl

## Phase 10 — Processing (Messenger pipeline)

- [ ] [S] Message class + empty handler
- [ ] [C] Keyword filter by `Skill` + edge case tests
- [ ] [C] Wire the keyword filter into the handler (rejection finalizes the status)
- [ ] [C] LLM matching using the platform `Prompt`
- [ ] [C] Wire LLM matching into the handler (like/dislike + reason)

## Phase 11 — Results UI (custom pages, not EasyAdmin)

- [ ] [S] Job posting list as cards (not a table), responsive for phones
- [ ] [S] List filters: platform, status, like/dislike, applied
- [ ] [S] Job posting card: description, LLM rating with reason
- [ ] [S] Job posting card: pipeline history (`JobPostingStageLog`)
- [ ] [C] Manually override the LLM like/dislike decision in the job posting card (via Turbo, without a reload)
- [ ] [S] User comment on a job posting (saved via Turbo, without a reload)
- [ ] [S] "Applied / not applied" status (toggled via Turbo)
- [ ] [S] Verify the list and the card on a phone (375px width)

## Phase 12 — Cover letter

- [ ] [S] `LlmTask`: add the "cover letter" task (the model is selected in the settings, like for the others)
- [ ] [S] `CoverLetter` entity (jobPosting, text, status, createdAt; several versions per job posting) + migration
- [ ] [C] Generation service: `CvProfile` + `JobPosting` + platform `Prompt` → letter text (runs in a Messenger handler)
- [ ] [S] "Cover letter" button in the job posting card (dispatches the message, the Turbo frame refreshes until the letter is ready)
- [ ] [S] Manual editing of the letter + copying to the clipboard

## Phase 13 — Deployment to the home server

- [ ] [S] Prod docker-compose configuration: `compose.prod.yaml`, run with `-f compose.yaml -f compose.prod.yaml` so the dev override isn't merged; on the server `.env.local` sets `COMPOSE_FILE=compose.yaml:compose.prod.yaml`, so a bare `docker compose up` picks the prod set too (`APP_ENV=prod`, no profiler or debug, no DB port)
- [ ] [S] Prod `php.ini` override: `opcache.validate_timestamps=0`
- [ ] [S] `trusted_proxies` + `X-Forwarded-*` headers, so that behind the tunnel Symfony sees HTTPS (`https://` links, secure cookies)
- [ ] [S] Ingress rule in the existing Cloudflare Tunnel: `jobhunter.madbugs.dev` → `localhost:APP_PORT` on the server
- [ ] [S] Verify from a phone: login, job posting list, POSTing a job posting via the API over HTTPS
- [ ] [S] README: installation and first run
- [ ] [S] README: external access (an example with Cloudflare Tunnel; `cloudflared` is not part of our compose — everyone has their own setup)

## Open questions

- Final field schema of `CvProfile` (to be clarified in Phase 6)

## Backlog (deliberately not now)

- CV tailored to a specific job posting: full CV structure in the DB (experience per employer, projects, education), the uploaded PDF becomes an import; a button in the job posting card → the LLM assembles a version for the posting → editing → PDF export (dompdf). The next phase after deployment, detail it before starting

- Automatic job collection: a "Check job postings" button in the UI → a headless browser on the server (Panther/Playwright). A separate phase, start with the simplest platform, not LinkedIn
- Additional LLM providers (Anthropic, OpenAI…)
- Email alerts
- Telegram notifications
- Job posting deduplication
- Automatic prompt adjustment based on user comments
- Migrating the API layer to Go
