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
- Code structure: what Symfony has a folder for stays there (`src/Entity`, `src/Repository`, `src/Controller`, `src/Command`, `src/MessageHandler`, `src/ApiResource`); framework-free domain code (VOs, domain exceptions, enums, domain events, ports) goes to `src/Domain/<Area>/`, an exception next to what it belongs to; adapters implementing the ports go to `src/Infrastructure/`. No `Application` layer
- Every user-facing string goes through the translator, the product name included: a key in `translations/messages.{en,ru}.yaml`, never literal text in templates or code. Both locales are added in the same change

## What the tools enforce

Only what the tools don't do themselves goes here. Don't duplicate in the rules what is already configured in the tools:

- PHP-CS-Fixer fixes the style, including `declare(strict_types=1)`
- Rector brings the code up to modern PHP (including `readonly` where a property doesn't change)
- PHPStan level 10 + strict-rules forbids unsafe handling of `mixed`, loose comparisons, etc.
- GrumPHP runs all the checks on every commit (`make lint` runs them by hand)

The exception is line length: 120 characters max. PHPCS only checks it, so it has to be fixed by hand. Write long signatures and calls with one argument per line from the start.

## Running commands

Every project command (`bin/console`, `composer`, tests, linters, etc.) runs only inside Docker: `docker compose exec php ...` or the Makefile targets that wrap it. Bring the stack up only with `make init`/`make upd`/`make updb`: a bare `docker compose up` doesn't read `.env.local` and would rebuild the image with the default UID. Never use the host's PHP or Composer. Only git runs on the host.

## Language

All files in the repository are in English: code, comments, docs, commit messages, PR descriptions.
