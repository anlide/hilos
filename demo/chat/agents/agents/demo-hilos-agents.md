# DemoHilos Agents (Framework-level)

These agents are provided by the Hilos framework and handle the built-in `/hilos/*` admin panel.

## DemoHilosAgent (`AgentType::HILOS_INDEX`)

Handles pages: `HILOS_DASHBOARD`, `HILOS_SETTINGS`, `HILOS_I18N`.
Manages framework settings, i18n strings, and the main Hilos dashboard.

## DemoHilosAnalyticsAgent (`AgentType::HILOS_ANALYTICS`)

Handles `HILOS_ANALYTICS` page.
Displays analytics data collected by `Hilos::$ac` (AnalyticsCollector).

## DemoHilosGuardianAgent (`AgentType::HILOS_GUARDIAN`)

Handles `HILOS_GUARDIAN` page.
The Guardian is an AI-powered code/runtime health inspector.
Triggered via `GUARDIAN_AGENT_RUN_START` / `GUARDIAN_AGENT_RUN_STOP` actions.
Runs AI agents (`ChatAiAgentFactory`) against the project code and reports results.

## DemoHilosLogsAgent (`AgentType::HILOS_LOGS`)

Handles `HILOS_LOGS` page.
Provides access to daemon and worker log files from the admin panel.

## Location

Backend: `backend/Agents/Hilos/Demo*.php`
Daemons: `backend/Core/Agent/Daemon/Hilos/Demo*AgentDaemon.php`

## Routing

All DemoHilos pages route through `ChatSignalRouter` config:
```php
PageConstants::HILOS_DASHBOARD => ['agentType' => AgentType::HILOS_INDEX, ...]
PageConstants::HILOS_GUARDIAN  => ['agentType' => AgentType::HILOS_GUARDIAN, ...]
// etc.
```

## AI agent system (Guardian)

`backend/AI/Agent/` — AI self-inspection agents organized by category:
- `Additional/` — cost, dead code, feature flags, migration safety, observability
- `ApplicationLevel/` — business logic, prompt integration
- `DevOpsEnvironment/` — CI/CD, Docker infra
- `Performance/` — API latency, DB performance, memory/CPU
- `RuntimeLogs/` — anomaly detection, log analysis
- `Security/` — secrets/config leak
- `SeoWebIntegrity/` — frontend rendering, link integrity, SEO

Factory: `ChatAiAgentFactory` creates and runs these agents on demand.
