import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { Agent, connectMcpServers, MCPServerStdio, run } from '@openai/agents';

import type { AgentOpenAIConfig } from '../config.js';
import { ensureDir, hashFile, hashString, sanitizeFileName, toRelativePath } from '../lib/fs.js';
import { buildInstructions } from './buildInstructions.js';
import { FingerprintStore } from '../storage/fingerprintStore.js';
import type { RunContextData, RunEventEnvelope, RunRequestPayload, RunSummary } from '../types.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

type EventCallback = (event: Omit<RunEventEnvelope, 'id' | 'timestamp'>) => Promise<void> | void;

export interface RunAgentOptions {
  request: RunRequestPayload;
  summary: RunSummary;
  config: AgentOpenAIConfig;
  onEvent: EventCallback;
  onSummaryPatch: (patch: Partial<RunSummary>) => Promise<void> | void;
}

interface FileFingerprint {
  relativePath: string;
  absolutePath: string;
  fileMd5: string;
}

export async function runAgentTask(options: RunAgentOptions): Promise<void> {
  const { request, config, summary, onEvent, onSummaryPatch } = options;
  const configHasOpenAiKey = Boolean(config.openAiApiKey);
  const envHasOpenAiKey = Boolean(process.env.OPENAI_API_KEY);
  const configOpenAiKeyLength = config.openAiApiKey?.length ?? 0;
  const envOpenAiKeyLength = process.env.OPENAI_API_KEY?.length ?? 0;
  const effectiveApiKey = config.openAiApiKey ?? process.env.OPENAI_API_KEY;

  console.log(
    '[agent-openai] run bootstrap',
    JSON.stringify({
      runId: summary.runId,
      role: request.role,
      env: request.env,
      initiator: request.initiator,
      model: config.model,
      configHasOpenAiKey,
      envHasOpenAiKey,
      configOpenAiKeyLength,
      envOpenAiKeyLength,
      effectiveApiKeyLength: effectiveApiKey?.length ?? 0,
    }),
  );

  if (!effectiveApiKey) {
    throw new Error('OPENAI_API_KEY is required for agent execution');
  }

  // Keep the runtime env in sync for SDK calls that resolve credentials lazily.
  process.env.OPENAI_API_KEY = effectiveApiKey;

  console.log(
    '[agent-openai] run credentials synced',
    JSON.stringify({
      runId: summary.runId,
      runtimeEnvHasOpenAiKey: Boolean(process.env.OPENAI_API_KEY),
      runtimeEnvOpenAiKeyLength: process.env.OPENAI_API_KEY?.length ?? 0,
    }),
  );

  const now = new Date().toISOString();
  const fingerprintStore = new FingerprintStore(config.fingerprintFile);
  const files = await resolveTargetFiles(request, config);
  const ruleSetMd5 = hashString(
    JSON.stringify(
      {
        role: request.role,
        env: request.env,
        skills: request.skills,
        prompt: request.prompt,
        promptTemplateVersion: config.promptTemplateVersion,
      },
      null,
      2,
    ),
  );
  const changedTargets = await fingerprintStore.resolveChanges({
    role: request.role,
    env: request.env,
    ruleSetMd5,
    files: files.map((file) => ({
      relativePath: file.relativePath,
      fileMd5: file.fileMd5,
    })),
  });

  const runContext: RunContextData = {
    runId: summary.runId,
    role: request.role,
    env: request.env,
    initiator: request.initiator,
    skills: request.skills,
    workspaceRoot: config.workspaceRoot,
    reportsDir: config.reportsDir,
    fingerprintsPath: config.fingerprintFile,
    allowedRoots: config.allowedRoots,
    promptTemplateVersion: config.promptTemplateVersion,
  };

  await onSummaryPatch({
    status: 'running',
    startedAt: now,
    ruleSetMd5,
  });

  await onEvent({
    runId: summary.runId,
    type: 'run.started',
    payload: {
      role: request.role,
      env: request.env,
      initiator: request.initiator,
      targetCount: files.length,
      changedCount: changedTargets.filter((target) => target.action === 'analyze').length,
      skippedCount: changedTargets.filter((target) => target.action === 'skip').length,
    },
  });

  const mcpEnv = {
    ...process.env,
    OPENAI_API_KEY: effectiveApiKey,
    AGENT_OPENAI_WORKSPACE_ROOT: config.workspaceRoot,
    AGENT_OPENAI_ALLOWED_ROOTS: config.allowedRoots.join(','),
    AGENT_OPENAI_REPORTS_DIR: config.reportsDir,
    AGENT_OPENAI_FINGERPRINT_FILE: config.fingerprintFile,
    AGENT_OPENAI_MAX_FILE_SIZE_BYTES: String(config.maxFileSizeBytes),
    AGENT_OPENAI_MAX_LIST_FILES: String(config.maxListFiles),
    AGENT_OPENAI_PROMPT_TEMPLATE_VERSION: config.promptTemplateVersion,
  } as Record<string, string>;

  const servers = [
    new MCPServerStdio({
      name: 'workspace',
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/workspaceServer.js')],
      env: mcpEnv,
    }),
    new MCPServerStdio({
      name: 'fingerprint',
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/fingerprintServer.js')],
      env: mcpEnv,
    }),
    new MCPServerStdio({
      name: 'reporting',
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/reportingServer.js')],
      env: mcpEnv,
    }),
  ];

  const manager = await connectMcpServers(servers, {
    connectInParallel: true,
    dropFailed: false,
    strict: true,
  });

  try {
    await onEvent({
      runId: summary.runId,
      type: 'run.log',
      payload: {
        level: 'info',
        message: 'Connected MCP servers',
        activeServers: manager.active.map((server) => server.name),
      },
    });

    const modelSettings: {
      store: true;
      reasoning?: {
        effort: AgentOpenAIConfig['reasoningEffort'];
        summary: 'auto';
      };
      text: {
        verbosity: 'medium';
      };
    } = {
      store: true,
      text: {
        verbosity: 'medium',
      },
    };

    if (config.model.startsWith('gpt-5')) {
      modelSettings.reasoning = {
        effort: config.reasoningEffort,
        summary: 'auto',
      };
    }

    const agent = new Agent<RunContextData>({
      name: `agent-openai-${request.role}`,
      model: config.model,
      instructions: buildInstructions(request, runContext, changedTargets),
      mcpServers: manager.active,
      modelSettings,
    });

    const streamed = await run(agent, request.prompt, {
      stream: true,
      context: runContext,
      maxTurns: 25,
    });

    let eventIndex = 0;

    for await (const event of streamed) {
      eventIndex += 1;
      await onEvent({
        runId: summary.runId,
        type: 'run.progress',
        payload: {
          step: eventIndex,
          eventType: event.type,
        },
      });

      if (event.type === 'run_item_stream_event') {
        const itemEvent = event as {
          name: string;
          item: { toJSON(): { rawItem?: Record<string, unknown> } };
        };

        if (itemEvent.name === 'tool_called') {
          const item = itemEvent.item.toJSON();
          const rawItem = item.rawItem as Record<string, unknown> | undefined;
          await onEvent({
            runId: summary.runId,
            type: 'run.mcp_call',
            payload: {
              name: rawItem?.name ?? 'unknown',
              kind: 'tool_called',
            },
          });
        }

        if (itemEvent.name === 'tool_output') {
          const item = itemEvent.item.toJSON();
          const rawItem = item.rawItem as Record<string, unknown> | undefined;
          await onEvent({
            runId: summary.runId,
            type: 'run.log',
            payload: {
              level: 'info',
              message: 'Tool output received',
              toolOutputType: rawItem?.type ?? 'unknown',
            },
          });
        }

        if (itemEvent.name === 'reasoning_item_created') {
          const rawItem = itemEvent.item.toJSON().rawItem as Record<string, unknown> | undefined;
          await onEvent({
            runId: summary.runId,
            type: 'run.reasoning',
            payload: {
              source: 'reasoning_item',
              itemType: rawItem?.type ?? 'reasoning',
              data: rawItem ?? {},
            },
          });
        }

        if (itemEvent.name === 'message_output_created') {
          const rawItem = itemEvent.item.toJSON().rawItem as Record<string, unknown> | undefined;
          await onEvent({
            runId: summary.runId,
            type: 'run.log',
            payload: {
              level: 'info',
              message: 'Message output created',
              messageType: rawItem?.type ?? 'message',
            },
          });
        }
      }

      if (event.type === 'raw_model_stream_event') {
        const rawEvent = event as { data: Record<string, unknown> };
        const raw = rawEvent.data;
        const rawType = String(raw['type'] ?? 'unknown');

        if (rawType.includes('reasoning')) {
          await onEvent({
            runId: summary.runId,
            type: 'run.reasoning',
            payload: {
              source: 'raw_model_stream_event',
              eventType: rawType,
              data: raw,
            },
          });
        }

        const delta = extractTextDelta(raw);
        if (delta !== undefined) {
          await onEvent({
            runId: summary.runId,
            type: 'run.log',
            payload: {
              level: 'debug',
              message: 'Model text delta',
              delta,
            },
          });
        }
      }
    }

    await streamed.completed;

    const finalOutput = stringifyFinalOutput(streamed.finalOutput);
    const checkedFiles = changedTargets
      .filter((target) => target.action === 'analyze')
      .map((target) => target.relativePath);
    const report = await writeFinalReport({
      runId: summary.runId,
      role: request.role,
      reportsDir: config.reportsDir,
      finalOutput,
    });

    for (const target of changedTargets) {
      if (target.action !== 'analyze') {
        continue;
      }

      await fingerprintStore.add({
        role: request.role,
        env: request.env,
        relativePath: target.relativePath,
        fileMd5: target.fileMd5,
        ruleSetMd5,
        checkedAt: new Date().toISOString(),
        runId: summary.runId,
        status: 'checked',
      });
    }

    await onSummaryPatch({
      status: 'completed',
      finishedAt: new Date().toISOString(),
      finalOutput,
      reportPath: report.reportPath,
      reportName: report.reportName,
      checkedFiles,
    });

    await onEvent({
      runId: summary.runId,
      type: 'run.report_written',
      payload: report,
    });

    await onEvent({
      runId: summary.runId,
      type: 'run.completed',
      payload: {
        finalOutput,
        checkedFiles,
      },
    });
  } finally {
    await manager.close();
  }
}

async function resolveTargetFiles(
  request: RunRequestPayload,
  config: AgentOpenAIConfig,
): Promise<FileFingerprint[]> {
  const paths = Array.isArray(request.context?.paths)
    ? request.context.paths.filter((value): value is string => typeof value === 'string')
    : [];

  const uniquePaths = [...new Set(paths)];
  const fingerprints: FileFingerprint[] = [];

  for (const rawPath of uniquePaths) {
    const absolutePath = path.isAbsolute(rawPath)
      ? rawPath
      : path.join(config.workspaceRoot, rawPath);
    const normalized = path.resolve(absolutePath);

    const isAllowed = config.allowedRoots.some((root) => {
      const relative = path.relative(root, normalized);
      return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
    });

    if (!isAllowed) {
      continue;
    }

    const stat = await fs.stat(normalized);
    if (!stat.isFile() || stat.size > config.maxFileSizeBytes) {
      continue;
    }

    fingerprints.push({
      absolutePath: normalized,
      relativePath: toRelativePath(config.workspaceRoot, normalized),
      fileMd5: await hashFile(normalized),
    });
  }

  return fingerprints;
}

function stringifyFinalOutput(finalOutput: unknown): string {
  if (typeof finalOutput === 'string') {
    return finalOutput;
  }

  if (finalOutput === undefined) {
    return '';
  }

  return JSON.stringify(finalOutput, null, 2);
}

function extractTextDelta(rawEvent: Record<string, unknown>): string | undefined {
  if (typeof rawEvent.delta === 'string' && rawEvent.delta.trim() !== '') {
    return rawEvent.delta;
  }

  const text = rawEvent.text;
  if (typeof text === 'string' && text.trim() !== '') {
    return text;
  }

  return undefined;
}

async function writeFinalReport(input: {
  runId: string;
  role: string;
  reportsDir: string;
  finalOutput: string;
}): Promise<{ reportPath: string; reportName: string }> {
  await ensureDir(input.reportsDir);
  const reportName = sanitizeFileName(`${input.role}-${input.runId}.md`);
  const reportPath = path.join(input.reportsDir, reportName);
  await fs.writeFile(reportPath, input.finalOutput || '# Empty report\n', 'utf8');
  return { reportPath, reportName };
}
