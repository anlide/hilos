# Code Quality Validation for AI Agents

> Draft. To be expanded.

Instructions for AI agents to validate code quality. Use these rules to:
- Review code produced by other AI agents
- Review code written by humans
- Validate output of CLI code generation tools (e.g. `db:entity:fix`, `db:object:fix`, `db:idea:fix`)

---

## Plan for filling this file

1. **Checklist for PHP (Backend)**
   - `declare(strict_types=1)` present
   - PSR-12 / PSR-1 compliance
   - PHPDoc on public methods
   - Namespace and class naming conventions
   - Exception handling (framework hierarchy)
   - No hardcoded credentials, env vars used correctly

2. **Checklist for TypeScript / Vue (Frontend)**
   - Typing, strict mode
   - Component structure
   - Import hygiene

3. **ORM validation**
   - Entity ↔ schema consistency (run `db:entity:diff`)
   - Object ↔ Entity consistency
   - Idea ↔ Object consistency
   - Custom methods preserved after `*:fix`

4. **Hilos-specific rules**
   - Agent `onTick` duration (< 0.1s guideline)
   - Long-running ops → monopolistic agent
   - Modal editing for data changes (conflict resolution)
   - See [quality.md](/docs/quality.md) for application quality

5. **Automation hints**
   - Commands: `db:entity:diff`, `db:object:fix --dry-run`, `db:idea:fix --dry-run`
   - Linters, formatters (if configured)
