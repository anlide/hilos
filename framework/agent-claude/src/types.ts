export const ALLOWED_ENVS = ['local', 'test', 'dev', 'staging', 'prod'] as const;
export const ALLOWED_INITIATORS = ['user', 'cron'] as const;
export const RUN_STATUSES = ['queued', 'running', 'completed', 'failed'] as const;

export type AgentEnvironment = (typeof ALLOWED_ENVS)[number];
export type AgentInitiator = (typeof ALLOWED_INITIATORS)[number];
export type RunStatus = (typeof RUN_STATUSES)[number];

export interface RunRequestPayload {
  role: string;
  env: AgentEnvironment;
  initiator: AgentInitiator;
  skills: string;
  prompt: string;
  context?: Record<string, unknown>;
}

export interface RunSummary {
  runId: string;
  status: RunStatus;
  role: string;
  env: AgentEnvironment;
  initiator: AgentInitiator;
  skills: string;
  prompt: string;
  context: Record<string, unknown>;
  createdAt: string;
  startedAt?: string;
  finishedAt?: string;
  error?: string;
  finalOutput?: string;
  reportPath?: string;
  reportName?: string;
  ruleSetMd5?: string;
  checkedFiles?: string[];
}

export interface RunEventEnvelope {
  id: string;
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
  timestamp: string;
  payload: Record<string, unknown>;
}

export interface RunsFilter {
  status?: RunStatus;
  role?: string;
  env?: AgentEnvironment;
  initiator?: AgentInitiator;
}

export interface FingerprintRecord {
  role: string;
  env: AgentEnvironment;
  relativePath: string;
  fileMd5: string;
  ruleSetMd5: string;
  checkedAt: string;
  runId: string;
  status: 'checked' | 'skipped';
}

export interface FingerprintIndex {
  records: FingerprintRecord[];
}

export interface ChangedTargetResult {
  relativePath: string;
  fileMd5: string;
  lastCheckedAt?: string;
  lastRunId?: string;
  action: 'analyze' | 'skip';
}

export interface RunContextData {
  runId: string;
  role: string;
  env: AgentEnvironment;
  initiator: AgentInitiator;
  skills: string;
  workspaceRoot: string;
  reportsDir: string;
  fingerprintsPath: string;
  allowedRoots: string[];
  promptTemplateVersion: string;
}