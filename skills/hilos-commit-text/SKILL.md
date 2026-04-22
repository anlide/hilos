---
name: hilos-commit-text
description: >
  Generate Hilos commit message text according to project rules: English
  options, short lines, text code blocks, one blank line after the summary,
  dash-prefixed body lines, and never running git commit or git push.
---

# Hilos Commit Text

Use this skill when the user asks for commit text, a commit message, or wording
for a commit. This skill only writes suggested text; it does not run git
commands.

## Output Rules

- Provide options in English.
- Put each option inside its own `text` code block.
- Keep line length short.
- Separate the first line from the rest with one blank line.
- Do not add any other blank lines inside an option.
- In additional lines, start each logical block with `-`.
- Prefer an imperative summary line.

## Shape

```text
Use validation exceptions for user action errors

- Replace RtBaseException with ValidationException children.
- Update page handlers and tests for the new exception taxonomy.
- Document the exception rules in project agent docs.
```

## Workflow

1. Inspect the actual diff or user summary before writing the message.
2. Offer 2-3 options unless the user asks for one exact message.
3. Keep the first option as the recommended/default one.
4. Mention only changes that are in scope.
5. Do not include issue numbers, ticket IDs, or scopes unless they are present
   in the user request or branch context.

## Hard Rules

- Never run `git commit`.
- Never run `git push`.
- Do not stage files unless the user explicitly asks for staging.
