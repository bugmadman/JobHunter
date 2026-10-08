@AGENTS.md
@WORKFLOW.md

# Claude Code

## Model selection

Work at high quality, but spend effort in proportion to the task. Pick agents on your own, without asking the user. Every task carries a tier, decided once when the task is planned (the user can change it):

- `[S]` (simple): configs, Docker, installing packages, docs and rules, simple enums/entities/pages without business logic. The main session does it itself, without agents: they start cold and cost far more than the task. The check is GrumPHP and the user's own verification
- `[C]` (complex): domain model with invariants, pipeline, LLM integration and prompts, security-relevant code, business logic. The `developer` agent (`.claude/agents/developer.md`: Opus, effort `high`) writes it, then the `reviewer` agent (`.claude/agents/reviewer.md`: Sonnet, effort `high`) reviews it. The review is done by a different model, otherwise it inherits the author's blind spots. Small review findings are fixed by the main session; substantial ones go to a new `developer` run with a short focused prompt
- The main session runs on Opus: effort `medium` for `[S]` tasks, `high` or above for planning, rules, sorting out decisions and architectural discussions. Only the user can change the main session's model and effort: suggest the switch at such moments. Don't switch the model mid-session: the prompt cache is tied to the model, and the whole context would be paid for again
- For architecturally important parts (domain model, pipeline, LLM prompts), additionally suggest `/code-review ultra` to the user: only the user runs it, it is paid
- Don't use lightweight models or low effort for code and rules. Don't enable fast mode
