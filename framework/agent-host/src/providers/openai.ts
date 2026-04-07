import { Agent, MCPServerStdio, connectMcpServers, run } from '@openai/agents';
import {
  FingerprintStore,
  buildRuleSetMd5,
  resolveMcpServerEntrypoint,
  resolveTargetFiles,
  stringifyFinalOutput,
  writeFinalReport,
  type ProviderHealthStatus,
  type RunContextData,
} from '@hilos/agent-core';

import type { AgentHostConfig } from '../config.js';
import { buildInstructions } from '../agent/buildInstructions.js';
import type { ProviderRunOptions, UnifiedProvider } from './types.js';

type OpenAIRuntimeConfig = Pick<
  AgentHostConfig,
  | 'workspaceRoot'
  | 'reportsDir'
  | 'fingerprintFile'
  | 'allowedRoots'
  | 'maxFileSizeBytes'
  | 'maxListFiles'
  | 'promptTemplateVersion'
> &
  AgentHostConfig['openai'];

export class OpenAIProvider implements UnifiedProvider {
  public readonly name = 'openai' as const;

  public constructor(private readonly config: OpenAIRuntimeConfig) {}

  public isConfigured(): boolean {
    return Boolean(this.config.apiKey);
  }

  public getHealthStatus(): ProviderHealthStatus {
    return {
      configured: this.isConfigured(),
      model: this.config.model,
    };
  }

  public async runAgentTask(options: ProviderRunOptions): Promise<void> {
    const { request, summary, onEvent, onSummaryPatch } = options;
    const effectiveApiKey = this.config.apiKey ?? process.env.OPENAI_API_KEY;

    if (!effectiveApiKey) {
      throw new Error('OPENAI_API_KEY is required for agent execution');
    }

    process.env.OPENAI_API_KEY = effectiveApiKey;

    const now = new Date().toISOString();
    const fingerprintStore = new FingerprintStore(this.config.fingerprintFile);
    const files = await resolveTargetFiles(request, this.config);
    const ruleSetMd5 = buildRuleSetMd5({
      role: request.role,
      env: request.env,
      skills: request.skills,
      prompt: request.prompt,
      promptTemplateVersion: this.config.promptTemplateVersion,
    });
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
      workspaceRoot: this.config.workspaceRoot,
      reportsDir: this.config.reportsDir,
      fingerprintsPath: this.config.fingerprintFile,
      allowedRoots: this.config.allowedRoots,
      promptTemplateVersion: this.config.promptTemplateVersion,
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
        provider: this.name,
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
      AGENT_WORKSPACE_ROOT: this.config.workspaceRoot,
      AGENT_ALLOWED_ROOTS: this.config.allowedRoots.join(','),
      AGENT_REPORTS_DIR: this.config.reportsDir,
      AGENT_FINGERPRINT_FILE: this.config.fingerprintFile,
      AGENT_MAX_FILE_SIZE_BYTES: String(this.config.maxFileSizeBytes),
      AGENT_MAX_LIST_FILES: String(this.config.maxListFiles),
      AGENT_PROMPT_TEMPLATE_VERSION: this.config.promptTemplateVersion,
    } as Record<string, string>;

    const servers = [
      new MCPServerStdio({
        name: 'workspace',
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('workspace')],
        env: mcpEnv,
      }),
      new MCPServerStdio({
        name: 'fingerprint',
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('fingerprint')],
        env: mcpEnv,
      }),
      new MCPServerStdio({
        name: 'reporting',
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('reporting')],
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
          provider: this.name,
          message: 'Connected MCP servers',
          activeServers: manager.active.map((server) => server.name),
        },
      });

      const modelSettings: {
        store: true;
        reasoning?: {
          effort: OpenAIRuntimeConfig['reasoningEffort'];
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

      if (this.config.model.startsWith('gpt-5')) {
        modelSettings.reasoning = {
          effort: this.config.reasoningEffort,
          summary: 'auto',
        };
      }

      const agent = new Agent<RunContextData>({
        name: `agent-host-openai-${request.role}`,
        model: this.config.model,
        instructions: buildInstructions(this.name, request, runContext, changedTargets),
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
            provider: this.name,
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
                provider: this.name,
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
                provider: this.name,
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
                provider: this.name,
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
                provider: this.name,
                message: 'Message output created',
                messageType: rawItem?.type ?? 'message',
              },
            });
          }
        }

        if (event.type === 'raw_model_stream_event') {
          const rawEvent = event as { data: Record<string, unknown> };
          const raw = rawEvent.data;
          const rawType = String(raw.type ?? 'unknown');

          if (rawType.includes('reasoning')) {
            await onEvent({
              runId: summary.runId,
              type: 'run.reasoning',
              payload: {
                provider: this.name,
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
                provider: this.name,
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
        reportsDir: this.config.reportsDir,
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
        payload: {
          provider: this.name,
          ...report,
        },
      });

      await onEvent({
        runId: summary.runId,
        type: 'run.completed',
        payload: {
          provider: this.name,
          finalOutput,
          checkedFiles,
        },
      });
    } finally {
      await manager.close();
    }
  }
}

function extractTextDelta(rawEvent: Record<string, unknown>): string | undefined {
  if (typeof rawEvent.delta === 'string' && rawEvent.delta.trim() !== '') {
    return rawEvent.delta;
  }

  if (typeof rawEvent.text === 'string' && rawEvent.text.trim() !== '') {
    return rawEvent.text;
  }

  return undefined;
}
