import { useTableStore } from '../stores/useTableStore'
import { useGuardianStore } from '../stores/useGuardianStore'
import { parseGuardianAgentStatusesSnapshot, parseGuardianAgentStatusUpdate } from '../types/guardianAgentRuns'
import type { TableMutationEntry } from '../types/table'
import { VueSignalRouter } from './VueSignalRouter'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null
}

function handleTableData(data: unknown): void {
  if (!isRecord(data) || !isRecord(data.tables)) return

  const tableStore = useTableStore()
  const tables = data.tables as Record<string, unknown>
  tableStore.applyTablesPayload(tables)

  for (const key of Object.keys(tables)) {
    tableStore.completeTableRefreshForKey(key)
  }
}

function handleTableMutation(data: unknown): void {
  if (!isRecord(data)) return

  const tableStore = useTableStore()
  const tableKey = data['tableKey'] as string | undefined
  const mutation = data['mutation'] as TableMutationEntry | undefined

  if (tableKey && mutation) {
    tableStore.applyTableMutation(tableKey, mutation)
  }
}

function handleTableActionError(data: unknown): void {
  if (!isRecord(data)) return

  const tableStore = useTableStore()
  const tableKey = data['tableKey'] as string | undefined

  if (tableKey) {
    tableStore.completeTableRefreshForKey(tableKey)
  }

  const msg = data['message'] as string | undefined
  if (msg) {
    console.error(`[Table action error] ${data['tableKey'] ?? ''}: ${msg}`)
  }
}

function handleGuardianAgentStatusUpdate(data: unknown): void {
  const update = parseGuardianAgentStatusUpdate(data)
  if (update) {
    const guardianStore = useGuardianStore()
    guardianStore.setGuardianAgentStatus(update.agentId, update.status)
  }
}

function handleGuardianSnapshot(data: unknown): void {
  const snapshot = parseGuardianAgentStatusesSnapshot(data)
  if (snapshot) {
    const guardianStore = useGuardianStore()
    guardianStore.setGuardianAgentStatuses(snapshot)
  }
}

/**
 * Register framework-level signal handlers on the router.
 * Demo projects call this, then add their own handlers.
 */
export function registerHilosSignalHandlers(router: VueSignalRouter): void {
  router.on('table_data', handleTableData)
  router.on('table_mutation', handleTableMutation)
  router.on('table_action_error', handleTableActionError)
  router.on('guardian_agent_status_update', handleGuardianAgentStatusUpdate)
  router.on('subscription_page_hilos_guardian', handleGuardianSnapshot)
  router.on('subscription_page_hilos_guardian_agent', handleGuardianSnapshot)
  router.on('subscription_updated', () => {})

  router.onPrefix('subscription_page_', (data: unknown) => {
    if (!isRecord(data)) return
    if (isRecord(data.tables)) {
      const tableStore = useTableStore()
      tableStore.applyTablesPayload(data.tables as Record<string, unknown>)
    }
  })
}

/**
 * Create a VueSignalRouter with all framework handlers pre-registered.
 */
export function createHilosSignalRouter(): VueSignalRouter {
  const router = new VueSignalRouter()
  registerHilosSignalHandlers(router)
  return router
}
