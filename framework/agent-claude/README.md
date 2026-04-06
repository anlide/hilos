# Agent Claude

Standalone Node/TypeScript API server for `@anthropic-ai/claude-agent-sdk` with:

- HTTP API for creating and listing runs
- WebSocket event stream for progress, logs and reasoning
- Guardian role validation based on `framework/backend/AI/Agent/GuardianAiAgentId.php`
- MCP servers for workspace access, fingerprint cache and report writing

## Docker

```bash
docker compose -f framework/docker/agent-claude-local.yml up -d --build
```

The service listens on `9308`.

Required environment variables:

- `ANTHROPIC_API_KEY`

Useful optional variables:

- `CLAUDE_MODEL` default: `claude-sonnet-4-6`
- `AGENT_CLAUDE_ALLOWED_ROOTS` default: `/workspace`
- `AGENT_CLAUDE_MAX_FILE_SIZE_BYTES` default: `256000`
- `AGENT_CLAUDE_MAX_TURNS` default: `25`

## HTTP API

### `GET /health`

Returns service status, configured model, roles count and runs count.

### `GET /api/runs`

Lists stored runs. Supports optional query params:

- `status`
- `role`
- `env`
- `initiator`

### `POST /api/runs`

Creates a run.

Request body:

```json
{
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
  "status": "queued"
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
