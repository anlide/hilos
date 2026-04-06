import { randomUUID } from 'node:crypto';
import { createServer } from 'node:http';
import { promises as fs } from 'node:fs';
import path from 'node:path';

import express from 'express';
import { WebSocketServer, type WebSocket } from 'ws';
import { z } from 'zod';

import { loadConfig } from './config.js';
import { ensureDir, newEventId } from './lib/fs.js';
import { runAgentTask } from './agent/runAgent.js';
import { loadGuardianRoles } from './roles.js';
import { RunStore } from './storage/runStore.js';
import {
  ALLOWED_ENVS,
  ALLOWED_INITIATORS,
  RUN_STATUSES,
  type RunEventEnvelope,
  type RunsFilter,
  type RunRequestPayload,
  type RunSummary,
} from './types.js';

const config = loadConfig();
if (config.openAiApiKey) {
  process.env.OPENAI_API_KEY = config.openAiApiKey;
}
const runStore = new RunStore(config.runsDir);

const querySchema = z.object({
  status: z.enum(RUN_STATUSES).optional(),
  role: z.string().optional(),
  env: z.enum(ALLOWED_ENVS).optional(),
  initiator: z.enum(ALLOWED_INITIATORS).optional(),
});

const websocketMessageSchema = z.discriminatedUnion('type', [
  z.object({
    type: z.literal('subscribe'),
    runId: z.string(),
  }),
  z.object({
    type: z.literal('unsubscribe'),
    runId: z.string(),
  }),
  z.object({
    type: z.literal('ping'),
  }),
]);

async function main(): Promise<void> {
  await ensureDir(config.dataDir);
  await ensureDir(config.reportsDir);
  await ensureDir(config.runsDir);
  await runStore.init();

  const guardianRoles = await loadGuardianRoles(
    path.join(config.workspaceRoot, 'framework/backend/AI/Agent/GuardianAiAgentId.php'),
  );
  const guardianRoleSet = new Set(guardianRoles);

  const createRunSchema = z.object({
    role: z.string().refine((value) => guardianRoleSet.has(value), 'Unknown guardian role'),
    env: z.enum(ALLOWED_ENVS),
    initiator: z.enum(ALLOWED_INITIATORS),
    skills: z.string().trim().min(1),
    prompt: z.string().trim().min(1),
    context: z.record(z.string(), z.unknown()).optional(),
  });

  const app = express();
  app.use(express.json({ limit: '1mb' }));

  const server = createServer(app);
  const wss = new WebSocketServer({ noServer: true });
  const subscriptions = new Map<WebSocket, Set<string>>();

  server.on('upgrade', (request, socket, head) => {
    if (request.url === undefined || !request.url.startsWith('/ws')) {
      socket.destroy();
      return;
    }

    wss.handleUpgrade(request, socket, head, (ws) => {
      subscriptions.set(ws, new Set());
      ws.send(
        JSON.stringify({
          type: 'hello',
          payload: {
            message: 'Connected to agent-openai websocket',
          },
        }),
      );
      wss.emit('connection', ws, request);
    });
  });

  wss.on('connection', (ws) => {
    ws.on('message', (rawData) => {
      try {
        const parsed = websocketMessageSchema.parse(JSON.parse(rawData.toString('utf8')));
        if (parsed.type === 'ping') {
          ws.send(JSON.stringify({ type: 'pong' }));
          return;
        }

        const current = subscriptions.get(ws) ?? new Set<string>();
        if (parsed.type === 'subscribe') {
          current.add(parsed.runId);
        } else {
          current.delete(parsed.runId);
        }
        subscriptions.set(ws, current);

        ws.send(
          JSON.stringify({
            type: 'subscription.updated',
            payload: {
              runIds: [...current],
            },
          }),
        );
      } catch (error) {
        ws.send(
          JSON.stringify({
            type: 'error',
            payload: {
              message: error instanceof Error ? error.message : 'Invalid websocket message',
            },
          }),
        );
      }
    });

    ws.on('close', () => {
      subscriptions.delete(ws);
    });
  });

  app.get('/health', async (_request, response) => {
    const runs = await runStore.list();
    response.json({
      status: config.openAiApiKey ? 'ok' : 'degraded',
      service: 'agent-openai',
      port: config.port,
      model: config.model,
      openAiConfigured: Boolean(config.openAiApiKey),
      rolesCount: guardianRoles.length,
      runsCount: runs.length,
    });
  });

  app.get('/api/runs', async (request, response) => {
    const filter = querySchema.parse(request.query) as RunsFilter;
    const runs = await runStore.list(filter);
    response.json({
      items: runs.map(stripEvents),
    });
  });

  app.post('/api/runs', async (request, response) => {
    const payload = createRunSchema.parse(request.body) as RunRequestPayload;
    const runId = randomUUID();
    const summary: RunSummary = {
      runId,
      status: 'queued',
      role: payload.role,
      env: payload.env,
      initiator: payload.initiator,
      skills: payload.skills,
      prompt: payload.prompt,
      context: payload.context ?? {},
      createdAt: new Date().toISOString(),
    };

    await runStore.create(summary);

    void executeRun({
      summary,
      request: payload,
      onBroadcast: async (event) => {
        await runStore.appendEvent(runId, event);
        broadcast(event);
      },
      onSummaryPatch: async (patch) => {
        await runStore.patch(runId, patch);
      },
    });

    response.status(202).json({
      runId,
      websocket: '/ws',
      status: summary.status,
    });
  });

  app.get('/api/runs/:runId', async (request, response) => {
    const run = await runStore.get(request.params.runId);
    if (run === null) {
      response.status(404).json({ message: 'Run not found' });
      return;
    }

    response.json(run);
  });

  app.get('/api/runs/:runId/report', async (request, response) => {
    const run = await runStore.get(request.params.runId);
    if (run === null) {
      response.status(404).json({ message: 'Run not found' });
      return;
    }

    if (!run.reportPath) {
      response.status(404).json({ message: 'Report not found' });
      return;
    }

    const stat = await fs.stat(run.reportPath);
    response.json({
      runId: run.runId,
      reportPath: run.reportPath,
      reportName: run.reportName,
      size: stat.size,
      updatedAt: stat.mtime.toISOString(),
    });
  });

  app.use((error: unknown, _request: express.Request, response: express.Response, _next: express.NextFunction) => {
    if (error instanceof z.ZodError) {
      response.status(400).json({
        message: 'Validation failed',
        issues: error.issues,
      });
      return;
    }

    response.status(500).json({
      message: error instanceof Error ? error.message : 'Unexpected server error',
    });
  });

  server.listen(config.port, config.host, () => {
    console.log(`agent-openai listening on ${config.host}:${config.port}`);
  });

  function broadcast(event: RunEventEnvelope): void {
    const message = JSON.stringify(event);
    for (const client of wss.clients) {
      const runIds = subscriptions.get(client);
      if (runIds === undefined || client.readyState !== client.OPEN) {
        continue;
      }

      if (runIds.has(event.runId) || runIds.has('*')) {
        client.send(message);
      }
    }
  }
}

async function executeRun(input: {
  summary: RunSummary;
  request: RunRequestPayload;
  onBroadcast: (event: RunEventEnvelope) => Promise<void>;
  onSummaryPatch: (patch: Partial<RunSummary>) => Promise<void>;
}): Promise<void> {
  try {
    await runAgentTask({
      summary: input.summary,
      request: input.request,
      config,
      onSummaryPatch: input.onSummaryPatch,
      onEvent: async (event) => {
        await input.onBroadcast({
          id: newEventId(),
          timestamp: new Date().toISOString(),
          ...event,
        });
      },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown agent error';
    await input.onSummaryPatch({
      status: 'failed',
      finishedAt: new Date().toISOString(),
      error: message,
    });
    await input.onBroadcast({
      id: newEventId(),
      runId: input.summary.runId,
      type: 'run.failed',
      timestamp: new Date().toISOString(),
      payload: {
        message,
      },
    });
  }
}

function stripEvents(run: Awaited<ReturnType<RunStore['get']>> extends infer TResult ? TResult : never) {
  if (run === null) {
    return null;
  }

  const { events, ...summary } = run;
  return summary;
}

main().catch((error) => {
  console.error('agent-openai bootstrap failed', error);
  process.exit(1);
});
