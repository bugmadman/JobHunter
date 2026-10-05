# Implementation plan

Source: [NOTES.md](NOTES.md). Tasks are done in phase order, within a phase — top to bottom. Mark `[x]` as they get done. Each task is an atomic step verifiable in a few minutes.

## Architecture (brief)

- A self-hosted product, not SaaS: `git clone` + `docker compose up`. A single user. Target deployment — a home server, accessed from a phone via an already running Cloudflare Tunnel (`jobhunter.madbugs.dev`); the server is exposed to the internet — HTTPS and protection of the login/API are mandatory.
- PHP 8.5, Symfony 7.4, PostgreSQL, Doctrine ORM, EasyAdmin (settings and reference data), custom Twig pages on Symfony UX — Turbo/Stimulus via AssetMapper, without Node.js (job posting work screens, convenient on a phone), API Platform (job posting intake), Symfony Messenger on the Doctrine transport (queue in PostgreSQL, no RabbitMQ). Web server — FrankenPHP (Caddy + PHP in one container, Symfony in worker mode), plain HTTP behind Cloudflare Tunnel. Everything in Docker.
- The LLM is configured in the UI: provider, a model per task (CV analysis / prompt generation / matching), API key. Adapters behind `LlmClientInterface`, the first one — Gemini.
- Flow: Claude Code manually scans a platform → POST to an API Platform endpoint (protected by a key from the settings) → `JobPosting` with status `raw` → a message to Messenger → worker: keyword filter → (if passed) LLM matching using the platform prompt → result. Every transition is logged in `JobPostingStageLog`.
- Deliberately not now: see "Backlog".

## Phase 0 — Repository and environment

Docker: `.docker/` + `compose.yaml` + Makefile, designed for installation from a fresh clone.

- [x] `git init`
- [x] `.gitignore`: `.idea/`, `.DS_Store` (Flex will add the Symfony blocks when creating the skeleton)
- [x] `.editorconfig`
- [ ] `.docker/php/Dockerfile`: the official FrankenPHP image `dunglas/frankenphp:1-php8.5-alpine`, extensions via `install-php-extensions` (`pdo_pgsql`, `intl`, `opcache`, `apcu`), composer, user via `USER`/`USERID`
- [ ] `.docker/php/php.ini` — in the repository (not in `.gitignore`, otherwise the build from a fresh clone fails)
- [ ] `.docker/php/Caddyfile` — in the repository, plain HTTP (HTTPS is terminated by Cloudflare Tunnel)
- [ ] `compose.yaml`: `php` service (runs FrankenPHP, publishes `APP_PORT`)
- [ ] `compose.yaml`: `postgres` service with a healthcheck, port not published externally, `depends_on` with `condition: service_healthy`
- [ ] Docker variables template in `.env` (`APP_NAME`, `APP_PORT`, `APP_USER`, `APP_USERID`, DB credentials)
- [ ] Makefile: `init` (creates `.env.local` from the template if it doesn't exist; up; composer install; migrations), `upd`, `down`, `ps`, `in`
- [ ] Remove the "once they exist" clause about Makefile targets from the "Running commands" section of `CLAUDE.md` (the targets now exist)
- [ ] Create a Symfony 7.4 skeleton inside the container
- [ ] Enable FrankenPHP worker mode for Symfony
- [ ] Verify: `make init` from a fresh clone succeeds, the start page opens in the browser

## Phase 1 — Dev tooling

Goal — as many automated checks as possible, so that errors are caught without manual review.

- [ ] Install `symfony/maker-bundle`
- [ ] Install PHPUnit 12, `phpunit.xml.dist` (`failOnWarning`, `failOnDeprecation`), one smoke test green
- [ ] Install PHPStan, `phpstan.dist.neon` at level 10
- [ ] Install `phpstan/phpstan-strict-rules`
- [ ] Install `phpstan/phpstan-deprecation-rules`
- [ ] Install `phpstan/phpstan-symfony`
- [ ] Install PHP-CS-Fixer, a single config `.php-cs-fixer.dist.php` (`@Symfony` + `declare_strict_types` + `blank_line_before_statement` for `return` + `return_assignment`)
- [ ] Install `squizlabs/php_codesniffer` with only the `Generic.Files.LineLength` rule (120), no other standards — CS-Fixer doesn't check line length
- [ ] Install Rector, config for PHP 8.5 + Symfony code quality
- [ ] Install `ergebnis/composer-normalize`, normalize composer.json
- [ ] Install `symfony/browser-kit` + `symfony/css-selector`
- [ ] Install `fakerphp/faker`
- [ ] Install GrumPHP, `grumphp.yml`: composer, composer_normalize, composer audit, PHPStan, PHP-CS-Fixer, PHPCS (line length), Rector (dry-run), PHPUnit, Symfony linters (`lint:yaml`, `lint:container`, `lint:twig`)
- [ ] GrumPHP: run checks via `docker compose exec` (git on the host, PHP in the container)
- [ ] Verify: a commit with a deliberate error is blocked, a clean one passes
- [ ] Remove the "once they are installed" clause from the GrumPHP item in `.claude/agents/developer.md` (all checks now actually work)

## Phase 2 — Bundles

- [ ] Doctrine ORM + Migrations, connection to PostgreSQL (`doctrine:database:create` succeeds)
- [ ] Install `phpstan/phpstan-doctrine`
- [ ] Install `doctrine/doctrine-fixtures-bundle`
- [ ] Symfony Messenger + `symfony/doctrine-messenger`, `async` transport in PostgreSQL
- [ ] Symfony Security
- [ ] EasyAdminBundle
- [ ] AssetMapper
- [ ] Symfony UX Turbo
- [ ] Symfony UX Stimulus
- [ ] Base layout for custom pages (responsive, shared, with navigation to the admin)
- [ ] API Platform

## Phase 3 — User and login

- [ ] `User` entity (email, password hash) + migration
- [ ] Console command to create a user
- [ ] Form login, the whole UI is behind the login
- [ ] `login_throttling` — brute-force protection for the login
- [ ] Empty EasyAdmin dashboard, opens after login

## Phase 4 — LLM settings

- [ ] `LlmProvider` enum (only Gemini for now)
- [ ] `LlmTask` enum (CV analysis / prompt generation / matching)
- [ ] Provider key entity (provider → apiKey) + migration
- [ ] Per-task model selection entity (task → provider + model) + migration
- [ ] Encryption of LLM API keys in the DB (`sodium`, encryption key in `.env.local`)
- [ ] EasyAdmin CRUD for keys, the key is shown masked
- [ ] EasyAdmin CRUD for model selection
- [ ] `LlmClientInterface` + request/response DTOs
- [ ] `GeminiClient`: text request
- [ ] `GeminiClient`: structured output via JSON schema
- [ ] Resolver "task → client + model" from the settings
- [ ] Console command to check a key (test request to the model)

## Phase 5 — Platforms and skills

- [ ] Platform access type enum
- [ ] `Platform` entity + migration
- [ ] Fixture: Upwork, LinkedIn, Glassdoor, Indeed
- [ ] `Skill` entity + migration

## Phase 6 — CV: upload and analysis

- [ ] `CvProfile` entity (original file, analysis in the original language, analysis in English, `yearsExperience`, `seniority`, `salaryMin/Max/Currency`, raw LLM response) + migration
- [ ] `CvLanguage` entity (one-to-many) + migration
- [ ] `CvProfile` ↔ `Skill` relation (many-to-many) + migration
- [ ] CV upload form (PDF only), saving the original file
- [ ] CV analysis JSON schema + validation via `justinrainbow/json-schema`
- [ ] CV analysis service: PDF directly into the LLM call (no text extraction) → validation → saving to `CvProfile`/`CvLanguage`/`Skill`
- [ ] Analysis view page

## Phase 7 — Per-platform prompts

- [ ] Prompt source enum (generated / manually edited)
- [ ] `Prompt` entity (platform, text, source) + migration
- [ ] Generating a draft prompt from `CvProfile` for a platform (LLM call)
- [ ] Manual prompt editing in EasyAdmin
- [ ] `platforms/upwork.md`
- [ ] `platforms/linkedin.md`
- [ ] `platforms/glassdoor.md`
- [ ] `platforms/indeed.md`

## Phase 8 — Job posting domain

- [ ] `JobPosting` pipeline status enum
- [ ] `JobPosting` entity with transition methods that protect invariants + migration
- [ ] Unit tests for status transitions (including forbidden ones)
- [ ] `JobPostingStageLog` entity + migration
- [ ] Domain events for transitions → written to `JobPostingStageLog`

## Phase 9 — Job posting intake (API Platform)

- [ ] Generating/regenerating the API key in the settings
- [ ] API key authenticator (header)
- [ ] Rate limiter on the job posting intake endpoint
- [ ] POST endpoint for creating a `JobPosting` (DTO with Assert)
- [ ] Dispatching a message to Messenger on creation
- [ ] Verify the endpoint with curl

## Phase 10 — Processing (Messenger pipeline)

- [ ] Message class + empty handler
- [ ] Keyword filter by `Skill` + edge case tests
- [ ] Wire the keyword filter into the handler (rejection finalizes the status)
- [ ] LLM matching using the platform `Prompt`
- [ ] Wire LLM matching into the handler (like/dislike + reason)
- [ ] `worker` service in `compose.yaml` (`messenger:consume async`, the same image as `php`)

## Phase 11 — Results UI (custom pages, not EasyAdmin)

- [ ] Job posting list as cards (not a table), responsive for phones
- [ ] List filters: platform, status, like/dislike, applied
- [ ] Job posting card: description, LLM rating with reason
- [ ] Job posting card: pipeline history (`JobPostingStageLog`)
- [ ] Manually override the LLM like/dislike decision in the job posting card (via Turbo, without a reload)
- [ ] User comment on a job posting (saved via Turbo, without a reload)
- [ ] "Applied / not applied" status (toggled via Turbo)
- [ ] Verify the list and the card on a phone (375px width)

## Phase 12 — Cover letter

- [ ] `LlmTask`: add the "cover letter" task (the model is selected in the settings, like for the others)
- [ ] `CoverLetter` entity (jobPosting, text, createdAt; several versions per job posting) + migration
- [ ] Generation service: `CvProfile` + `JobPosting` + platform `Prompt` → letter text
- [ ] "Cover letter" button in the job posting card (generation via Turbo, without a reload)
- [ ] Manual editing of the letter + copying to the clipboard

## Phase 13 — Deployment to the home server

- [ ] Prod docker-compose configuration (`APP_ENV=prod`, no profiler or debug)
- [ ] `trusted_proxies` + `X-Forwarded-*` headers, so that behind the tunnel Symfony sees HTTPS (`https://` links, secure cookies)
- [ ] Ingress rule in the existing Cloudflare Tunnel: `jobhunter.madbugs.dev` → `localhost:APP_PORT` on the server
- [ ] Verify from a phone: login, job posting list, POSTing a job posting via the API over HTTPS
- [ ] README: installation and first run
- [ ] README: external access (an example with Cloudflare Tunnel; `cloudflared` is not part of our compose — everyone has their own setup)

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
