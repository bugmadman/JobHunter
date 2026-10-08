# Workflow

How the work is run. The method doesn't depend on the project: it relies on two files in the repository root, and stays the same when either of them is empty or missing. A missing file is created when it is first needed.

## NOTES.md and PLAN.md

- **NOTES.md** — a structured record of the current decisions, grouped by topic, plus "Rejected alternatives" (what was rejected and why) and an "Inbox" for the user's raw thoughts: ideas, doubts, solution options, fragments of requirements, written as is, as thoughts come.
- **PLAN.md** — a structured implementation plan with a task checklist. The source of truth about what to do and in what order.

## The cycle

1. The user adds thoughts to the "Inbox" section of `NOTES.md`.
2. After discussion, each thought is moved from the inbox into the right section of `NOTES.md` (a superseded decision moves to "Rejected alternatives" with the reason), and the inbox is cleared. Based on `NOTES.md` (and the existing `PLAN.md`), the plan is updated — tasks are added/changed/removed.
3. Tasks from `PLAN.md` are done one at a time and marked as done.
4. If new considerations come up during implementation, they go back into the `NOTES.md` inbox as one line, unverified, and the cycle repeats.

Don't start implementing a task unless it is recorded in `PLAN.md`. Don't turn `PLAN.md` into a dumping ground — if an item from `NOTES.md` hasn't been thought through yet and isn't ready to become a task, discuss it with the user before adding it to the plan.

## Task granularity

Each task in `PLAN.md` is the smallest possible atomic step that the user can verify themselves in a few minutes, not in half a day. Don't combine several different actions into one task.

Example: not "Bootstrap the project", but separately — "Initialize the project", "Bring up the database in docker-compose", "Install the static analyzer", "Install the code style fixer", "Set up a pre-commit hook", etc. — each item on its own checklist line.

## Scope and verification

- Spend effort in proportion to the task, and stay within it.
- Verify in proportion: one build or test run plus a check of the result itself. No exploratory experiments or research beyond what the task needs; a side consideration goes to the inbox (step 4 of the cycle).

## Commits

- A step is committed only after the user has seen the finished result and explicitly said to commit it. A yes to making changes is not a yes to committing: don't bundle "make the changes and commit" into one question — make the changes, show them, then ask.
- "Commit and continue" covers the step just shown, not the next one.
- Never bypass the hooks (`--no-verify`) without asking. If a hook rejects a commit, explain why and fix it or ask.
- Chain whatever follows a commit (another repository's commit, the next step) with `&&`, so that a rejected commit stops it.
