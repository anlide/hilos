# Feature Specification for AI Agents

> Draft. To be expanded.

Instructions for AI agents that orchestrate other agents or humans. How to turn a user wish into an executable technical specification.

---

## Plan for filling this file

1. **Clarifying questions**
   - What role: end user, admin, developer, operator?
   - What triggers the feature (user action, cron, WebSocket event, external API)?
   - What data is involved (new Entity/Object/Db, existing tables)?
   - Concurrency: single user or multi-user? Conflict resolution needed?
   - Performance: real-time or batch? onTick < 0.1s or long-running → monopolistic agent?
   - Frontend: new page, modal, table action, WebSocket update?

2. **Technical specification format**
   - Title, summary
   - Acceptance criteria (checkable)
   - Backend: migrations, Entity/Object/Db changes, agents, routes
   - Frontend: components, stores, WebSocket handlers
   - Dependencies (other features, env vars)

3. **Request format for executors**
   - Structured (markdown sections, checklists)
   - Ordered tasks (migration first, then Entity, Object, Db, then agent/frontend)
   - References: [reference.md](/docs/reference.md), [code-style.md](/docs/code-style.md), [cli-commands.md](/docs/cli-commands.md)
