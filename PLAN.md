# Implementation plan

Source: [NOTES.md](NOTES.md). Tasks are done in phase order, within a phase — top to bottom. Mark `[x]` as they get done. `1.3` is the task's stable number; ` — reviewed` marks a task whose code the owner has reviewed (see WORKFLOW.md). Each task is an atomic step verifiable in a few minutes. `[S]`/`[C]` is the task's tier (simple/complex): it decides who does it, see CLAUDE.md, "Model selection".

## Architecture (brief)

- A self-hosted product, not SaaS: `git clone` + `docker compose up`. A single user. Target deployment — a home server, accessed from a phone via an already running Cloudflare Tunnel (`jobhunter.madbugs.dev`); the server is exposed to the internet — HTTPS and protection of the login/API are mandatory.
- PHP 8.5, Symfony 7.4, PostgreSQL, Doctrine ORM, EasyAdmin (settings and reference data), custom Twig pages on Symfony UX — Turbo/Stimulus via AssetMapper, without Node.js (job posting work screens, convenient on a phone), API Platform (job posting intake), Symfony Messenger on the Doctrine transport (queue in PostgreSQL, no RabbitMQ). Web server — FrankenPHP (Caddy + PHP in one container, Symfony in worker mode), plain HTTP behind Cloudflare Tunnel. Everything in Docker.
- The LLM is configured in the UI: provider, a model per task (CV analysis / prompt generation / matching), API key. Adapters behind `LlmClientInterface`, the first one — Gemini.
- Flow: Claude Code manually scans a platform → POST to an API Platform endpoint (protected by a key from the settings) → `JobPosting` with status `raw` → a message to Messenger → worker: keyword filter → (if passed) LLM matching using the platform prompt → result. Every transition is logged in `JobPostingStageLog`.
- Deliberately not now: see "Backlog".

## Phase 0 — Repository and environment

Docker: `.docker/` + `compose.yaml` + Makefile, designed for installation from a fresh clone.

- [x] [S] 0.1 `git init` — reviewed
- [x] [S] 0.2 `.gitignore`: `.idea/`, `.DS_Store` (Flex will add the Symfony blocks when creating the skeleton) — reviewed
- [x] [S] 0.3 `.editorconfig` — reviewed
- [x] [S] 0.4 `.docker/php/Dockerfile`: the official FrankenPHP image `dunglas/frankenphp:1-php8.5-alpine`, extensions via `install-php-extensions` (`pdo_pgsql`, `intl`, `apcu`; OPcache is built into PHP 8.5), composer, user via `USER`/`USERID` — reviewed
- [x] [S] 0.5 `.docker/php/php.ini` — in the repository (not in `.gitignore`, otherwise the build from a fresh clone fails) — reviewed
- [x] [S] 0.6 `.docker/php/Caddyfile` — in the repository, plain HTTP (HTTPS is terminated by Cloudflare Tunnel), the admin endpoint stays on `localhost:2019` (the image's healthcheck depends on it) — reviewed
- [x] [S] 0.7 `compose.yaml`: `php` service (runs FrankenPHP, publishes `APP_PORT`), built from the repository root: `context: .` + `dockerfile: .docker/php/Dockerfile` — reviewed
- [x] [S] 0.8 `compose.yaml`: `postgres` service with a healthcheck, port not published in `compose.yaml`, `depends_on` with `condition: service_healthy` — reviewed
- [x] [S] 0.9 `compose.override.yaml` (dev only): Postgres port on `127.0.0.1:POSTGRES_PORT` for a local DB client — reviewed
- [x] [S] 0.10 Docker variables template in `.env` (`APP_NAME`, `APP_PORT`, `APP_USER`, `APP_USERID`, DB credentials, `POSTGRES_PORT`), defaults `APP_USER=app`, `APP_USERID=1000` — reviewed
- [x] [S] 0.11 Makefile: `init` (creates `.env.local` from the template if it doesn't exist, filling `APP_USERID` with the host's `id -u` and stopping with a clear error if it is 0; up; composer install), `upd`, `updb` (up with a rebuild), `down`, `ps`, `in` — reviewed
- [x] [S] 0.12 Remove the "once they exist" clause about Makefile targets from the "Running commands" section of `CLAUDE.md` (the targets now exist) — reviewed
- [x] [S] 0.13 Create a Symfony 7.4 skeleton inside the container — reviewed
- [x] [S] 0.14 Enable FrankenPHP worker mode for Symfony — reviewed
- [x] [S] 0.15 Verify: `make init` from a fresh clone succeeds, the start page opens in the browser — reviewed

## Phase 1 — Dev tooling

Goal — as many automated checks as possible, so that errors are caught without manual review.

- [x] [S] 1.1 Install `symfony/maker-bundle` — reviewed
- [x] [S] 1.2 Install PHPUnit 12, `phpunit.dist.xml` (`failOnWarning`, `failOnDeprecation`), one smoke test green — reviewed
- [x] [S] 1.3 Install PHPStan, `phpstan.dist.neon` at level 10 — reviewed
- [x] [S] 1.4 Install `phpstan/phpstan-strict-rules` — reviewed
- [x] [S] 1.5 Install `phpstan/phpstan-deprecation-rules` — reviewed
- [x] [S] 1.6 Install `phpstan/phpstan-symfony` — reviewed
- [x] [S] 1.7 Install PHP-CS-Fixer, a single config `.php-cs-fixer.dist.php` (`@Symfony` + `declare_strict_types` + `blank_line_before_statement` for `return` + `return_assignment`) — reviewed
- [x] [S] 1.8 Install `squizlabs/php_codesniffer` with only the `Generic.Files.LineLength` rule (120), no other standards — CS-Fixer doesn't check line length — reviewed
- [x] [S] 1.9 Install Rector, config for PHP 8.5 + Symfony code quality — reviewed
- [x] [S] 1.10 Install `ergebnis/composer-normalize`, normalize composer.json — reviewed
- [x] [S] 1.11 Install `symfony/browser-kit` + `symfony/css-selector` — reviewed
- [x] [S] 1.12 Install `fakerphp/faker` — reviewed
- [x] [S] 1.13 Makefile check targets: `cs` (PHP-CS-Fixer dry-run with diff + PHPCS), `fix` (PHP-CS-Fixer + Rector apply), `stan` (PHPStan), `test` (PHPUnit), `lint` (all checks at once) — reviewed
- [x] [S] 1.14 Install GrumPHP, `grumphp.yml`: composer, composer_normalize, composer audit, PHPStan, PHP-CS-Fixer, PHPCS (line length), Rector (dry-run), PHPUnit, Symfony linters (`lint:yaml`, `lint:container`; `lint:twig` comes with Twig); the dev container is compiled before PHPStan (`lint:container` runs first or `cache:warmup`), otherwise `phpstan-symfony` silently runs without `var/cache/dev/App_KernelDevDebugContainer.xml` — reviewed
- [x] [S] 1.15 Add `git` to the `php` image (GrumPHP calls git inside the container) — reviewed
- [x] [S] 1.16 GrumPHP: run checks via `docker compose exec` (git on the host, PHP in the container) — reviewed
- [x] [S] 1.17 Verify: a commit with a deliberate error is blocked, a clean one passes — reviewed
- [x] [S] 1.18 Remove the "once they are installed" clause from the GrumPHP item in `.claude/agents/developer.md` (all checks now actually work) — reviewed

## Phase 2 — Bundles

- [x] [S] 2.1 Doctrine ORM + Migrations, connection to PostgreSQL (`doctrine:database:create` succeeds); add `doctrine:migrations:migrate` to `make init`
- [x] [S] 2.2 Install `phpstan/phpstan-doctrine`
- [x] [S] 2.3 Install `doctrine/doctrine-fixtures-bundle`
- [x] [S] 2.4 Symfony Messenger + `symfony/doctrine-messenger`, `async` transport in PostgreSQL
- [x] [S] 2.5 `worker` service in `compose.yaml` (`messenger:consume async`, the same image as `php`, `healthcheck` disabled: there is no Caddy in it)
- [x] [S] 2.6 Symfony Security
- [x] [S] 2.7 EasyAdminBundle
- [x] [S] 2.8 GrumPHP: add `lint:twig` (Twig now installed with EasyAdmin)
- [x] [S] 2.9 Translator: `default_locale: en`, `enabled_locales: [en, ru]`
- [x] [S] 2.10 AssetMapper
- [x] [S] 2.11 Symfony UX Turbo
- [x] [S] 2.12 Symfony UX Stimulus
- [x] [S] 2.13 Bootstrap 5 via importmap
- [x] [S] 2.14 Base layout for custom pages (responsive, shared)
- [x] [S] 2.15 Stub home page `/` on the base layout (replaced by the job posting list in Phase 11)
- [x] [S] 2.16 GrumPHP: fail when a `messages` key is missing in `en` or `ru` (`debug:translation --only-missing --domain=messages` for each locale)
- [x] [S] 2.17 API Platform
- [x] [S] 2.18 API version prefix `/api/v1`
- [x] [S] 2.19 Mark `/app` as a git `safe.directory` in the `php` image (Composer warned "dubious ownership")

## Phase 3 — User and login

- [x] [S] 3.1 `User` entity (email, password hash) + migration
- [x] [S] 3.2 Code structure rule in CLAUDE.md
- [x] [S] 3.3 Move the project-independent workflow (NOTES/PLAN cycle, task granularity, commit rules) from CLAUDE.md to WORKFLOW.md, imported with `@WORKFLOW.md`
- [x] [S] 3.4 Split the rule files: `AGENTS.md` (project rules), `CLAUDE.md` (Claude Code entry point + Claude-only rules), `CONTRIBUTING.md` (pointer for people), the owner's personal rules in the uncommitted `CLAUDE.local.md`; clean up the agent files; check that the imports reach the subagents
- [x] [S] 3.5 Move `Email` and `InvalidEmail` to `src/Domain/Shared/` (see NOTES, "Code structure")
- [x] [S] 3.6 PHP-CS-Fixer `final_internal_class` (empty `include`/`exclude`, `consider_absent_docblock_as_internal_class`): every non-abstract class `final`, Doctrine entities included; a line in AGENTS.md "What the tools enforce"; in NOTES why entities can be final (`enable_native_lazy_objects: true`)
- [x] [C] 3.7 `UserService::create()` + `UserServiceInterface` in `src/Domain/User/`: rejects a second user (`UserAlreadyExists`) and an empty password; depends on `UserRepositoryInterface` (`exists()`, `save()`) and a password hasher interface + adapter in `src/Infrastructure/Security/`; unit tests with mocks
- [x] [S] 3.8 Console command `app:user:create`: thin, calls `UserServiceInterface`; functional test with the service mocked
- [x] [C] 3.9 Form login, the whole UI is behind the login
- [x] [S] 3.10 `app:user:create` asks for the email when the argument is missing (`#[Ask]`); functional test
- [x] [S] 3.11 Install `symfony/web-profiler-bundle` (dev only): debug toolbar and profiler in dev
- [x] [S] 3.12 AGENTS.md comment rule: it covers comments added by Flex recipes too (doc links, "uncomment this", examples); a comment says why it is done this way, not what the setting does
- [x] [S] 3.13 Remove the recipe and obvious comments from configs, `.env*`, compose, Makefile and code (not the generated `config/reference.php`), keeping only real traps
- [x] [S] 3.14 AGENTS.md repository naming rule: `find…` returns null when nothing is found, `get…` throws; methods required by a framework interface (`loadUserByIdentifier()`) keep their names
- [x] [S] 3.15 Test DB: a separate `_test` database filled from fixtures and rolled back after each test (`dama/doctrine-test-bundle`)
- [x] [C] 3.16 Login tests against the DB: `UserRepository::loadUserByIdentifier()` finds a user by a mixed-case email and returns null for an unknown one; a functional login as a fixture user through the entity provider (drop the in-memory provider from `when@test`)
- [x] [C] 3.17 CSRF tests for cross-site requests: POST `/login` and `/logout` with `Sec-Fetch-Site: cross-site` and a well-formed token are rejected (login error, 403)
- [x] [C] 3.18 `User::__serialize()` puts the crc32c of the password hash into the session instead of the hash itself; entity test
- [x] [C] 3.19 Narrow the public API access to the docs: `^/api/v1/docs` (with its format variants) is public, everything else under `/api/v1` falls under `^/ → ROLE_USER`; `ApiDocsTest` checks that the docs open without login and the entrypoint, `contexts/`, `errors/`, `.well-known/` redirect to `/login`
- [x] [C] 3.20 `login_throttling` — brute-force protection for the login; also make an unknown email take as long as a wrong password (a dummy hash check), so the owner's email can't be confirmed by timing
- [x] [C] 3.21 `UnknownUserLoginListener`: only on the `main` firewall; verify the submitted password against a dummy hash made by the configured hasher (regenerated when it needs a rehash) instead of hashing a constant, so a password over 4096 bytes is rejected as fast as for a known email; find `UserNotFoundException` anywhere in the exception chain; tests
- [x] [C] 3.22 Rehash the owner's password on login when the hasher config changes (`PasswordUpgraderInterface`), so a wrong password and the dummy check cost the same; tests
- [x] [S] 3.23 Empty EasyAdmin dashboard
- [x] [S] 3.24 Link to the admin in the base layout navigation
- [x] [S] 3.25 `User.locale` (a `Locale` enum: `en`, `ru`; default `en`) + migration
- [x] [S] 3.26 The request locale is taken from the logged-in user's `locale` (EasyAdmin included)
- [x] [S] 3.27 Language switcher in the interface, saves `User.locale`

## Phase 4 — LLM settings

- [x] [S] 4.1 `LlmProvider` enum (only Gemini for now)
- [x] [S] 4.2 `LlmTask` enum (CV analysis / prompt generation / matching)
- [x] [S] 4.3 `LlmOperationStatus` enum (pending / done / failed): the state of an LLM call started from the UI and running in Messenger
- [x] [C] 4.4 Provider key entity (provider → apiKey) + migration
- [x] [S] 4.5 Per-task model selection entity (task → provider + model) + migration
- [x] [C] 4.6 Encryption of LLM API keys in the DB (`sodium`, encryption key in `.env.local`)
- [x] [C] 4.7 EasyAdmin CRUD for keys, the key is shown masked
- [x] [S] 4.8 EasyAdmin CRUD for model selection
- [x] [C] 4.9 `LlmClientInterface` + request/response DTOs
- [x] [C] 4.10 `GeminiClient`: text request
- [x] [C] 4.11 `GeminiClient`: structured output via JSON schema
- [x] [C] 4.12 Resolver "task → client + model" from the settings
- [x] [S] 4.13 Console command to check a key (test request to the model)

## Phase 5 — Platforms and skills

- [x] [S] 5.1 Platform access type enum
- [x] [S] 5.2 `Platform` entity + migration
- [x] [S] 5.3 Fixture: Upwork, LinkedIn, Glassdoor, Indeed
- [x] [S] 5.4 `Skill` entity + migration

## Phase 6 — CV: upload and analysis

- [x] [C] 6.1 `CvProfile` entity (original file, analysis status (`LlmOperationStatus`), analysis in the original language, analysis in English, `yearsExperience`, `seniority`, `salaryMin/Max/Currency`, raw LLM response) + migration
- [x] [S] 6.2 `CvLanguage` entity (one-to-many) + migration
- [x] [S] 6.3 `CvProfile` ↔ `Skill` relation (many-to-many) + migration
- [x] [C] 6.4 CV upload form (PDF only, form limit ≤ 10M: PHP's `upload_max_filesize`), saving the original file
- [x] [C] 6.5 CV analysis JSON schema + validation via `justinrainbow/json-schema`
- [x] [C] 6.6 CV analysis service: PDF directly into the LLM call (no text extraction) → validation → saving to `CvProfile`/`CvLanguage`/`Skill`
- [x] [C] 6.7 CV upload dispatches the analysis message; the Messenger handler runs the analysis service and sets the status (done / failed)
- [x] [S] 6.8 Analysis view page: shows the status, the Turbo frame refreshes until the analysis is done or failed

## Phase 7 — Per-platform prompts

- [x] [S] 7.1 Prompt source enum (generated / manually edited)
- [x] [C] 7.2 `Prompt` entity (platform, text, source, generation status) + migration
- [x] [C] 7.3 Generating a draft prompt from `CvProfile` for a platform (LLM call in a Messenger handler)
- [x] [S] 7.4 "Generate prompt" button: dispatches the message, the Turbo frame refreshes until the draft is ready
- [x] [C] 7.5 Manual prompt editing in EasyAdmin
- [x] [S] 7.6 `platforms/upwork.md`
- [x] [S] 7.7 `platforms/linkedin.md`
- [x] [S] 7.8 `platforms/glassdoor.md`
- [x] [S] 7.9 `platforms/indeed.md`

## Phase 8 — Job posting domain

- [x] [C] 8.1 `JobPosting` pipeline status enum
- [x] [C] 8.2 `JobPosting` entity with transition methods that protect invariants + migration
- [x] [C] 8.3 Unit tests for status transitions (including forbidden ones); delete the placeholder `tests/KernelBootTest.php`, real tests now cover the PHPUnit setup
- [x] [S] 8.4 `JobPostingStageLog` entity + migration
- [x] [C] 8.5 Domain events for transitions → written to `JobPostingStageLog`

## Phase 9 — Job posting intake (API Platform)

- [x] [C] 9.1 Generating/regenerating the API key in the settings
- [x] [C] 9.2 API key authenticator (header)
- [x] [C] 9.3 Rate limiter on the job posting intake endpoint
- [x] [C] 9.4 POST endpoint for creating a `JobPosting` (DTO with Assert)
- [x] [C] 9.5 Dispatching a message to Messenger on creation
- [x] [S] 9.6 Verify the endpoint with curl

## Phase 10 — Processing (Messenger pipeline)

- [x] [S] 10.1 Empty handler for `ProcessJobPosting` (the message class exists)
- [x] [C] 10.2 Keyword filter by `Skill` + edge case tests
- [x] [C] 10.3 Wire the keyword filter into the handler (rejection finalizes the status)
- [x] [C] 10.4 LLM matching using the platform `Prompt`
- [x] [C] 10.5 Wire LLM matching into the handler (like/dislike + reason)

## Phase 11 — Results UI (custom pages, not EasyAdmin)

- [x] [S] 11.1 Job posting list as cards (not a table), responsive for phones
- [x] [S] 11.2 List filters: platform, status, like/dislike
- [x] [S] 11.3 Job posting card: description, LLM rating with reason
- [x] [S] 11.4 Job posting card: pipeline history (`JobPostingStageLog`)
- [x] [C] 11.5 Manually override the LLM like/dislike decision in the job posting card (via Turbo, without a reload)
- [x] [S] 11.6 User comment on a job posting (saved via Turbo, without a reload)
- [x] [C] 11.7 "Applied / not applied" status (toggled via Turbo) + the "applied" list filter
- [x] [S] 11.8 Verify the list and the card on a phone (375px width)

## Phase 12 — Cover letter

- [x] [S] 12.1 `LlmTask`: add the "cover letter" task (the model is selected in the settings, like for the others)
- [x] [S] 12.2 `CoverLetter` entity (jobPosting, text, status, createdAt; several versions per job posting) + migration
- [x] [C] 12.3 Generation service: `CvProfile` + `JobPosting` + platform `Prompt` → letter text (runs in a Messenger handler)
- [x] [S] 12.4 "Cover letter" button in the job posting card (dispatches the message, the Turbo frame refreshes until the letter is ready)
- [x] [S] 12.5 Manual editing of the letter + copying to the clipboard

## Phase 13 — Deployment to the home server

- [x] [C] 13.1 `trusted_proxies` + `X-Forwarded-*` headers, so that behind the tunnel Symfony sees HTTPS (`https://` links, secure cookies) and the login limiter sees the client's IP, not the tunnel's; must land before the tunnel ingress rule, otherwise anyone can lock the owner out
- [x] [C] 13.2 Prod docker-compose configuration: `compose.prod.yaml`, run with `-f compose.yaml -f compose.prod.yaml` so the dev override isn't merged; on the server `.env.local` sets `COMPOSE_FILE=compose.yaml:compose.prod.yaml`, so the `make` targets pick the prod set too (`APP_ENV=prod`, no profiler or debug, no DB port)
- [x] [S] 13.3 Prod `php.ini` override: `opcache.validate_timestamps=0`, `zend.assertions=-1`
- [x] [S] 13.8 Data migration inserting the four platforms (`Upwork`, `LinkedIn`, `Glassdoor`, `Indeed`) when they are missing: a fresh dev or prod DB has none (only the fixtures create them), so the intake API rejects every posting; blocks the phone check and the first scan
- [x] [C] 13.9 Load the stateless CSRF script on the EasyAdmin pages: a separate `admin` AssetMapper entrypoint with only `csrf_protection_controller.js` (EasyAdmin brings its own Bootstrap and doesn't work with Turbo), added in `DashboardController::configureAssets()`; without it, once the login form has double-submitted the token, every admin form POST in that session fails CSRF validation — reviewed
- [ ] [S] 13.4 Ingress rule in the existing Cloudflare Tunnel: `jobhunter.madbugs.dev` → `localhost:APP_PORT` on the server
- [ ] [S] 13.5 Verify from a phone: login, job posting list, POSTing a job posting via the API over HTTPS
- [x] [S] 13.6 README: installation and first run
- [x] [S] 13.7 README: external access (an example with Cloudflare Tunnel; `cloudflared` is not part of our compose — everyone has their own setup)

## Phase 14 — Scan instructions

- [x] [S] 14.1 `ScanInstruction` entity (platform → text, updatedAt; one per platform) + migration
- [x] [S] 14.2 EasyAdmin CRUD for scan instructions
- [x] [C] 14.3 `GET /api/v1/scan-instructions` behind the intake API key: the platforms with their instructions and the intake POST format
- [x] [S] 14.4 Bootstrap prompt on the intake API key page (the key is shown only there, right after it is generated): the prompt with the server URL and the key filled in (a placeholder otherwise), a copy button
- [x] [S] 14.5 Upwork scan instruction template in `platforms/upwork.md` (the owner's own searches go only into the DB, through the settings)

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
- Multi-user version: owner and user roles, prompt templates from the owner, per-user prompts and LLM keys (see NOTES, "Product and concept")
