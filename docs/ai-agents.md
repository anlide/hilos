# Documentation for AI Agents

> Draft. To be expanded.

Instructions for AI agents working with the Hilos framework.

---

## Anti-patterns

- **Do not use Repository or Service** for data access
- Use `Hilos::$db-><collection>` and DbCollection methods (`findByKey`, `findBySession`, etc.)
- Do not introduce Repository abstraction on top of DbCollection

---

## Sections

- **[Code Quality Validation](ai-agents/code-quality-validation.md)** — How to validate code quality: review output of other agents, humans, or CLI code generation. Checklists and automation hints.

- **[Feature Specification](ai-agents/feature-specification.md)** — How to turn a user wish into a technical specification. Clarifying questions, spec format, request format for executors.

- **[API, Testing & Debugging](ai-agents/api-testing-debugging.md)** — How to test, debug, read logs, add logs, and diagnose bugs.
