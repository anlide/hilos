# API, Testing & Debugging for AI Agents

> Draft. To be expanded.

How AI agents can test, debug, read logs, and diagnose bugs in Hilos applications.

---

## Plan for filling this file

1. **API for testing**
   - daemon:status — health check
   - daemon:monitor — live metrics (TTY)
   - HTTP status endpoint (if available)
   - WebSocket connection test

2. **Logs**
   - **Where:** `data/logs/` (project-specific path)
   - **Structure:** main log, agent-specific logs, rotation
   - **How to read:** grep by timestamp, agent ID, error level
   - **Log levels:** debug, info, error

3. **Adding logs for debugging**
   - When to add: entry/exit of critical paths, before/after DB ops, before/after WebSocket send
   - What to log: request ID, agent ID, user ID, operation name, short payload (no secrets)
   - Logger usage: `Logger::debug()`, `Logger::info()`, `Logger::errorLog()`

4. **Bug diagnosis workflow**
   - Reproduce: steps, role, environment
   - Check logs: last errors, relevant agent logs
   - Check daemon:status — workers, agents, memory
   - Check DB: migrations applied, schema consistent
   - Check WebSocket: connection, reconnection, action flow
   - Narrow down: frontend vs backend, which agent, which request
