# JobHunter

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
- Every user-facing string goes through the translator, the product name included: a key in `translations/messages.{en,ru}.yaml`, never literal text in templates or code. Both locales are added in the same change

## What the tools enforce

Only what the tools don't do themselves goes here. Don't duplicate in the rules what is already configured in the tools:

- PHP-CS-Fixer fixes the style, including `declare(strict_types=1)`
- Rector brings the code up to modern PHP (including `readonly` where a property doesn't change)
- PHPStan level 10 + strict-rules forbids unsafe handling of `mixed`, loose comparisons, etc.

The exception is line length: 120 characters max. PHPCS only checks it, so it has to be fixed by hand. Write long signatures and calls with one argument per line from the start.

## Running commands

Every project command (`bin/console`, `composer`, tests, linters, etc.) runs only inside Docker: `docker compose exec php ...` or the Makefile targets that wrap it. Bring the stack up only with `make init`/`make upd`/`make updb`: a bare `docker compose up` doesn't read `.env.local` and would rebuild the image with the default UID. Never use the host's PHP or Composer. Only git runs on the host.

## Model selection

Work at high quality, but spend effort in proportion to the task. Switch the model and effort on your own, without asking the user. Every task in `PLAN.md` carries a tier, decided once when the task is planned (the user can change it):

- `[S]` (simple): configs, Docker, installing packages, docs and rules, simple enums/entities/pages without business logic. The main session does it itself, without agents: they start cold and cost far more than the task. The check is GrumPHP (once installed) and the user's own verification
- `[C]` (complex): domain model with invariants, pipeline, LLM integration and prompts, security-relevant code, business logic. The `developer` agent (`.claude/agents/developer.md`: Opus, effort `high`) writes it, then the `reviewer` agent (`.claude/agents/reviewer.md`: Sonnet, effort `high`) reviews it. The review is done by a different model, otherwise it inherits the author's blind spots. Small review findings are fixed by the main session; substantial ones go to a new `developer` run with a short focused prompt
- The main session runs on Opus: effort `medium` for `[S]` tasks, `high` for planning, sorting out `NOTES.md` and architectural discussions. Suggest the switch to the user at such moments. Don't switch the model mid-session: the prompt cache is tied to the model, and the whole context would be paid for again
- For architecturally important parts (domain model, pipeline, LLM prompts), additionally suggest `/code-review ultra` to the user: only the user runs it, it is paid
- Verification is proportional to the task: one build or test run plus a check of the result itself. No exploratory experiments beyond the task; a side consideration goes into the `NOTES.md` inbox as one line, unverified
- Don't use lightweight models or low effort for code and rules. Don't enable fast mode

## Language

- All files in the repository are in English: code, comments, docs, commit messages, PR descriptions.
- Every Markdown doc in the repository (including future ones such as `README.md` or `platforms/*.md`) has a Russian copy for the owner in `ru/`, same path with the `.ru.md` suffix: `CLAUDE.md` → `ru/CLAUDE.ru.md`, `platforms/upwork.md` → `ru/platforms/upwork.ru.md`. The one exception is agents: `.claude/agents/X.md` → `ru/agents/X.ru.md`. Code and configs have no Russian copies. `ru/` is hidden from git via `.git/info/exclude` (local, not `.gitignore`): never commit it, and don't reference `ru/` from committed files other than these rules.
- `ru/` has its own local git repository (no remote), separate from the main one, so the owner can see diffs of the Russian docs. Whenever a commit is made in the main repository, also commit the changes in `ru/` (if any) with the same message. Never add `ru/` as a submodule and never push it.
- The Russian files are deliberately not named `CLAUDE.md` and not placed in `.claude/agents/`, so that Claude Code doesn't load them as instructions or agents.
- This applies to whoever edits the doc: the main session or any agent. A change to a doc (creating, editing, deleting) is made in both versions in the same step; a task is not done while the copies differ. If the owner edits a Russian file (e.g. writes thoughts into `ru/NOTES.ru.md`), translate the change into the English file. English is canonical for Claude; Russian is for the owner.
- Chat with the owner stays in Russian.

## Workflow

The project is run through two files:

- **[NOTES.md](NOTES.md)** — a structured record of the current decisions, grouped by topic, plus "Rejected alternatives" (what was rejected and why) and an "Inbox" for the user's raw thoughts: ideas, doubts, solution options, fragments of requirements, written as is, as thoughts come.
- **[PLAN.md](PLAN.md)** — a structured implementation plan with a task checklist. The source of truth about what to do and in what order.

The work order:

1. The user adds thoughts to the "Inbox" section of `NOTES.md`.
2. After discussion, each thought is moved from the inbox into the right section of `NOTES.md` (a superseded decision moves to "Rejected alternatives" with the reason), and the inbox is cleared. Based on `NOTES.md` (and the existing `PLAN.md`), the plan is updated — tasks are added/changed/removed.
3. Tasks from `PLAN.md` are done one at a time and marked as done.
4. If new considerations come up during implementation, they go back into the `NOTES.md` inbox, and the cycle repeats.

Don't start implementing a task unless it is recorded in `PLAN.md`. Don't turn `PLAN.md` into a dumping ground — if an item from `NOTES.md` hasn't been thought through yet and isn't ready to become a task, discuss it with the user before adding it to the plan.

## Task granularity

Each task in `PLAN.md` is the smallest possible atomic step that the user can verify themselves in a few minutes, not in half a day. Don't combine several different actions into one task.

Example: not "Bootstrap the Symfony project", but separately — "Initialize the Symfony project", "Bring up PostgreSQL in docker-compose", "Add the worker service to compose.yaml", "Install phpstan", "Install phpstan-symfony", "Install php-cs-fixer", "Set up a pre-commit hook", etc. — each item on its own checklist line.
