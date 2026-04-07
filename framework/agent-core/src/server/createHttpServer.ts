import { randomUUID } from 'node:crypto';
import { createServer, type Server as HttpServer } from 'node:http';
import { promises as fs } from 'node:fs';

import express from 'express';
import { WebSocketServer, type WebSocket } from 'ws';
import { z } from 'zod';

import { ensureDir, newEventId } from '../lib/fs.js';
import { RunStore } from '../storage/runStore.js';
import {
  ALLOWED_ENVS,
  ALLOWED_INITIATORS,
  ALLOWED_PROVIDERS,
  RUN_STATUSES,
  type AgentProvider,
  type ProviderHealthStatus,
  type RunEventEnvelope,
  type RunRequestPayload,
  type RunSummary,
  type RunsFilter,
} from '../types.js';

const querySchema = z.object({
  status: z.enum(RUN_STATUSES).optional(),
  role: z.string().optional(),
  env: z.enum(ALLOWED_ENVS).optional(),
  initiator: z.enum(ALLOWED_INITIATORS).optional(),
  provider: z.enum(ALLOWED_PROVIDERS).optional(),
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

export interface CoreServerConfig {
  host: string;
  port: number;
  dataDir: string;
  reportsDir: string;
  runsDir: string;
  defaultProvider?: AgentProvider;
  serviceName: string;
  websocketHelloMessage: string;
}

export interface ExecuteRunInput {
  summary: RunSummary;
  request: RunRequestPayload;
  onBroadcast: (event: RunEventEnvelope) => Promise<void>;
  onSummaryPatch: (patch: Partial<RunSummary>) => Promise<void>;
}

export interface ServerDependencies {
  config: CoreServerConfig;
  guardianRoles: string[];
  runStore: RunStore;
  getProvidersHealth: () => Record<AgentProvider, ProviderHealthStatus>;
  executeRun: (input: ExecuteRunInput) => Promise<void>;
}

export interface CreatedHttpServer {
  app: express.Express;
  server: HttpServer;
  wss: WebSocketServer;
}

export async function createHttpServer(deps: ServerDependencies): Promise<CreatedHttpServer> {
  await ensureDir(deps.config.dataDir);
  await ensureDir(deps.config.reportsDir);
  await ensureDir(deps.config.runsDir);
  await deps.runStore.init();

  const guardianRoleSet = new Set(deps.guardianRoles);
  const createRunSchema = z.object({
    provider: z.enum(ALLOWED_PROVIDERS).optional(),
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
            message: deps.config.websocketHelloMessage,
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
    const runs = await deps.runStore.list();
    response.json({
      status: 'ok',
      service: deps.config.serviceName,
      port: deps.config.port,
      providers: deps.getProvidersHealth(),
      rolesCount: deps.guardianRoles.length,
      runsCount: runs.length,
    });
  });

  app.get('/api/runs', async (request, response) => {
    const filter = querySchema.parse(request.query) as RunsFilter;
    const runs = await deps.runStore.list(filter);
    response.json({
      items: runs.map(stripEvents),
    });
  });

  app.post('/api/runs', async (request, response) => {
    const parsed = createRunSchema.parse(request.body);
    const provider = parsed.provider ?? deps.config.defaultProvider;

    if (provider === undefined) {
      response.status(400).json({ message: 'Provider is required' });
      return;
    }

    const providerStatus = deps.getProvidersHealth()[provider];
    if (!providerStatus?.configured) {
      response.status(400).json({ message: `Provider is not configured: ${provider}` });
      return;
    }

    const payload: RunRequestPayload = {
      ...parsed,
      provider,
    };

    const runId = randomUUID();
    const summary: RunSummary = {
      runId,
      provider,
      status: 'queued',
      role: payload.role,
      env: payload.env,
      initiator: payload.initiator,
      skills: payload.skills,
      prompt: payload.prompt,
      context: payload.context ?? {},
      createdAt: new Date().toISOString(),
    };

    await deps.runStore.create(summary);

    void executeRun({
      summary,
      request: payload,
      runStore: deps.runStore,
      executeRun: deps.executeRun,
      broadcast,
    });

    response.status(202).json({
      runId,
      websocket: '/ws',
      status: summary.status,
      provider,
    });
  });

  app.get('/api/runs/:runId', async (request, response) => {
    const run = await deps.runStore.get(request.params.runId);
    if (run === null) {
      response.status(404).json({ message: 'Run not found' });
      return;
    }

    response.json(run);
  });

  app.get('/api/runs/:runId/report', async (request, response) => {
    const run = await deps.runStore.get(request.params.runId);
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

  app.use(
    (
      error: unknown,
      _request: express.Request,
      response: express.Response,
      _next: express.NextFunction,
    ) => {
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
    },
  );

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

  return {
    app,
    server,
    wss,
  };
}

async function executeRun(input: {
  summary: RunSummary;
  request: RunRequestPayload;
  runStore: RunStore;
  executeRun: ServerDependencies['executeRun'];
  broadcast: (event: RunEventEnvelope) => void;
}): Promise<void> {
  try {
    await input.executeRun({
      summary: input.summary,
      request: input.request,
      onBroadcast: async (event) => {
        await input.runStore.appendEvent(input.summary.runId, event);
        input.broadcast(event);
      },
      onSummaryPatch: async (patch) => {
        await input.runStore.patch(input.summary.runId, patch);
      },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown agent error';
    await input.runStore.patch(input.summary.runId, {
      status: 'failed',
      finishedAt: new Date().toISOString(),
      error: message,
    });
    const event: RunEventEnvelope = {
      id: newEventId(),
      runId: input.summary.runId,
      type: 'run.failed',
      timestamp: new Date().toISOString(),
      payload: {
        message,
      },
    };
    await input.runStore.appendEvent(input.summary.runId, event);
    input.broadcast(event);
  }
}

function stripEvents(run: Awaited<ReturnType<RunStore['get']>> extends infer TResult ? TResult : never) {
  if (run === null) {
    return null;
  }

  const { events, ...summary } = run;
  return summary;
}
