# Agent Host

Unified Node/TypeScript API server for Hilos AI agents with:

- HTTP API for creating and listing runs
- WebSocket event stream for progress, logs and reasoning
- Parallel support for multiple providers per run (`claude` and `openai`)
- Guardian role validation based on `framework/backend/AI/Agent/GuardianAiAgentId.php`
- Shared MCP servers for workspace access, fingerprint cache and report writing

## Docker

```bash
docker compose --env-file .env -f framework/docker/agent-host-local.yml up -d --build
```

The service listens on `9309`.

## Environment variables

Required provider keys:

- `ANTHROPIC_API_KEY` for Claude runs
- `OPENAI_API_KEY` for OpenAI runs

Common optional variables:

- `AGENT_HOST` default: `0.0.0.0`
- `AGENT_PORT` default: `9309`
- `AGENT_ALLOWED_ROOTS` default: `/workspace`
- `AGENT_MAX_FILE_SIZE_BYTES` default: `256000`
- `AGENT_MAX_LIST_FILES` default: `1000`
- `AGENT_DEFAULT_PROVIDER` optional fallback when request body omits `provider`

Claude-specific optional variables:

- `CLAUDE_MODEL` default: `claude-sonnet-4-6`
- `CLAUDE_MAX_TURNS` default: `25`

OpenAI-specific optional variables:

- `OPENAI_MODEL` default: `gpt-5`
- `OPENAI_REASONING_EFFORT` default: `medium`

## HTTP API

### `GET /health`

Returns service status, configured provider models, roles count and runs count.

### `GET /api/runs`

Lists stored runs. Supports optional query params:

- `status`
- `role`
- `env`
- `initiator`
- `provider`

### `POST /api/runs`

Creates a run.

Request body:

```json
{
  "provider": "claude",
  "role": "static_analysis",
  "env": "dev",
  "initiator": "user",
  "skills": "Read files, compare fingerprints, write report.",
  "prompt": "Inspect the listed files and produce a concise report.",
  "context": {
    "paths": [
      "framework/backend/Utils/Logger.php",
      "framework/backend/AI/Agent/GuardianAiAgentId.php"
    ]
  }
}
```

Response:

```json
{
  "runId": "uuid",
  "websocket": "/ws",
  "status": "queued",
  "provider": "claude"
}
```

OpenAI run example:

```json
{
  "provider": "openai",
  "role": "test_quality",
  "env": "dev",
  "initiator": "user",
  "skills": "Inspect tests and highlight risky gaps.",
  "prompt": "Review the touched tests and write a short markdown report."
}
```

### `GET /api/runs/:runId`

Returns run metadata plus persisted event history.

### `GET /api/runs/:runId/report`

Returns report metadata for the stored markdown report.

## WebSocket

Connect to `/ws`, then subscribe to a run:

```json
{
  "type": "subscribe",
  "runId": "uuid"
}
```

Supported incoming messages:

- `subscribe`
- `unsubscribe`
- `ping`

Main outgoing event types:

- `run.started`
- `run.progress`
- `run.log`
- `run.reasoning`
- `run.mcp_call`
- `run.report_written`
- `run.completed`
- `run.failed`

Use `runId: "*"` to subscribe to all runs.

## MCP servers

The service starts three local stdio MCP servers:

- `workspace`: `list_files`, `read_file`, `get_file_fingerprints`
- `fingerprint`: `get_rule_fingerprint`, `get_changed_targets`
- `reporting`: `write_report`, `record_checked_targets`, `syntax_check`

## Fingerprint cache

The cache is stored in `/data/fingerprints.json` and keys checks by:

- `role`
- `env`
- `ruleSetMd5`
- `fileMd5`

This allows the service to skip files that were already checked under the same effective ruleset.
