---
name: hilos-commit-text
description: >
  Generate Hilos commit message text according to project rules: a single
  English message, short lines, a text code block, one blank line after the
  summary, dash-prefixed body lines, a closing authorship block naming every
  executor whose work the commit carries with the step each ran at, and never
  running git commit or git push.
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
then a `Co-Authored-By` line for every executor whose work this commit carries,
and under them a `Model:` line for each, in the same order.

```text
Co-Authored-By: Antigravity Gemini 3.8 Flash (high) <noreply@google.com>
Co-Authored-By: Claude Opus 5 (xhigh) <noreply@anthropic.com>
Model: gemini-3.8-flash-high
Model: claude-opus-5
```

- The name is the one the vendor gives the model — `Claude Opus 5`,
  `Claude Fable 5.1`, `Codex GPT-6 Astra` — not a model id, and the address is
  that vendor's no-reply address.
- The step goes in the name, in parentheses: `Claude Opus 5 (xhigh)`. Read it,
  do not recall it: in Claude Code it is the `CLAUDE_EFFORT` environment variable
  (`echo $CLAUDE_EFFORT`). Where the vendor builds the step into the model name
  (Cursor, Antigravity), it is there already — do not write it twice. There is no
  separate `Effort:` line any more: one line for the whole message cannot say
  whose step it carries once a commit has several co-authors.
- `Model:` carries the exact id — `claude-opus-5`, `gpt-6-astra`,
  `cursor-grok-4.6-xhigh`. Write that line for every co-author or for none: a
  list read by position with one entry missing hands a model to the wrong person.
- Who belongs in the block:
  - an ordinary commit — the session that composes this text, one line;
  - a squash commit (`git merge --squash` of a leaf branch) — everyone whose
    commits are in the squashed delta, in the order of each one's first commit,
    `HOTFIX:` commits counted like any other, and the session doing the squash
    last if it is not there already;
  - an amend (`--amend`, `-c`, `-C`) — the co-authors of the commit whose message
    is reused, then this session.
- Take those names from the trailers of their commits
  (`git show -s --format='%(trailers:only,unfold)' <sha>`), not from memory of who
  worked on the branch: a session that committed nothing is not a co-author. A
  signature written before 2026-09-19 carries its step on a separate `Effort:`
  line; fold it into the name only when that commit has one co-author, because
  with several there is no telling whose step it is.
- One line per executor. Identical lines are not repeated, and where the same
  executor appears with a step and without one, the line with the step is the one
  that stays: the line a tool appends by itself does not carry it.
- The block names those executors and nothing else: not the subagents a session
  used, not the `model-*` or `effort-*` labels of a ticket.
- The blank line before the block is required, and the block is the LAST thing in
  the message. Git reads trailers only out of the final paragraph, so a line after
  the block hides it as surely as a missing blank line before it, and either way
  the block stops being something history can be asked about. A departure from the
  plan, a measurement, a caveat — everything of that kind goes above the block.
- Write only what this session knows. A value you cannot read is left out — the
  step out of the name, the model id together with every `Model:` line; never
  write a placeholder such as `unknown`, which greps as a value.
- A `prepare-commit-msg` hook, in a checkout that has one, owns this block: it
  strips these trailers and writes them from what the run really was, and on a
  squash it reads the other co-authors out of the commits being squashed. Do not
  hand-write branch co-authors there. The rules above are for a checkout without
  that hook.

## Shape

```text
Use validation exceptions for user action errors

- Replace RtBaseException with ValidationException children.
- Update page handlers and tests for the new exception taxonomy.
- Document the exception rules in project agent docs.

Co-Authored-By: Claude Opus 5 (xhigh) <noreply@anthropic.com>
Model: claude-opus-5
```

## Workflow

1. Inspect the actual diff or user summary before writing the message.
2. Provide a single commit message; offer alternatives only if the user
   explicitly asks.
3. Mention only changes that are in scope.
4. Do not include issue numbers, ticket IDs, or scopes unless they are present
   in the user request or branch context.
5. End the message with the authorship block: read your step from the
   environment, and on a squash take the other co-authors from the commits it
   rolls in.

## Hard Rules

- Never run `git commit`.
- Never run `git push`.
- Do not stage files unless the user explicitly asks for staging.
