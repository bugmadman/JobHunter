# Notes and decisions

Current project decisions, grouped by topic. Each item is the final decision (with the reason, if there is one); "Open" means the question isn't decided yet, "Postponed" means deliberately not now. Superseded decisions are in the "Rejected alternatives" section, new raw thoughts are in the "Inbox" section. Tasks derived from these decisions are in PLAN.md.

## Product and concept

- A job search service: job postings from platforms → keyword filter → LLM matching against criteria derived from the CV → review and apply in a web interface.
- Self-hosted, not SaaS: installed via `git clone` + `docker compose up`.
- A single user: one account, no registration. `Prompt`/`CvProfile`/`JobPosting` are not tied to `User`.
- All the code is written by Claude with minimal manual involvement from the owner — so the code must be verified automatically, without a manual review at every step (see "Dev tooling and quality", "Development process").
- The result is written only to the DB.

## Job sources and platforms

- Platforms: Upwork, LinkedIn, glassdoor.com, indeed.com.
- Platforms are stored in the DB (a "platform" entity), so they can be added/removed without a code release.
- Each platform has its own context file in `platforms/` (similar to CLAUDE.md): decisions about what and how to code for accessing it (API? RSS? scraping? parsing specifics) are written down before implementing it and along the way. This is dev documentation, not a user setting.
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
- What the interface must do:
  - clearly sort/filter job postings;
  - view a job posting's processing history and the LLM decisions, manually correct LLM decisions;
  - add comments to a job posting;
  - mark "applied / not applied" — a separate field, not the same as like/dislike.
- Login — form login (Symfony Security), the account is created with a console command.

## Applications

- Cover letter: a button in the job posting card → the LLM writes a letter based on `CvProfile` + the job posting + the platform prompt → editing → copying. Simple (one LLM call), we do it before the tailored CV.
- CV tailored to a job posting (the market is like this now — each job posting needs its own CV version): a button in the job posting card → the LLM assembles a CV version for it → manual editing → PDF. Requires the full CV structure in the DB (experience per employer, etc.), the uploaded PDF becomes an import. A big feature — a separate phase after the core works.

## Infrastructure and deployment

- PHP entirely in Docker, no local PHP needed. DB — PostgreSQL.
- Docker: `.docker/php` + `compose.yaml` + Makefile, a separate `worker` service. Requirements: `php.ini`/`Caddyfile` in the repository, the Makefile doesn't fail without `.env.local`, `depends_on` waits for the Postgres healthcheck, the DB port is not exposed externally, the official `dunglas/frankenphp` image (PHP 8.5, alpine).
- The `php` image is built from the repository root (`context: .`, `dockerfile: .docker/php/Dockerfile`): the Dockerfile copies `.docker/php/php.ini`.
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

- `login_throttling` on the login form;
- a rate limiter on the job posting intake API;
- encryption of LLM API keys in the DB;
- `trusted_proxies` behind the tunnel;
- prod without debug.

## Dev tooling and quality

- Static analysis: PHPStan level 10 + `phpstan-symfony` + strict-rules + deprecation-rules.
- Tests: PHPUnit 12 + `symfony/phpunit-bridge`. Mocks — standard PHPUnit ones.
- Style: PHP-CS-Fixer with our own config; the `return` rules (a blank line before `return`, a useless variable before `return`) are covered by it (`blank_line_before_statement`, `return_assignment`) with auto-fixing. PHPCS — only for the 120 line length limit (CS-Fixer doesn't check it).
- Rector, composer-normalize.
- `symfony/maker-bundle` — boilerplate generation (`make:entity`, `make:migration`, `make:message-handler`, etc.), a key thing for writing code autonomously.
- `symfony/browser-kit` + `symfony/css-selector` — functional tests of HTTP endpoints and pages (including EasyAdmin).
- `fakerphp/faker` — fake data for fixtures/tests.
- `symfony/http-client`, `symfony/twig-bundle`, `api-platform/core` are runtime dependencies (`require`), not dev.
- Package versions — current for the Symfony/PHP version at bootstrap time.
- All checks (PHPStan, PHPUnit, PHP-CS-Fixer, Symfony linters) run automatically on commit via GrumPHP; the commit doesn't go through if the checks are red.
- GrumPHP runs inside the `php` container (via `docker compose exec`) and calls `git` there, so the `php` image includes `git`.

## Development process

- The "writer + reviewer" scheme: a strong model writes; the first filter is free deterministic checks (they catch mechanical errors without an LLM); the second filter is a review by a different, independent model (not necessarily more expensive than the writer). Reason: a review by the same model that wrote the code inherits its blind spots. The specific agents, models and effort are in CLAUDE.md, the "Model selection" section.
- Model switching happens without manual actions by the owner: the main orchestrator session delegates to subagents itself. The owner just asks to do a task.
- Claude can't run `/code-review ultra` (multi-agent cloud review) itself — the owner runs it (paid); Claude only suggests it for important parts.
- Tests are written by Claude too, in the same "code → checks → review" cycle. A test is mandatory for every business branch/rule (`JobPostingStageLog` status transitions, keyword filter edge cases, CV schema validation, prompt generation, LLM integration via mocks at the boundary). The coverage % is a signal for ourselves, not a hard threshold.
- CLAUDE.md holds only what the tools don't do: `strict_types`, style, `readonly`, `mixed` are covered by CS-Fixer/Rector/PHPStan — we don't duplicate them in the rules.

## Rejected alternatives

- Multi-user with registration and platforms/prompts/job postings tied to the user → a single user, self-hosted.
- SaaS → self-hosted (`git clone` + `docker compose up`).
- A prompt per "user + platform" pair → a prompt per platform (there is one user).
- One global prompt → a separate prompt for each platform.
- A per-user API key for job posting intake → one key from the settings.
- RabbitMQ (`symfony/amqp-messenger`) → the Doctrine transport in PostgreSQL: one container fewer for self-hosted, switched with a single DSN line.
- nginx + PHP-FPM → FrankenPHP: one container instead of two, the official Symfony Docker setup, worker mode.
- Synchronous LLM calls in the web request with a raised `set_time_limit` → Messenger: Cloudflare cuts a response after ~100 s anyway, there are no retries, and a long load hangs on the phone.
- VPN (Tailscale/WireGuard) → the already running Cloudflare Tunnel.
- Cloudflare Access on top of our login — not enabled.
- `cloudflared` in our `compose.yaml` → no, it's the infrastructure of a specific server; an example in the README.
- A volume (or `COMPOSER_CACHE_DIR`) for the Composer cache → not taken: the project is installed once, and losing the cache when the container is recreated costs only an extra minute of `composer install`; a dedicated volume is YAGNI.
- Storing LLM API keys in the DB as is → encryption, since the server is on the internet.
- EasyAdmin as the main interface for everything, including job postings → only settings and reference data; work screens are custom pages, responsive for phones.
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
- Duplicating in CLAUDE.md what the tools check → not duplicated.

## Inbox

The owner writes new thoughts here as is, without structure. After discussion they are moved into the sections above (superseded decisions go to "Rejected alternatives" with the reason), and this section is cleared.
