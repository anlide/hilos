import { query } from '@anthropic-ai/claude-agent-sdk';
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

type ClaudeRuntimeConfig = Pick<
  AgentHostConfig,
  | 'workspaceRoot'
  | 'reportsDir'
  | 'fingerprintFile'
  | 'allowedRoots'
  | 'maxFileSizeBytes'
  | 'maxListFiles'
  | 'promptTemplateVersion'
> &
  AgentHostConfig['claude'];

export class ClaudeProvider implements UnifiedProvider {
  public readonly name = 'claude' as const;

  public constructor(private readonly config: ClaudeRuntimeConfig) {}

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
    const effectiveApiKey = this.config.apiKey ?? process.env.ANTHROPIC_API_KEY;

    if (!effectiveApiKey) {
      throw new Error('ANTHROPIC_API_KEY is required for agent execution');
    }

    process.env.ANTHROPIC_API_KEY = effectiveApiKey;

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

    const mcpServers = {
      workspace: {
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('workspace')],
        env: mcpEnv,
      },
      fingerprint: {
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('fingerprint')],
        env: mcpEnv,
      },
      reporting: {
        command: process.execPath,
        args: [resolveMcpServerEntrypoint('reporting')],
        env: mcpEnv,
      },
    };

    let stepIndex = 0;
    let finalOutput = '';

    for await (const message of query({
      prompt: request.prompt,
      options: {
        model: this.config.model,
        systemPrompt: buildInstructions(this.name, request, runContext, changedTargets),
        mcpServers,
        allowedTools: ['mcp__workspace__*', 'mcp__fingerprint__*', 'mcp__reporting__*'],
        maxTurns: this.config.maxTurns,
        cwd: this.config.workspaceRoot,
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
            provider: this.name,
            messageType: 'assistant',
          },
        });

        const rawContent = (message as { type: 'assistant'; message: { content: unknown } }).message.content;
        const content = Array.isArray(rawContent) ? rawContent : [];

        for (const block of content) {
          if (typeof block !== 'object' || block === null) {
            continue;
          }

          const currentBlock = block as Record<string, unknown>;

          if (currentBlock.type === 'tool_use') {
            await onEvent({
              runId: summary.runId,
              type: 'run.mcp_call',
              payload: {
                provider: this.name,
                name: currentBlock.name ?? 'unknown',
                kind: 'tool_called',
                input: currentBlock.input,
              },
            });
          } else if (
            currentBlock.type === 'text' &&
            typeof currentBlock.text === 'string' &&
            currentBlock.text.trim() !== ''
          ) {
            await onEvent({
              runId: summary.runId,
              type: 'run.log',
              payload: {
                level: 'debug',
                provider: this.name,
                message: 'Model text delta',
                delta: currentBlock.text,
              },
            });
          } else if (currentBlock.type === 'thinking' && typeof currentBlock.thinking === 'string') {
            await onEvent({
              runId: summary.runId,
              type: 'run.reasoning',
              payload: {
                provider: this.name,
                source: 'thinking_block',
                data: currentBlock.thinking,
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
      reportsDir: this.config.reportsDir,
      finalOutput: stringifyFinalOutput(finalOutput),
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
  }
}
