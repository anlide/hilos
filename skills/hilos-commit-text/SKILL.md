---
name: hilos-commit-text
description: >
  Generate Hilos commit message text according to project rules: a single
  English message, short lines, a text code block, one blank line after the
  summary, dash-prefixed body lines, a closing authorship block naming the model
  and its effort, and never running git commit or git push.
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
- Do not add any other blank lines inside the message, except the one before the
  authorship block.
- In additional lines, start each logical block with `-`.
- Prefer an imperative summary line.

## Authorship Block

Every message this skill produces ends with an authorship block: one blank line,
then two git trailers.

```text
Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Effort: max
```

- The name is the one the composing session goes by — `Claude Opus 5`,
  `Claude Sonnet 5`, `Claude Fable 5` — not a model id, and the address is that
  vendor's no-reply address.
- `Effort:` carries the reasoning effort of that same session, lowercase, in the
  tool's own words: `low`, `medium`, `high`, `xhigh`, `max`. Read it, do not
  recall it: in Claude Code it is the `CLAUDE_EFFORT` environment variable
  (`echo $CLAUDE_EFFORT`).
- The blank line before the block is required. Without it git does not read these
  lines as trailers at all, and the block stops being something history can be
  asked about.
- Write only what this session knows. A value you cannot read takes its whole
  line out of the block; never write a placeholder such as `unknown`, which greps
  as a value.
- Exactly one `Co-Authored-By` line per message. When the tool appends that line
  itself, do not add a second one.
- The block names the session that composed this text and nothing else: not the
  subagents it used, not the engine behind the branch commits a squash message
  summarizes, not the `model-*` or `effort-*` labels of a ticket.

## Shape

```text
Use validation exceptions for user action errors

- Replace RtBaseException with ValidationException children.
- Update page handlers and tests for the new exception taxonomy.
- Document the exception rules in project agent docs.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
Effort: max
```

## Workflow

1. Inspect the actual diff or user summary before writing the message.
2. Provide a single commit message; offer alternatives only if the user
   explicitly asks.
3. Mention only changes that are in scope.
4. Do not include issue numbers, ticket IDs, or scopes unless they are present
   in the user request or branch context.
5. End the message with the authorship block, and read your effort from the
   environment before you write it.

## Hard Rules

- Never run `git commit`.
- Never run `git push`.
- Do not stage files unless the user explicitly asks for staging.
