export const guardianRunStatuses = [
  'not_started',
  'in_progress',
  'done',
  'stopped',
  'failed',
] as const

export type GuardianRunStatus = (typeof guardianRunStatuses)[number]

export type GuardianAgentStatusMap = Record<string, GuardianRunStatus>

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null
}

export const isGuardianRunStatus = (value: unknown): value is GuardianRunStatus => {
  return typeof value === 'string' && guardianRunStatuses.includes(value as GuardianRunStatus)
}

export const parseGuardianAgentStatusesSnapshot = (value: unknown): GuardianAgentStatusMap | null => {
  if (!isRecord(value) || !isRecord(value.guardianAgentStatuses)) {
    return null
  }

  const snapshot: GuardianAgentStatusMap = {}

  for (const [agentId, status] of Object.entries(value.guardianAgentStatuses)) {
    if (isGuardianRunStatus(status)) {
      snapshot[agentId] = status
    }
  }

  return snapshot
}

export const parseGuardianAgentStatusUpdate = (
  value: unknown,
): { agentId: string; status: GuardianRunStatus } | null => {
  if (!isRecord(value) || typeof value.agentId !== 'string' || !isGuardianRunStatus(value.status)) {
    return null
  }

  return {
    agentId: value.agentId,
    status: value.status,
  }
}
