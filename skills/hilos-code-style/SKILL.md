---
name: hilos-code-style
description: Apply Hilos PHP and TypeScript code style, PHPDoc conventions, strict types, page action handler style, local variable rules, DTO routing style, and project quality rules. Use when writing, reviewing, or refactoring Hilos code where naming, typing, comments, PHPDoc, handlers, or small style decisions matter.
---

# Hilos Code Style

Use this skill for style-sensitive Hilos edits and reviews. Start with `agents.md`, then read the smallest style rule that applies.

## Read First

- Style rule index: `docs/agents/code-style/README.md`
- PHPDoc and inherited method docs: `docs/agents/code-style/phpdoc.md`
- `Page::onAction()` and action handler routing: `docs/agents/code-style/page-action-handlers.md`
- Temporary/local variable rules: `docs/agents/code-style/local-variables.md`
- Broader style guide: `docs/code-style.md`
- Quality guide: `docs/quality.md`

## Workflow

1. Read the specific style rule before changing code shape.
2. Keep PHP files strict with `declare(strict_types=1)`.
3. Use PHPDoc only where the project rule asks for it or where it clarifies a contract.
4. Keep action routing and handler code aligned with the page action handler guide.
5. Avoid one-use locals unless they improve clarity under the local variable rule.
6. Keep comments concise and in English.
7. For `Page::onAction()`, keep a `try/catch` around the routing `switch` and
   convert thrown handler failures to the page's user-facing fail/error signal
   with `sendToUser()`.

## Hard Rules

- Never run `git commit` or `git push`.
- Use `?type` for nullable PHP types in code and regular PHPDoc, unless a documented exception applies.
- Do not add unrelated refactors while applying style cleanup.
