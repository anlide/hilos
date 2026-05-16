import type { BrowserPageRow } from './browserState'

export const BROWSER_PAGE_HILOS_GUARDIAN = 'subscription_page_hilos_guardian'
export const BROWSER_PAGE_HILOS_GUARDIAN_AGENT = 'subscription_page_hilos_guardian_agent'
export const BROWSER_TABLE_GUARDIAN_AGENT_STATUSES = 'guardianAgentStatuses'
export const BROWSER_TABLE_GUARDIAN_AGENT_STATUS_DETAIL = 'guardianAgentStatusDetail'
export const BROWSER_SOURCE_GUARDIAN_AGENT_STATUSES = 'guardianAgentStatuses'

export const guardianRunStatuses = [
  'not_started',
  'in_progress',
  'done',
  'stopped',
  'failed',
] as const

export type GuardianRunStatus = (typeof guardianRunStatuses)[number]

export type GuardianAgentStatusMap = Record<string, GuardianRunStatus>

export type GuardianAgentStatusRow = {
  agentId: string
  status: GuardianRunStatus
  updatedAt: number
}

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null
}

export const isGuardianRunStatus = (value: unknown): value is GuardianRunStatus => {
  return typeof value === 'string' && guardianRunStatuses.includes(value as GuardianRunStatus)
}

export const guardianAgentStatusFromBrowserRow = (
  row: BrowserPageRow | undefined,
): GuardianAgentStatusRow | null => {
  if (row === undefined || !isRecord(row.sources)) {
    return null
  }

  const status = row.sources[BROWSER_SOURCE_GUARDIAN_AGENT_STATUSES]
  if (
    !isRecord(status)
    || typeof status.agentId !== 'string'
    || !isGuardianRunStatus(status.status)
    || typeof status.updatedAt !== 'number'
  ) {
    return null
  }

  return {
    agentId: status.agentId,
    status: status.status,
    updatedAt: status.updatedAt,
  }
}

export const guardianAgentStatusRowsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): Record<string, GuardianAgentStatusRow> => {
  if (rowsByKey === undefined) {
    return {}
  }

  const rows: Record<string, GuardianAgentStatusRow> = {}
  for (const row of Object.values(rowsByKey)) {
    const status = guardianAgentStatusFromBrowserRow(row)
    if (status !== null) {
      rows[status.agentId] = status
    }
  }

  return rows
}

export const guardianAgentStatusesFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): GuardianAgentStatusMap => {
  const statuses: GuardianAgentStatusMap = {}
  for (const status of Object.values(guardianAgentStatusRowsFromBrowserRows(rowsByKey))) {
    statuses[status.agentId] = status.status
  }

  return statuses
}
