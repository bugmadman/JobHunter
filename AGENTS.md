# JobHunter

The rules for working on this project, for people and AI tools alike. The working method is in [WORKFLOW.md](WORKFLOW.md).

A self-hosted single-user job search service: job postings from platforms → keyword filter → LLM matching against criteria derived from the CV → review and apply in a web interface. Stack: PHP 8.5, Symfony 7.4 (LTS), PostgreSQL, Docker.

## Development principles

- DDD: a rich domain model, not an anemic one — entities (`JobPosting`, `CvProfile`, etc.) contain behavior and protect their invariants (e.g. `JobPosting` cannot jump to the "evaluated" status, skipping "filtered"), rather than bare getters/setters with the logic living in services
- Value Objects are immutable, with no mutator methods (money/salary, language level, etc.)
- DTOs validated with Symfony Assert (`Assert\NotBlank`, etc.) at the boundaries (API input, forms)
- Enums instead of magic strings — pipeline statuses, seniority, platform access type, etc.
- Interfaces for external integrations (ports & adapters) — `LlmClientInterface`, `PlatformClientInterface`, etc., so that concrete implementations (platform, LLM provider) are swappable and testable with mocks
- Domain events for pipeline transitions — they map onto `JobPostingStageLog`, every status transition = an event
- Pass structured data as classes (DTO/VO), not arrays
- DRY, KISS, SOLID, YAGNI — don't build abstractions for a hypothetical future
- Comments only for a real trap a future reader would fall into; no comments explaining obvious settings or restating the code, in code and configs alike (compose, Makefile, Dockerfile, YAML/NEON). When in doubt, leave it out
- Code structure: what Symfony has a folder for stays there (`src/Entity`, `src/Repository`, `src/Controller`, `src/Command`, `src/MessageHandler`, `src/ApiResource`); framework-free domain code (VOs, domain exceptions, enums, domain events, area services) goes to `src/Domain/<Area>/`, an exception next to what it belongs to; adapters for external integrations go to `src/Infrastructure/`. No `Application` layer
- One service per area, `src/Domain/<Area>/<Area>Service`, for operations that need something outside the entity (the DB, the password hasher, the LLM, several entities). An entity's own behavior and invariants stay in the entity
- Every data change goes through the area service. Controllers, console commands, Messenger handlers and API Platform processors are thin: parse the input, call the service, render the result. Reading for display may use a repository directly
- Repositories hold queries and `save()` (`persist` + `flush`), no logic. `save()` covers both create and update; no separate `update()`
- Passwords and secrets (LLM API keys) travel as VOs (`PlainPassword`); every parameter that takes the raw value is marked `#[\SensitiveParameter]`
- An interface lives next to its implementation (`UserServiceInterface` next to `UserService`, `UserRepositoryInterface` in `src/Repository/`, an adapter's interface in `src/Infrastructure/`), and dependents use the interface: every class is `final`, so nothing else can be mocked
- Tests cover logic, not data containers: a VO with validation is tested, a VO or DTO without logic isn't. Services: unit tests with the dependencies mocked. Controllers and commands: functional tests with the service mocked. A test DB filled from fixtures only for repository queries; tests never touch real data
- Every user-facing string goes through the translator, the product name included: a key in `translations/messages.{en,ru}.yaml`, never literal text in templates or code. Both locales are added in the same change. Console command output is the exception: English only, no translator

## What the tools enforce

Only what the tools don't do themselves goes here. Don't duplicate in the rules what is already configured in the tools:

- PHP-CS-Fixer fixes the style, including `declare(strict_types=1)`
- PHP-CS-Fixer makes every non-abstract class `final`, Doctrine entities included
- Rector brings the code up to modern PHP (including `readonly` where a property doesn't change)
- PHPStan level 10 + strict-rules forbids unsafe handling of `mixed`, loose comparisons, etc.
- GrumPHP runs all the checks on every commit (`make lint` runs them by hand)

The exception is line length: 120 characters max. PHPCS only checks it, so it has to be fixed by hand. Write long signatures and calls with one argument per line from the start.

## Running commands

Every project command (`bin/console`, `composer`, tests, linters, etc.) runs only inside Docker: `docker compose exec php ...` or the Makefile targets that wrap it. Bring the stack up only with `make init`/`make upd`/`make updb`: a bare `docker compose up` doesn't read `.env.local` and would rebuild the image with the default UID. Never use the host's PHP or Composer. Only git runs on the host.

## Language

All files in the repository are in English: code, comments, docs, commit messages, PR descriptions.
