import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { query } from '@anthropic-ai/claude-agent-sdk';

import type { AgentClaudeConfig } from '../config.js';
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
  config: AgentClaudeConfig;
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
  const effectiveApiKey = config.anthropicApiKey ?? process.env.ANTHROPIC_API_KEY;

  if (!effectiveApiKey) {
    throw new Error('ANTHROPIC_API_KEY is required for agent execution');
  }

  // Keep the runtime env in sync for SDK calls that resolve credentials lazily.
  process.env.ANTHROPIC_API_KEY = effectiveApiKey;

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
    ANTHROPIC_API_KEY: effectiveApiKey,
    AGENT_CLAUDE_WORKSPACE_ROOT: config.workspaceRoot,
    AGENT_CLAUDE_ALLOWED_ROOTS: config.allowedRoots.join(','),
    AGENT_CLAUDE_REPORTS_DIR: config.reportsDir,
    AGENT_CLAUDE_FINGERPRINT_FILE: config.fingerprintFile,
    AGENT_CLAUDE_MAX_FILE_SIZE_BYTES: String(config.maxFileSizeBytes),
    AGENT_CLAUDE_MAX_LIST_FILES: String(config.maxListFiles),
    AGENT_CLAUDE_PROMPT_TEMPLATE_VERSION: config.promptTemplateVersion,
  } as Record<string, string>;

  const mcpServers = {
    workspace: {
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/workspaceServer.js')],
      env: mcpEnv,
    },
    fingerprint: {
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/fingerprintServer.js')],
      env: mcpEnv,
    },
    reporting: {
      command: process.execPath,
      args: [path.join(__dirname, '../mcp/reportingServer.js')],
      env: mcpEnv,
    },
  };

  let stepIndex = 0;
  let finalOutput = '';

  for await (const message of query({
    prompt: request.prompt,
    options: {
      model: config.model,
      systemPrompt: buildInstructions(request, runContext, changedTargets),
      mcpServers,
      allowedTools: ['mcp__workspace__*', 'mcp__fingerprint__*', 'mcp__reporting__*'],
      maxTurns: config.maxTurns,
      cwd: config.workspaceRoot,
    },
  })) {
    stepIndex += 1;

    if (message.type === 'system') {
      await onEvent({
        runId: summary.runId,
        type: 'run.log',
        payload: {
          level: 'info',
          message: 'Claude session initialized',
          subtype: (message as { type: 'system'; subtype: string }).subtype,
        },
      });
      continue;
    }

    if (message.type === 'assistant') {
      await onEvent({
        runId: summary.runId,
        type: 'run.progress',
        payload: {
          step: stepIndex,
          messageType: 'assistant',
        },
      });

      const rawContent = (message as { type: 'assistant'; message: { content: unknown } }).message.content;
      const content = Array.isArray(rawContent) ? rawContent : [];

      for (const block of content) {
        if (typeof block !== 'object' || block === null) {
          continue;
        }

        const b = block as Record<string, unknown>;

        if (b['type'] === 'tool_use') {
          await onEvent({
            runId: summary.runId,
            type: 'run.mcp_call',
            payload: {
              name: b['name'] ?? 'unknown',
              kind: 'tool_called',
              input: b['input'],
            },
          });
        } else if (b['type'] === 'text' && typeof b['text'] === 'string' && (b['text'] as string).trim() !== '') {
          await onEvent({
            runId: summary.runId,
            type: 'run.log',
            payload: {
              level: 'debug',
              message: 'Model text delta',
              delta: b['text'],
            },
          });
        } else if (b['type'] === 'thinking' && typeof b['thinking'] === 'string') {
          await onEvent({
            runId: summary.runId,
            type: 'run.reasoning',
            payload: {
              source: 'thinking_block',
              data: b['thinking'],
            },
          });
        }
      }

      continue;
    }

    if (message.type === 'result') {
      const result = message as { type: 'result'; subtype: string; result?: string };
      if (result.subtype === 'success') {
        finalOutput = typeof result.result === 'string' ? result.result : '';
      } else {
        throw new Error(`Agent run ended with error: ${result.subtype}`);
      }
    }
  }

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
}

async function resolveTargetFiles(
  request: RunRequestPayload,
  config: AgentClaudeConfig,
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

async function writeFinalReport(input: {
  runId: string;
  role: string;
  reportsDir: string;
  finalOutput: string;
}): Promise<{ reportPath: string; reportName: string }> {
  await ensureDir(input.reportsDir);
  const reportName = sanitizeFileName(`${input.role}-${input.runId}.md`);
  const reportPath = path.join(input.reportsDir, reportName);
  await fs.writeFile(reportPath, stringifyFinalOutput(input.finalOutput) || '# Empty report\n', 'utf8');
  return { reportPath, reportName };
}