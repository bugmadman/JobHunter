# Notes and decisions

Current project decisions, grouped by topic. Each item is the final decision (with the reason, if there is one); "Open" means the question isn't decided yet, "Postponed" means deliberately not now. Superseded decisions are in the "Rejected alternatives" section, new raw thoughts are in the "Inbox" section. Tasks derived from these decisions are in PLAN.md.

## Product and concept

- A job search service: job postings from platforms → keyword filter → LLM matching against criteria derived from the CV → review and apply in a web interface.
- Self-hosted, not SaaS: installed via `git clone` + `docker compose up`.
- A single user: one account, no registration. `Prompt`/`CvProfile`/`JobPosting` are not tied to `User`.
- A possible future version is multi-user, with two roles: the owner writes the prompt templates, each user sees the final prompt and sets it themselves; a user sets their own LLM keys or uses the owner's, if they're fine with a different model. Not now: the current version stays single-user, EasyAdmin is that user's settings screen.
- All the code is written by Claude with minimal manual involvement from the owner — so the code must be verified automatically, without a manual review at every step (see "Dev tooling and quality", "Development process").
- The result is written only to the DB.

## Job sources and platforms

- Platforms: Upwork, LinkedIn, glassdoor.com, indeed.com.
- Platforms are stored in the DB (a "platform" entity), so they can be added/removed without a code release.
- Each platform has its own context file in `platforms/` (similar to AGENTS.md): decisions about what and how to code for accessing it (API? RSS? scraping? parsing specifics) are written down before implementing it and along the way. This is dev documentation, not a user setting.
- For now, job postings are collected by Claude Code manually: the owner runs Claude → Claude itself accesses the platform (browser/API) → writes raw job postings to the DB via our API.
- Postponed (a separate phase): a "Check job postings" button in the UI → a headless browser on the server (Panther/Playwright) collects job postings by itself. Start with the simplest platform, not LinkedIn.
- Postponed: cron/webhook — once an automatic source appears (email or periodic scraping).
- Postponed: deduplication (the same job posting from different platforms or a repeated one) — duplicates are acceptable for now.

## CV and candidate profile

- CV upload format — PDF only. The PDF is passed to the LLM directly (Gemini reads PDFs), without a text extraction library.
- PHP's `upload_max_filesize` (10M, `.docker/php/php.ini`) is the hard ceiling for the CV upload: the form's limit can be lower, not higher.
- Flow: the owner uploads the CV → the original file is saved → a cheap LLM analyzes the resume → the result is saved to the DB and shown to the owner.
- The analysis comes in two versions: in the resume's original language (for the owner to read) and in English (canonical, used for comparison with job postings).
- One LLM call per CV → both the text analysis (in 2 languages) and structured output following a single fixed schema via structured output/JSON schema in the API, not arbitrary JSON at the model's discretion — otherwise the fields aren't comparable.
- The LLM response is validated against this schema with `justinrainbow/json-schema` (in the code, not only in tests).
- Tentative schema fields: `stack[]`, `years_experience`, `seniority`, `languages[{lang, level}]`, `salary_expectation{min,max,currency}`. Open: the final set of fields — to be refined during implementation.
- Storage without a JSON column for structured data — so it can be indexed/filtered and used in the keyword filter:
  - scalars (`years_experience`, `seniority`, `salary_min/max/currency`) — regular columns;
  - repeating structures — relational tables: `Skill` (many-to-many), `CvLanguage` (one-to-many). `Skill` as a separate entity allows reusing tags across platforms and in the keyword filter.
- The raw LLM JSON response is stored in a separate technical field, just for debugging, outside the business model.

## Prompts

- Each platform has its own prompt, not one global prompt.
- A platform prompt is auto-generated from the CV profile; the owner accepts it as is or edits it manually.
- Postponed: in the long run, the owner's comments on job postings should influence the adjustment of the prompt (feedback owner → prompt).

## Processing pipeline

- Job posting intake: an API Platform endpoint, protected by a key from the settings. Requests are tested with curl.
- The API is versioned in the URL: `/api/v1`. Free while there are no clients; once the address is in the Claude Code instructions (and, in a multi-user version, in other people's setups), adding it would break them. An incompatible change gets `/api/v2` next to it; compatible changes (new fields, deprecations) stay in `v1`.
- Queue: Symfony Messenger on the Doctrine transport (queue in PostgreSQL). Intake via the API → dispatching a message → a separate worker process (`messenger:consume`, the `worker` service) runs the handler.
- The filter has two stages, regardless of the source (email/browser/API): first a rough keyword filter (stack, etc.), and only what passes goes to the LLM via the API — saves tokens.
- LLM matching uses the platform prompt; the result is like/dislike with a reason.
- Full pipeline audit: everything scanned is saved in the DB, not just what made it to the end. For each job posting it records the stage it is at now or was cut off at (raw → keyword filter → LLM evaluation) and the reason for the cutoff (didn't pass the keywords / the LLM gave a dislike for such-and-such reason). I.e. a status pipeline with history, not a single final like/dislike field.

## LLM

- Configured in the UI: provider (enum), a separate model for each task (CV analysis / prompt generation / matching) and an API key.
- A cheap model for CV analysis.
- Keys are stored in the DB encrypted (the server is exposed to the internet) and shown masked in the UI.
- Each provider has its own adapter behind `LlmClientInterface`. We start with Gemini (free tier for testing).
- Every LLM call started from the UI (CV analysis, prompt generation, cover letter) runs in Messenger, not in the web request: the UI dispatches a message, the page shows "in progress" and refreshes a Turbo frame until the result is done or failed (a shared `LlmOperationStatus` enum). Reason: PHP's `max_execution_time` (30 s, measured as wall-clock time under FrankenPHP, so waiting for the API counts) and Cloudflare's ~100 s response limit would kill a slow call; the worker has no time limit and retries on API failures. Hence the `worker` service comes together with Messenger, not with the pipeline.

## Interface

- EasyAdmin — for settings and reference data (keys, models, platforms, prompts).
- Job posting work screens (a list of cards, a job posting card with letter/CV buttons) — custom Twig pages on Symfony UX (Turbo/Stimulus) via AssetMapper, without Node.js, responsive for phones.
- CSS for the custom pages — Bootstrap 5 via importmap: EasyAdmin is built on it too, so the work screens and the admin look alike; no build step; ready components (cards, badges, buttons, forms, a responsive grid).
- What the interface must do:
  - clearly sort/filter job postings;
  - view a job posting's processing history and the LLM decisions, manually correct LLM decisions;
  - add comments to a job posting;
  - mark "applied / not applied" — a separate field, not the same as like/dislike.
- Login — form login (Symfony Security), the account is created with a console command.
- Interface languages: English (the default) and Russian. Every UI string goes through the Symfony translator (`translations/`), no hardcoded texts in templates or code; EasyAdmin ships its own Russian translations. The language is a setting of the user stored in the DB (one field on `User`, a switcher in the UI): the same on the phone and the computer, unlike `Accept-Language`; no URL prefix, there's no SEO to serve. The language of generated texts (cover letter) is a separate decision, made in Phase 12.
- Console command output is English only, not through the translator: the CLI has no user locale, so a `ru` key would never be shown.

## Applications

- Cover letter: a button in the job posting card → the LLM writes a letter based on `CvProfile` + the job posting + the platform prompt → editing → copying. Simple (one LLM call), we do it before the tailored CV.
- CV tailored to a job posting (the market is like this now — each job posting needs its own CV version): a button in the job posting card → the LLM assembles a CV version for it → manual editing → PDF. Requires the full CV structure in the DB (experience per employer, etc.), the uploaded PDF becomes an import. A big feature — a separate phase after the core works.

## Infrastructure and deployment

- PHP entirely in Docker, no local PHP needed. DB — PostgreSQL.
- Docker: `.docker/php` + `compose.yaml` + Makefile, a separate `worker` service. Requirements: `php.ini`/`Caddyfile` in the repository, the Makefile doesn't fail without `.env.local`, `depends_on` waits for the Postgres healthcheck, the DB port is published only in dev, the official `dunglas/frankenphp` image (PHP 8.5, alpine).
- The `php` image is built from the repository root (`context: .`, `dockerfile: .docker/php/Dockerfile`): the Dockerfile copies `.docker/php/php.ini`.
- The DB port is published in dev only, so that the owner can inspect the DB with a local client: `compose.override.yaml` (merged by docker compose automatically) binds it to `127.0.0.1:POSTGRES_PORT`. Prod runs with `-f compose.yaml -f compose.prod.yaml`, so the override and the port don't get there; the server's `.env.local` sets `COMPOSE_FILE=compose.yaml:compose.prod.yaml`, so a bare `docker compose up` is safe too. The override stays committed (not `.dist`): with any `-f`, compose ignores it, and a `.dist` copy would need a manual step and go stale.
- `php.ini` is for dev (OPcache timestamp validation stays on); prod overrides it with `opcache.validate_timestamps=0`.
- The `php` container runs as a non-root user with the host user's UID, so that files in the bind-mounted project belong to the host user: `APP_USER`/`APP_USERID` in `.env` (defaults `app`/`1000`); `make init` writes the host's `id -u` into `.env.local` and stops with a clear error on UID 0 (otherwise the image build fails with an unclear `adduser: uid '0' in use`).
- Web server — FrankenPHP (Caddy + PHP in one container), Symfony in worker mode. Caddy serves plain HTTP: HTTPS is terminated by Cloudflare Tunnel. One container fewer — simpler for a self-hosted install.
- The `php` image inherits the base image's `HEALTHCHECK` (`curl localhost:2019/metrics`, the Caddy admin endpoint): the Caddyfile keeps the admin endpoint on `localhost:2019`; the `worker` service runs no Caddy, so its healthcheck is disabled.
- Deployment to a home server, access from a phone.
- The server is exposed to the internet through the Cloudflare Tunnel the owner already runs (it also serves `madbugs.dev`), subdomain `jobhunter.madbugs.dev`. No ports are opened on the router, Cloudflare provides HTTPS.
- We don't put `cloudflared` into our `compose.yaml` — it's the infrastructure of a specific server, not of the product. The README gets an example.
- Postponed: move the API layer to Go when/if Symfony becomes a performance bottleneck. Everything on Symfony first.

## Security

The server is exposed to the internet, so:

- `login_throttling` on the login form: 5 failed attempts per 15 minutes per email + IP, 25 per IP (Symfony's global limiter), with `symfony/lock`, so concurrent failed attempts aren't lost in a read-modify-write race of the limiter state (it doesn't stop a parallel burst, see below);
- an unknown email answers as slowly as a wrong password, so the owner's email can't be confirmed by timing: on the `main` firewall only, the submitted password is verified against a dummy hash made by the configured hasher (regenerated when the hasher reports it needs a rehash). The dummy hash is kept in the `cache.app` pool, not in the listener: making it costs a full hash, which in memory every PHP worker would pay again after each restart, making that failure slower than a wrong password. The submitted password goes to the hasher as a raw string, not a `PlainPassword`: a whitespace-only password, which `PlainPassword` rejects, must cost a verification too. Verifying the submitted password, not hashing a constant, takes the same shortcuts as a real check (a password over 4096 bytes is rejected without hashing). The owner's hash is rehashed on login when the hasher config changes (`PasswordUpgraderInterface`), otherwise a wrong password would be verified at the old cost and the dummy at the new one. The upgrade goes through `UserService`, so it is done by an own user provider (`App\Security\UserProvider`) that loads users through Symfony's entity provider: the entity provider hands the upgrade only to the repository, and a repository calling the service that depends on it would be a circular dependency. Until the owner's first login after a config change the costs still differ;
- a rate limiter on the job posting intake API;
- encryption of LLM API keys in the DB;
- `trusted_proxies` behind the tunnel;
- prod without debug;
- passwords and secrets (LLM API keys) travel as VOs (`PlainPassword`), and the parameters that take them are marked `#[\SensitiveParameter]`, so they don't end up in stack traces and error logs. The VO's value is private and read through a method: Monolog normalizes an object in the log context with `json_encode`, which dumps public properties. `dump()` still shows it, which is fine: it never goes to the logs and is for manual debugging; that's why there's no `__debugInfo()` masking.

Known limitations of the login throttling, accepted for a single user with a strong password (both come from how Symfony's limiter works):

- a parallel burst gets more than 5 attempts per window: Symfony only peeks the limiter before the password check and counts the failure after it, so requests running at the same time all pass the check (about as many as PHP handles concurrently, ~8–16);
- requests rejected by the CSRF check still count as failures: a page the owner opens can send 25 cross-site login POSTs with any emails (5 with the owner's) and lock the owner out from their IP for 15 minutes, again on every visit; knowing the email isn't needed.

For a multi-user version (an idea, not planned): a custom limiter that counts an attempt before the password check and ignores CSRF failures; a shared limiter store (Redis or PostgreSQL); and a fresh look at an email-only limit, which with many users turns into a way to lock others out.

The API docs (`/api/v1/docs`) stay public in prod, as a guide to sending job postings: the repository is public anyway, and the intake is protected by the API key and the rate limiter, not by hiding the endpoints. Only the intake API goes into the docs; any internal endpoint is hidden from them or put behind the login. Public access means the docs only, not the endpoints: in `access_control` only `/api/v1/docs` is open, and everything else under `/api/v1` is closed by default, so a new API resource isn't callable anonymously until a rule explicitly opens it (the intake endpoint opens with the API key).

## Dev tooling and quality

- Static analysis: PHPStan level 10 + `phpstan-symfony` + strict-rules + deprecation-rules.
- Tests: PHPUnit 12 + `symfony/phpunit-bridge`. Mocks — standard PHPUnit ones.
- What gets tested is logic, not data containers: a VO with validation or normalization is tested, a VO or DTO without logic isn't. Area services: unit tests with every dependency (repositories, adapters) mocked. Controllers and console commands: functional tests with the area service mocked in the container.
- A test database only where a mock can't check anything: repository queries and mapping. A separate `_test` DB filled from fixtures and rolled back after each test (`dama/doctrine-test-bundle`); set up once the first such query appears. Tests never touch real (dev or prod) data.
- `assert()` only narrows a type for static analysis where the framework already guarantees it (e.g. `APP_ENV` in `public/index.php`). Never for invariants or input checks: prod runs with `zend.assertions = -1`, where asserts aren't even compiled; domain invariants throw domain exceptions.
- Style: PHP-CS-Fixer with our own config; the `return` rules (a blank line before `return`, a useless variable before `return`) are covered by it (`blank_line_before_statement`, `return_assignment`) with auto-fixing. PHPCS — only for the 120 line length limit (CS-Fixer doesn't check it).
- Every non-abstract class is `final`, enforced by PHP-CS-Fixer `final_internal_class` (empty `include`/`exclude`, `consider_absent_docblock_as_internal_class`). Entities included: Doctrine uses native PHP lazy objects (always on in DoctrineBundle 3 on PHP 8.4+) instead of proxies that subclass the entity, so `final` doesn't break lazy loading.
- Rector, composer-normalize.
- `symfony/maker-bundle` — boilerplate generation (`make:entity`, `make:migration`, `make:message-handler`, etc.), a key thing for writing code autonomously.
- `symfony/browser-kit` + `symfony/css-selector` — functional tests of HTTP endpoints and pages (including EasyAdmin).
- `fakerphp/faker` — fake data for fixtures/tests.
- `symfony/http-client`, `symfony/twig-bundle`, `api-platform/core` are runtime dependencies (`require`), not dev.
- Package versions — current for the Symfony/PHP version at bootstrap time.
- Manual check commands are Makefile targets (`make cs`, `make fix`, `make lint`, etc.), not Composer scripts: a short command from the host, one entry point next to `init`/`upd`.
- All checks (PHPStan, PHPUnit, PHP-CS-Fixer, Symfony linters) run automatically on commit via GrumPHP; the commit doesn't go through if the checks are red.
- GrumPHP runs inside the `php` container (via `docker compose exec`) and calls `git` there, so the `php` image includes `git`.

## Development process

- The "writer + reviewer" scheme: a strong model writes; the first filter is free deterministic checks (they catch mechanical errors without an LLM); the second filter is a review by a different, independent model (not necessarily more expensive than the writer). Reason: a review by the same model that wrote the code inherits its blind spots. The specific agents, models and effort are in CLAUDE.md, the "Model selection" section.
- Model switching happens without manual actions by the owner: the main orchestrator session delegates to subagents itself. The owner just asks to do a task.
- Claude can't run `/code-review ultra` (multi-agent cloud review) itself — the owner runs it (paid); Claude only suggests it for important parts.
- Tests are written by Claude too, in the same "code → checks → review" cycle. A test is mandatory for every business branch/rule (`JobPostingStageLog` status transitions, keyword filter edge cases, CV schema validation, prompt generation, LLM integration via mocks at the boundary). The coverage % is a signal for ourselves, not a hard threshold.
- Rule files: `AGENTS.md` is the single source of the project rules, for people and any AI tool; `WORKFLOW.md` is the project-independent working method; `CLAUDE.md` is only the Claude Code entry point (imports both, plus the Claude-only model and agent rules); `CONTRIBUTING.md` is a pointer for people. The owner's personal rules (chat language, local translations of the docs) live in an uncommitted `CLAUDE.local.md`, which Claude Code loads by itself.
- AGENTS.md holds only what the tools don't do: `strict_types`, style, `readonly`, `mixed` are covered by CS-Fixer/Rector/PHPStan — we don't duplicate them in the rules.

## Code structure

- What Symfony has a convention for stays where Symfony puts it: `src/Entity`, `src/Repository`, `src/Controller`, `src/Command`, `src/MessageHandler`, `src/ApiResource`, `src/DataFixtures`. maker-bundle and the recipes keep working without extra configuration.
- `src/Domain` — framework-free domain code Symfony has no place for, grouped by area: Value Objects, domain exceptions, status enums, domain events, area services. An exception sits next to what it belongs to: `Domain/Shared/Email.php` + `InvalidEmail.php`, `Domain/JobPosting/JobPostingStatus.php`.
- One service per area: `src/Domain/<Area>/<Area>Service` (`Domain/User/UserService`). It holds the operations that need something outside the entity: the DB, the password hasher, the LLM, several entities at once. An entity's own behavior and invariants stay in the entity: the service calls `$posting->markFiltered()`, it doesn't set the status itself. One large service per area rather than a class per use case: one standard for the whole project and fewer files at this size; an area service is split only once it actually gets in the way.
- Every data change (create, update, delete) goes through the area service. Controllers, console commands, Messenger handlers and API Platform processors are thin: parse the input, call the service, render the result. Reading for display may go straight to a repository.
- Service methods take VOs and DTOs, never raw strings (`create(Email $email, PlainPassword $password)`): parsing the input is the entry point's job, so the entry point builds the VOs and handles their validation errors, and the service can't receive invalid data, the type guarantees it.
- Repositories: queries plus `save()` (`persist` + `flush`), no logic. Whatever has to change on save (a status, for example) is decided by the service and the entity before `save()`, never inside the repository. `save()` covers both create and update: Doctrine ignores `persist()` on an already managed entity, and `flush()` writes the changes; there is no separate `update()`.
- An interface lives next to its implementation: `UserServiceInterface` next to `UserService`, `UserRepositoryInterface` next to `UserRepository` in `src/Repository/`, an adapter's interface next to the adapter in `src/Infrastructure/`. Dependents (controllers, commands, services) use the interface. Reason: every class is `final`, and PHPUnit can't double a final class, so whatever gets mocked needs an interface.
- `src/Infrastructure` — adapters for external integrations: the Gemini client, platform clients, key encryption, the password hasher. Ideally extractable into a PHP package later.
- No `Application` layer: use cases live in the area services. The trade-off: entities belong to the domain but stay in `src/Entity`, apart from their VOs and exceptions.

## Rejected alternatives

- Multi-user with registration and platforms/prompts/job postings tied to the user → a single user, self-hosted.
- SaaS → self-hosted (`git clone` + `docker compose up`).
- A prompt per "user + platform" pair → a prompt per platform (there is one user).
- One global prompt → a separate prompt for each platform.
- A per-user API key for job posting intake → one key from the settings.
- RabbitMQ (`symfony/amqp-messenger`) → the Doctrine transport in PostgreSQL: one container fewer for self-hosted, switched with a single DSN line.
- The DB port is never published → published in dev only (`compose.override.yaml`, on `127.0.0.1`): the owner inspects the DB with a local client; prod skips the override.
- nginx + PHP-FPM → FrankenPHP: one container instead of two, the official Symfony Docker setup, worker mode.
- Synchronous LLM calls in the web request with a raised `set_time_limit` → Messenger: Cloudflare cuts a response after ~100 s anyway, there are no retries, and a long load hangs on the phone.
- VPN (Tailscale/WireGuard) → the already running Cloudflare Tunnel.
- Cloudflare Access on top of our login — not enabled.
- `cloudflared` in our `compose.yaml` → no, it's the infrastructure of a specific server; an example in the README.
- A volume (or `COMPOSER_CACHE_DIR`) for the Composer cache → not taken: the project is installed once, and losing the cache when the container is recreated costs only an extra minute of `composer install`; a dedicated volume is YAGNI.
- Storing LLM API keys in the DB as is → encryption, since the server is on the internet.
- EasyAdmin as the main interface for everything, including job postings → only settings and reference data; work screens are custom pages, responsive for phones.
- Choosing the interface language by `Accept-Language` or a URL prefix (`/en/`, `/ru/`) → a user setting in the DB: the same language on every device, no routing complexity for a single user.
- Tailwind / Pico CSS / own CSS → Bootstrap 5: Tailwind without Node.js needs `tailwind-bundle` with its own binary and a `--watch` process, and would look different from the Bootstrap-based EasyAdmin; Pico is niche and may stall; own CSS means writing everything by hand.
- Code split by type only (`src/ValueObject`, `src/Exception`) → `src/Domain` grouped by area: exceptions of every area would pile up in one folder.
- Full modules (`src/User/`, `src/JobPosting/` with entities inside) → Symfony's own folders stay: maker-bundle, recipes and Doctrine/API Platform mapping expect them.
- Full DDD layers with `src/Application` → no: an extra layer of use-case classes adds no value at this size; the area service orchestrates.
- Use-case orchestration in controllers, console commands and Messenger handlers → in the area service: entry points stay thin, the logic is unit-tested with mocks.
- A class per use case (`CreateUser`, `ChangePassword`) → one service per area: one standard and fewer files at this size.
- Ports in `src/Domain` (`LlmClientInterface` in the domain) → an interface next to its implementation: one rule for services, repositories and adapters, easier to find.
- A service depending on concrete repositories, tested against a test DB → interfaces and mocks: a `final` class can't be doubled, and a test DB is only for queries.
- Repositories for reading only, saving through `EntityManager` in the service → `save()` in the repository, without logic: otherwise Doctrine ends up in the domain service.
- Tests on every VO and DTO → only on the ones with logic.
- Project rules in `CLAUDE.md` → `AGENTS.md`: only Claude Code reads `CLAUDE.md`, while `AGENTS.md` is the common file of AI tools and readable by people.
- `CONTRIBUTING.md` as the source of the rules → a pointer: agents load `AGENTS.md` as content but only see a link to another file.
- The owner's personal rules in a committed file → `CLAUDE.local.md`: anyone cloning the public repository would get them in their AI tool.
- SPA (React/Vue) → Twig + Symfony UX via AssetMapper, without Node.js: an SPA is overkill for a single user.
- A CSV tracker → only the DB.
- Sending every job posting to the LLM → a keyword filter first, saves tokens.
- Only a final like/dislike field → a status pipeline with history and the cutoff reason.
- Arbitrary CV analysis JSON at the model's discretion → a fixed schema via structured output.
- A JSON column/JSON blob for structured CV data → regular columns and relational tables (`Skill`, `CvLanguage`).
- A PDF text extraction library → the PDF goes to the LLM directly.
- DOCX → not now, PDF only.
- Email alerts as a job posting source (and parsing the email format) → not now; for now manual collection via Claude Code.
- Telegram notifications (and their format/buttons) → not now, together with automation.
- phpcs + slevomat for the `return` rules → PHP-CS-Fixer with auto-fixing; PHPCS only for line length.
- A hand-written pre-commit hook → GrumPHP.
- parallel-lint → not taken, PHPStan covers it.
- roave/security-advisories → not taken, `composer audit` is enough.
- Prophecy (`jangregor/phpstan-prophecy`, `phpspec/prophecy-phpunit`) → standard PHPUnit mocks.
- A formal coverage % as a hard gate → a mandatory test for every business rule: a % breeds useless tests on getters/DTOs/configs.
- "Cheap writer / expensive reviewer" or the other way round; a review by the same model that wrote the code → a strong model writes, a different independent model reviews.
- Duplicating in the rules (AGENTS.md) what the tools check → not duplicated.
- Testing Symfony's own login limiter behavior (a different email from the same IP isn't blocked by the per-email limit, a successful login resets the counter) → not tested. Tests cover our logic, not the framework's: the key and the reset live in `DefaultLoginRateLimiter`, which Symfony tests itself, and such tests would break on a Symfony upgrade with no change in our code. What is ours is the config (5 attempts, 15 minutes, on `main`, blocks even the right password), and that is tested. A custom limiter (e.g. a different key for a multi-user version) would be our code and gets tested.

## Inbox

The owner writes new thoughts here as is, without structure. After discussion they are moved into the sections above (superseded decisions go to "Rejected alternatives" with the reason), and this section is cleared.

- Messenger has no `failed` transport: after the retries (3 by default) a failed message is lost. For LLM calls it may be worth keeping them to inspect and retry (`failure_transport` + `doctrine://default?queue_name=failed`).
- Mercure (built into FrankenPHP) is undecided: it could push live updates to the page (an LLM operation finished, new job postings arrived) instead of refreshing a Turbo frame until done. Turbo's Mercure parts are off for now (`assets/controllers.json`).
- Unused translation keys (in `translations/messages.*.yaml` but used nowhere) aren't caught: `debug:translation --only-unused` lists them, but keys built dynamically in code (e.g. from an enum value) would show up as unused too. Decide whether to check this and how.
- Enforce the layer boundaries with a tool (`src/Domain` must not use `Symfony\*`, `Doctrine\*` or implementations from `App\Repository\*`/`App\Infrastructure\*`, only their interfaces): PHPat (a PHPStan extension) or Deptrac (has a GrumPHP task). Decide later, once there is working code to see how the services shape up.
- Building a VO from raw input needs a `try/catch` at every entry point (`Email::fromString()` throws `InvalidEmail`; the login loader `UserRepository::loadUserByIdentifier()` must return null, not 500). It is easy to forget at a new entry point. Options: `Email::tryFromString(): ?self` where invalid means "not found"; a shared exception listener mapping domain validation errors (`InvalidEmail`, etc.) to 422 for the API; DTOs validated by `Assert\Email` before the VO is built. Decide once forms/API DTOs appear.
- Dev demo data, once there is domain data (postings, CV, criteria): a separate `DevFixtures` with `#[When(env: 'dev')]` and a `make demo` target loading it with `--append` (never purges; `UserService` refuses if a user exists). A bare `doctrine:fixtures:load` in dev still purges, so decide explicitly that the dev instance holds no real data (the owner uses a separate prod deployment).
- The login limiter keys on the client IP, and the app sits behind a reverse proxy (`cloudflared` now, possibly an AWS load balancer or another host later). Until `trusted_proxies` is set for that proxy, every request comes from the proxy's address, so the per-IP limit (25 failures per 15 minutes) is shared by everyone and an attacker can lock the owner out. The `trusted_proxies` task must land before any deployment. Once the real client IP is known: an attacker rotating addresses gets 5 attempts per address, and an IPv6 /64 is effectively unlimited addresses; decide then whether to count a whole /64 as one address (a custom request rate limiter). An email-only limit is rejected for a single user: an attacker could lock the owner out at will.
- The login limiter counters live in the filesystem cache (`cache.app`) and the lock is `flock`: fine for one `php` container and a single user. The counters are lost when the container is recreated (a deploy gives an attacker 5 fresh attempts, which doesn't matter for a single user). For a multi-user version, or as soon as there is more than one PHP instance (replicas behind a balancer, a separate container also serving HTTP), each instance would count and lock on its own, multiplying the limit and losing the race protection. Then move the limiter storage (`login_throttling.cache_pool`) and `LOCK_DSN` to a shared store: Redis, or PostgreSQL (`cache.adapter.doctrine_dbal` + a DBAL lock) without a new service. Config only, no code changes.
- The owner can't change their password: the user is only created by `UserService::create()`. Decide how: a form in the web interface (current password + new one, logging out other sessions) and/or a console command for a forgotten password.
- An idea to look at only if the login lockout becomes a real problem: Cloudflare Access (Zero Trust) in front of the app. The tunnel lets a request through only after Cloudflare's own login, so login brute force and the lockout above don't reach the app at all. The job posting intake API would then need an Access service token. Not a replacement for our own login and `trusted_proxies`: the self-hosted product must not depend on Cloudflare.
- `ApiKey` trims surrounding whitespace, unlike `PlainPassword`, which keeps the input as given: a pasted key with a trailing newline would break the HTTP header, and real keys never start or end with whitespace. Confirm.
- The job posting intake API key (from the settings): reuse `App\Domain\Llm\ApiKey` (then it moves to `Domain/Shared`) or give it its own VO.
- LLM key encryption as built: `LlmService` encrypts through `ApiKeyEncryptorInterface` (Infrastructure, sodium secretbox), the entity holds only `EncryptedApiKey`. A custom Doctrine type was rejected: it has no DI and would need the encryptor in a static property set at boot. Move into "LLM" once confirmed.
- `LLM_KEY_ENCRYPTION_KEY` lives only in `.env.local` (`make init` / `make encryption-key` generate it and never overwrite it). Losing or changing it means re-entering the LLM keys, so the server's `.env.local` belongs in the backups (README, deployment section).
- No key version in the ciphertext: rotating `LLM_KEY_ENCRYPTION_KEY` means re-entering the LLM keys. Acceptable for a single user with a couple of keys?
- EasyAdmin CRUD for provider keys is built with a fixed mask, never decrypting (the list works even if `LLM_KEY_ENCRYPTION_KEY` is lost); the last 4 characters through `LlmService::findApiKey()` were not taken. Move into "LLM" / "Rejected alternatives" once confirmed.
- The provider key edit form requires a new key: an empty field is a validation error, not "keep the current one" (the form has only that field, so "keep" would make saving a no-op). Confirm.
- The provider key CRUD overrides EasyAdmin's `new()`/`edit()` with a Symfony form on a DTO (the entity holds only ciphertext and has no no-argument constructor). This skips EasyAdmin's `EA_EXECUTE_ACTION` check and its Before/AfterEntity* events: fine for one user behind `ROLE_USER`, revisit if EasyAdmin permissions or event subscribers appear.
- Enum labels are dynamic translation keys (`llm.provider.<value>` through `LlmProviderKeyType::providerLabel()`); the model selection CRUD needs the same for `LlmTask`. Decide one shared place for enum labels (not the framework-free domain enum).
- `git push` failed from Claude's session: GitHub asks for a username over HTTPS (no credential helper / token on this machine). Set up `gh auth login` or an SSH remote so the "commit + push" flow works without the owner.
- `LlmRequestFailed` doesn't tell a permanent failure (400, invalid key 401/403, safety block) from a transient one (429, 5xx, network): Messenger would retry an invalid key 3 times. Decide in the first Messenger LLM handler task: a flag or a separate exception mapped to `UnrecoverableMessageHandlingException`. `CvService::analyze()` lets every `LlmRequestFailed` propagate with the profile still pending.
- `LlmRequest.systemInstruction` was added by the developer agent's own judgement: it keeps the platform prompt apart from the untrusted job posting text in matching (prompt injection). Confirm or drop it (matching would then put everything into `prompt`).
- `LlmRequest` carries one attachment; it becomes a list only if a request ever needs several files.
- `GeminiClient` sends the PDF inline as base64; Gemini caps an inline request at ~20 MB. Enough for a CV; bigger files would need Gemini's Files API. The CV upload limit (10M) keeps it under the cap.
- `GeminiClient` trims the model's text, skips `thought` parts, and treats any `finishReason` other than `STOP` (MAX_TOKENS included) as a failure. Confirm.
- A model name entered with the `models/` prefix (`models/gemini-2.5-flash`) gets URL-encoded and fails as HTTP 404. Strip the prefix or reject it in `LlmModel`?
- Gemini structured output uses `generationConfig.responseJsonSchema` (standard JSON Schema, so the same `JsonSchema` is validated with justinrainbow later); the older `responseSchema` is deprecated, the newer `responseFormat.text.schema` is documented only for the Interactions API. Untried against the live API: confirm with the key check command.
- Gemini enforces only part of JSON Schema (`type`, `properties`, `required`, `enum`, `items`, `anyOf`, `$ref`, `minimum`/`maximum`, `minItems`/`maxItems`, `format`, …); `pattern`, `minLength` etc. are caught only by our own validation. The CV analysis schema is written that way (`pattern`/`maxLength` on skills and the domain VO checks are ours only); the matching schema still has to be. Unknown: whether Gemini ignores or rejects keywords it doesn't support.
- The "task → client + model" resolver is `LlmService::generate(LlmTask, LlmRequest)`, not a separate class. "Settings missing" vs a retryable `LlmRequestFailed` is told apart by the area service, not the handler, so no shared parent exception: `CvService::analyze()` catches the settings exceptions (`LlmModelNotSelected`, `LlmClientNotFound`, `ApiKeyNotSet`, `ApiKeyDecryptionFailed`, `InvalidEncryptionKey`) itself. Picked by the CV analysis service task; confirm.
- LLM clients are picked from a tagged iterator, not a service locator: PHPStan's Symfony extension checks `get('gemini')` against the main container and reports it unknown. The iterator instantiates every client before the match; switch to a locator once there are several providers.
- `app:llm:check <task>` checks a task's model and key with a plain text request; the dev DB has no key or model selected yet, so nothing has been tried against the live Gemini API. It doesn't check structured output (`responseJsonSchema`); the first real CV analysis will.
- `PlatformAccessType` values were picked without discussion: `api`, `rss`, `browser` (scraping, by Claude Code now or a headless browser later). No `manual`: how postings are collected today (Claude Code) is not a property of the platform. Confirm, or adjust once `platforms/*.md` are written.
- The platform fixture is test-only, like `AppFixtures`: `doctrine:fixtures:load` purges the DB, so it can't be the way platforms reach dev or prod. A fresh install (and the owner's prod) still has no platforms. Options: a data migration inserting the four (but `make test-db` purges it and reloads fixtures, so the fixture stays for tests), an EasyAdmin CRUD for platforms (reference data, see "Job sources and platforms"), or a console command. Decide before the first task that needs a platform outside tests (the `Prompt` entity).
- All four fixture platforms are `browser` for now: today Claude Code collects postings in a browser everywhere. Revisit per platform in `platforms/*.md` (Upwork has an API that needs approval).
- Skill names are stored in a canonical form, the `SkillName` VO (`src/Domain/Skill`): whitespace runs (NBSP included) become one space, trimmed, lowercased (`mb_strtolower`), 1 to 100 characters; `Skill` takes only a `SkillName`. So the plain unique index on `skill.name` works and the keyword filter compares lowercase too; the price is that the UI shows "postgresql", not "PostgreSQL". A unique index on `LOWER(name)` was not taken: Doctrine can't map a functional index, so `doctrine:schema:validate` and `migrations:diff` would keep wanting to drop it. The parser still returns raw names; `CvService::analyze()` canonicalises and dedupes them, and a name left blank after that fails the analysis like an invalid response. Confirm.
- `CvProfile` keeps only two strings for the original file: the stored name (a bare generated file name, unique, no directory: the storage directory comes from config, so moving it doesn't touch rows) and the name the owner uploaded (for display and download). No MIME type (PDF only) and no size (the filesystem has it). The upload task generates the stored name.
- `CvProfile` analysis transitions: only pending → done and pending → failed, both final. Re-running an analysis (after a failure, or with another model) would need a `restartAnalysis()` or a new `CvProfile`. Decide with the upload task: a new `CvProfile` per upload (the latest is current) or one profile replaced on re-upload.
- A failed CV analysis keeps the raw LLM response when there is one (a response that failed the schema is what needs debugging), but there is no error message column: the reason only goes to the logs. Add one if the analysis view page must show why it failed.
- `Seniority` values were picked without discussion: `junior`, `middle`, `senior`, `lead`. Confirm (`intern`, `principal`?). The CV analysis schema takes them from the enum, so a change is a code change, but stored rows keep the old values.
- `SalaryExpectation`: positive whole amounts, at least one bound, min ≤ max, a three-letter currency (format only, not checked against the ISO 4217 list). No period column: the CV analysis schema asks the model for a monthly amount, converting an hourly or yearly figure (picked without discussion). Comparing with hourly postings (Upwork) would then convert at match time. Confirm, or add a period (enum + column + migration) instead.
- `CvAnalysis`: only the two texts are required; `yearsExperience`, `seniority` and the salary are nullable, since a CV may not state them. `yearsExperience` is whole years, 0 to 70: the upper bound catches a year (2015) returned instead of a count.
- `CvLanguage` as built: the language is an ISO 639-1 code (`en`, lowercase), the level a `LanguageLevel` enum: CEFR `a1`–`c2` plus `native`. Picked without discussion; confirm (the CV analysis schema now takes the levels from the enum). A CV listing a language twice is rejected by `CvAnalysis` (unique `(cv_profile_id, language)`).
- `CvLanguage` rows are created only by `CvProfile::markAnalysisDone()` from `CvAnalysis::$languages` (the profile is the aggregate root, cascade persist, no repository). `CvAnalysis::$languages` defaults to an empty list.
- `CvProfile` ↔ `Skill` is unidirectional (`Skill` knows nothing about profiles: it is a shared tag). Skills are attached only through `markAnalysisDone(CvAnalysis, list<Skill>, raw)`: the analysis service resolves names to `Skill` entities (find or create) and passes them in; a repeated skill is attached once.
- `CvFile` original name as built: normalised, not rejected, so an odd name never blocks an upload: invalid UTF-8 bytes become `?` (`mb_scrub`), each run of control or invisible format characters (`\p{Cc}`, `\p{Cf}`, line/paragraph separators: NUL, newlines, a right-to-left override, zero-width spaces) becomes one space, then Unicode spaces (NBSP included) are trimmed; ZWJ/ZWNJ (Persian, Indic names, emoji) are replaced too, accepted for a single user; only a name left empty (or over 255 characters) is rejected, shown as a form error.
- Picked by the upload task: a new `CvProfile` per upload, the latest (highest id) is the current CV. No `restartAnalysis()`: re-running the analysis means uploading again. Older profiles and their files are kept, nothing cleans them up. Resolves the "new profile vs replaced" question above; confirm.
- CV files are stored in `app.cv_storage_dir` (`%kernel.project_dir%/var/storage/cv`, `config/services.yaml`): outside `public/`, and in dev under the bind mount, so it survives container recreation. The directory is created `0700`, stored names are `<32 hex chars>.pdf`. A prod deployment without the bind mount needs a volume at that path, and it belongs in the backups.
- The upload moves PHP's temporary file with `Filesystem::rename()` (the path travels in the `CvUpload` VO), not `UploadedFile::move()`, so the domain service doesn't depend on HttpFoundation; the "is it a real upload" check is done earlier by the `File` constraint (`UploadedFile::isValid()`). Confirm.
- If saving the `CvProfile` fails after the file was moved, the file stays in the storage as an orphan (no cleanup). Acceptable for a single user?
- The upload form uses Symfony's built-in validator messages (the `validators` domain, already translated into Russian), not keys in `messages.*.yaml`: the wrong-type error names the detected MIME type. Friendlier texts would need `translations/validators.{en,ru}.yaml`. Confirm.
- After an upload the page redirects back to `/cv` with a flash message; it doesn't show the current CV yet (the analysis view page task will). Flash messages are now rendered by `base.html.twig` (`#flash-messages`).
- `phpunit.dist.xml` sets `memory_limit` to 256M: with a cold test cache the container is compiled inside the test process and the suite already peaked at ~123M before this task; the PDF MIME check (`finfo`) pushed it over 128M now and then.
- The CV analysis schema is built in PHP (`CvAnalysisResponseParser::schema()`), not a JSON file: the enum values come from `Seniority`/`LanguageLevel`, the bounds from `CvAnalysis::MAX_YEARS_EXPERIENCE` and `Skill::MAX_NAME_LENGTH`. The same `JsonSchema` goes into the LLM request and the validation.
- CV analysis schema fields (snake_case): `original_language_text`, `english_text`, `skills[]`, `years_experience`, `seniority`, `languages[{language, level}]`, `salary_expectation{min, max, currency}`. All required; an unknown value is `null` (`anyOf` with `{"type": "null"}`) or an empty list, so the model has to address every field; `additionalProperties: false` everywhere. Untried against the live Gemini API: `anyOf` + null and `additionalProperties` in `responseJsonSchema`.
- The schema has short `description`s as field hints for the model (ISO codes, whole years, monthly salary); the analysis prompt itself is `CvService::ANALYSIS_PROMPT` (English, a few sentences, no `systemInstruction`: the CV is the owner's own file, not untrusted text).
- justinrainbow sits behind `JsonSchemaValidatorInterface` (`Infrastructure/Llm`, reusable for the matching schema), default check mode (draft-04 semantics, no `$schema` keyword); it returns a list of messages, not an exception. The parser's tests use the real validator: the schema is what they test.
- `InvalidCvAnalysisResponse` carries the error list but not the raw response: the caller already holds the LLM text to store on failure. The domain VO errors (`InvalidCvAnalysis`, `InvalidLanguageProficiency`, `InvalidSalaryExpectation`) become the same exception, with the original as `previous`.
- `SalaryExpectation::MAX_AMOUNT` is 2,000,000,000 (the `salary_min/max` columns are 32-bit `INT`; a huge model answer would otherwise fail at flush as a DB error). The CV schema caps `language` and `currency` at 16 characters only to keep a runaway string out of exception messages; their formats are checked by the VOs.
- `CvService::analyze(CvProfile)` contract, for the handler task: it takes the profile, not an id (the handler loads it and decides what a missing profile means). A non-pending profile throws `CvAnalysisAlreadyFinished` before anything is read or called (a redelivered message: acknowledge it). A permanent failure (invalid response or skill name: raw kept; settings missing; stored file unreadable or empty) marks the profile failed, saves it and throws `CvAnalysisFailed` with the cause as `previous` (the handler maps it to `UnrecoverableMessageHandlingException`, which also logs the reason). `LlmRequestFailed` propagates with the profile pending, so Messenger retries.
- After the last Messenger retry of a failed LLM request the CV profile stays pending forever (the status page would spin). The handler task needs a way to mark it failed: a `WorkerMessageFailedEvent` listener when `willRetry()` is false, calling a new service method.
- Missing LLM settings mark the CV analysis failed, not pending: with no `restartAnalysis()`, the owner fixes the settings and uploads the CV again. Confirm.
- New skills are saved one by one before the profile (`SkillRepository::save()` flushes each): if the profile save then fails, the skills stay (harmless shared tags). Two analyses creating the same new skill at once hit the unique index; the Messenger retry then finds it. Acceptable with one worker.
- `CvStorage::read()` wraps Symfony's `IOException` into `CvFileUnreadable` (next to the storage), so the domain service doesn't catch a Symfony exception. `store()` still throws `IOException` as before.
- `CvProfile::assertAnalysisPending()` is now public: `CvService::analyze()` checks it before spending an LLM call, instead of repeating the status rule.
- For the CV analysis handler task (from the review of `CvService::analyze()`): a DB error while saving skills or the profile (e.g. a unique violation on a skill created concurrently) propagates with the profile pending and closes the EntityManager. A normal Messenger retry is safe (Doctrine is reset between messages), but a `WorkerMessageFailedEvent` listener marking the profile failed after the last retry runs before that reset: it must `resetManager()` and reload the profile. The handler must load the profile fresh on every attempt. Race-free alternative: create skills with `INSERT … ON CONFLICT (name) DO NOTHING`, then `findByNames()`.
- A NUL byte in the analysis texts or the raw LLM response would fail the insert (PostgreSQL text rejects NUL) and escape as a DB error; `SkillName` already replaces control characters. Strip NUL in the parser/`CvAnalysis` if it ever shows up.
- One unusable skill name (blank after normalising, or longer than 100 characters after lowercasing, e.g. "İ" grows) fails the whole analysis. Skipping just that skill would be friendlier; decide if it happens in practice.
- Missing LLM settings (no model, key or client) mark the CV analysis failed for good; the likely first-run mistake (uploading the CV before entering the key) then needs a re-upload. Confirm, or make the upload page warn when the CV analysis task isn't configured.
- Picked by the CV analysis handler task (resolves the `LlmRequestFailed` permanent vs transient line): every `LlmRequestFailed` is retried, an invalid key or a safety block included; a few extra failing requests (and, for a block, 3 more PDF sends) are cheap for one CV. Split it only if it costs real money or time once matching runs at volume.
- The `async` transport keeps Messenger's default retry strategy (3 retries after 1, 2 and 4 s): a 429 rate limit (per minute on Gemini) is unlikely to clear in 7 s. Raise the delay (e.g. 10 s × 3) if 429s show up.
- The upload controller dispatches `AnalyzeCv` (only the profile id; the handler loads it), not `CvService::upload()`: the domain service stays free of Symfony Messenger. Another upload entry point (API) has to dispatch too. If the dispatch fails after the upload the profile stays pending with no message. Confirm.
- After the last retry `CvAnalysisRetriesExhaustedListener` (`WorkerMessageFailedEvent`, `willRetry()` false) resets the entity manager, reloads the profile and calls `CvService::giveUpAnalysis()`, which marks a still pending analysis failed (no raw response) and leaves a finished one alone (`CvAnalysisFailed` already marked it). Resolves the "stays pending forever" line. If the DB itself is down the listener throws, the worker exits (Docker restarts it) and the message is redelivered after the Doctrine transport's `redeliver_timeout` (1 h).
- `AnalyzeCvHandler`: a missing profile and `CvAnalysisFailed` become `UnrecoverableMessageHandlingException` (logged by the worker, no retry); `CvAnalysisAlreadyFinished` (a redelivered message) is acknowledged silently. With no `failure_transport` a message is dropped after its last retry; the profile's failed status is what remains.
- The `worker` container runs the code it started with: after changing a handler, restart it (`docker compose restart worker`), or `messenger:consume` keeps the old code. A `make` target or `--time-limit` could take care of that.
- Reviewer of the CV analysis handler task: the upload controller now orchestrates (upload, then dispatch), which stretches the "thin controller" rule, and a second entry point can forget the dispatch. Alternative: a `CvAnalysisDispatcherInterface` port called by `CvService::upload()` (Messenger adapter in Infrastructure). Decide together with the "controller dispatches" line above.
- CV analysis view page as built: `/cv/{id}`, the upload redirects there. While pending, a `frame-reload` Stimulus controller (`assets/controllers/frame_reload_controller.js`, reusable for the prompt draft) reloads the `cv-analysis` Turbo frame every 3 s from the same page URL; done/failed render no controller, so the polling stops. No polling limit: a profile stuck pending would poll forever while the page is open. The "CV" navigation link still opens the upload form, which doesn't link to the current (latest) CV yet; decide whether `/cv` should show the current analysis with the upload form below it.
- `Prompt` as built: one row per platform (unique one-to-one, deleted with the platform), created by the first generation request (`new Prompt(Platform, CvProfile)`, status pending, no text yet). `text` + `source` are the prompt in use; `generationStatus` + `cvProfile` describe the latest generation: a regeneration keeps the old text until the draft is ready, and a failed one keeps it for good (so after a failed regeneration `cvProfile` is newer than the text). Done replaces the text and sets `generated`, `edit()` sets `edited`. Picked without discussion; confirm.
- `Prompt` invariants as built: a generation is requested only from a CV profile whose analysis is done (`CvProfileNotAnalysed`); no second request and no `edit()` while a generation is pending (`PromptGenerationInProgress`: the draft would overwrite the edit); finishing a non-pending generation throws `PromptGenerationAlreadyFinished` (a redelivered message, like the CV analysis). A regeneration overwrites a manual edit without asking: should the "Generate prompt" button warn when the source is `edited`?
- `PromptText` VO: trimmed, non-empty, no length limit (TEXT column). `Prompt` keeps no raw LLM response, unlike `CvProfile`: the generation task decides whether the response is plain text (then the raw response is the text) or JSON worth keeping on failure.
- A prompt can't be created without a generation (no EasyAdmin "new"): manual editing applies to an existing prompt, or to one whose first generation failed. Confirm for the EasyAdmin task.
- Platforms still reach only the test DB (fixture): the "Generate prompt" button task needs them in dev/prod, so the platform fixture line above has to be decided before it.
- Reviewer of the `Prompt` entity task, for the prompt generation task: a prompt stuck in pending (message lost, retries exhausted) blocks both regeneration and editing for good, so the generation handler needs a retries-exhausted listener marking it failed, like `CvAnalysisRetriesExhaustedListener`. And after a hand edit following a failed generation the status stays `failed` while the text is usable: the UI and matching decide "has a usable prompt" from the text, not the generation status.
- `PromptService::requestGeneration(Platform)` takes the newest CV profile whose analysis is done (`CvProfileRepositoryInterface::findLatestAnalysed()`), skipping a newer pending or failed upload; with none it throws `AnalysedCvProfileNotFound`. The alternative was only the latest upload, refused unless done. Confirm.
- Prompt generation dispatch, like `AnalyzeCv`: the entry point (the "Generate prompt" button's controller) calls `requestGeneration()`, then dispatches `GeneratePrompt` with the returned prompt's id. If the dispatch fails, the prompt stays pending with no message, and the retries-exhausted listener never fires (no message failed): regeneration and editing stay blocked for good. Decide with the button task (a dispatch port called by the service, or a way to unstick a pending prompt).
- The prompt generation output is plain text, no response schema: the response (trimmed) is the prompt text, so no raw response column; a blank response marks the generation failed. The generation prompt (`PromptService::GENERATION_PROMPT`, English, one `prompt`, no `systemInstruction`: the profile is the owner's own data) asks for instructions addressed to the matcher, which never sees the profile, so they state the facts; dealbreakers, weakening and strengthening signals, a missing field is not a dislike on its own, hourly or per-project pay compared with the monthly expectation; at most 400 words (it goes into every matching call), no answer format (matching sets the format and the prompt injection guard itself, keeping the generated text as the `systemInstruction`). Confirm.
- The profile sent for prompt generation: the English analysis, seniority, years, skills, spoken languages, the monthly salary; "not stated" for an unknown field; the original-language text isn't sent. Only the platform name is sent: add the platform specifics from `platforms/*.md` once they are written.
- `PromptGenerationRetriesExhaustedListener` nearly duplicates `CvAnalysisRetriesExhaustedListener`. Fold them into one (a message interface with the entity id and the give-up call) if a third LLM operation (cover letter) needs the same.
- Two simultaneous first generation requests for one platform would both create a `Prompt` and the second hits the unique index on the platform (a 500). Accepted for a single user.
- From the review of the prompt generation task: the generation prompt fences the profile in `<candidate_profile>` tags and says it is data (the English analysis is LLM output from the CV, and the result becomes a standing `systemInstruction` for matching), forbids preferences the profile doesn't state (location, remote work, contract type, industries), covers pay in another currency, and asks for the instructions only, without a preface. Still untried against the live API: whether Gemini adds a preface or Markdown anyway (`PromptText` only trims).
