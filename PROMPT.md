# Execution Router

Look in `docs/plans/` for the most recently modified file matching `*-prompt.md` (exclude anything inside `completed/`). That file is your execution contract. Read it and follow its instructions.

**Before reading the prompt**, always read these steering files first:
```
.kiro/steering/git-workflow.md
.kiro/steering/safe-operations.md
.kiro/steering/project-conventions.md
.kiro/steering/laravel-boost.md
```

## Rules

- If **no** active prompt file exists outside of `completed/`, stop immediately and print:
  ```
  AGY_NO_PROMPT: No active prompt found in docs/plans/
  ```

- If **multiple** active prompt files exist outside of `completed/`, list them and stop. Never execute more than one prompt in a single session:
  ```
  AGY_MULTIPLE_PROMPTS: Found N active prompts. Specify which one to execute.
  ```

- If a specific prompt file was provided in the invocation command, use that file directly instead of searching. The steering files still apply.
