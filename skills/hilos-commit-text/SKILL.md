---
name: hilos-commit-text
description: >
  Generate Hilos commit message text according to project rules: a single
  English message, short lines, a text code block, one blank line after the
  summary, dash-prefixed body lines, and never running git commit or git push.
---

# Hilos Commit Text

Use this skill when the user asks for commit text, a commit message, or wording
for a commit. This skill only writes suggested text; it does not run git
commands.

## Output Rules

- Provide one commit message in English.
- Put the message inside a single `text` code block.
- Keep line length short.
- Separate the first line from the rest with one blank line.
- Do not add any other blank lines inside the message.
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
2. Provide a single commit message; offer alternatives only if the user
   explicitly asks.
3. Mention only changes that are in scope.
4. Do not include issue numbers, ticket IDs, or scopes unless they are present
   in the user request or branch context.

## Hard Rules

- Never run `git commit`.
- Never run `git push`.
- Do not stage files unless the user explicitly asks for staging.
