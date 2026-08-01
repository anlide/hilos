# Code Quality Validation for AI Agents

> Draft. To be expanded.

Instructions for AI agents to validate code quality. Use these rules to:
- Review code produced by other AI agents
- Review code written by humans
- Validate generated or automated code changes

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
   - Entity ↔ schema consistency
   - Object ↔ Entity consistency
   - Db ↔ Object consistency
   - No Repository/Service on top of DbCollection

4. **Hilos-specific rules**
   - Agent `onTick` duration (< 0.1s guideline)
   - Long-running ops → monopolistic agent
   - Modal editing for data changes (conflict resolution)
   - See [quality.md](/docs/quality.md) for application quality

5. **Automation hints**
   - Linters, formatters (if configured)
