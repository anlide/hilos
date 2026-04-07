import type { ProviderHealthStatus, RunRequestPayload, RunSummary } from '@hilos/agent-core';

export type EventCallback = (event: {
  runId: string;
  type:
    | 'run.started'
    | 'run.progress'
    | 'run.log'
    | 'run.reasoning'
    | 'run.mcp_call'
    | 'run.report_written'
    | 'run.completed'
    | 'run.failed';
  payload: Record<string, unknown>;
}) => Promise<void> | void;

export interface ProviderRunOptions {
  request: RunRequestPayload;
  summary: RunSummary;
  onEvent: EventCallback;
  onSummaryPatch: (patch: Partial<RunSummary>) => Promise<void> | void;
}

export interface UnifiedProvider {
  readonly name: RunRequestPayload['provider'];
  isConfigured(): boolean;
  getHealthStatus(): ProviderHealthStatus;
  runAgentTask(options: ProviderRunOptions): Promise<void>;
}
