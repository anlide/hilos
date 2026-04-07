import type { AgentProvider, ChangedTargetResult, RunContextData, RunRequestPayload } from '@hilos/agent-core';

const PROVIDER_LABELS: Record<AgentProvider, string> = {
  claude: 'Claude',
  openai: 'OpenAI',
};

export function buildInstructions(
  provider: AgentProvider,
  request: RunRequestPayload,
  context: RunContextData,
  changedTargets: ChangedTargetResult[],
): string {
  const analyzeTargets = changedTargets.filter((target) => target.action === 'analyze');
  const skipTargets = changedTargets.filter((target) => target.action === 'skip');

  return [
    `You are the Hilos ${PROVIDER_LABELS[provider]} agent server.`,
    `Provider: ${provider}`,
    `Role: ${request.role}`,
    `Environment: ${request.env}`,
    `Initiator: ${request.initiator}`,
    `Run ID: ${context.runId}`,
    '',
    'Primary responsibilities:',
    '- Follow the role semantics exactly.',
    '- Use MCP tools for file access instead of guessing project contents.',
    '- Prefer analyzing only files that are marked as changed or not-yet-checked.',
    '- If a final report is needed, prepare a concise markdown report.',
    '',
    'Available skills summary:',
    request.skills,
    '',
    'Fingerprint guidance:',
    `- Prompt template version: ${context.promptTemplateVersion}`,
    `- Files to analyze now: ${analyzeTargets.length}`,
    `- Files already covered by matching fingerprints: ${skipTargets.length}`,
    '- Re-check skipped targets only when they become necessary for correctness.',
    '',
    'Changed targets snapshot:',
    JSON.stringify(changedTargets, null, 2),
    '',
    'Workspace rules:',
    `- Allowed roots: ${context.allowedRoots.join(', ')}`,
    `- Workspace root: ${context.workspaceRoot}`,
    `- Reports directory: ${context.reportsDir}`,
    '',
    'When you finish, produce a clear final answer that can be stored as a report.',
  ].join('\n');
}
