import { useGuardianStore } from '../stores/useGuardianStore'
import { useTableStore } from '../stores/useTableStore'
import {
  actionError,
  tableData,
  tableMutation,
  tableActionError,
  guardianAgentStatusUpdate,
  subscriptionPageHilosGuardian,
  subscriptionPageHilosGuardianAgent,
  subscriptionUpdated,
  SUBSCRIPTION_PAGE_PREFIX,
} from '../signals'
import { VueSignalRouter } from './VueSignalRouter'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null
}

/**
 * Register framework-level signal handlers on the router.
 * Demo projects call this, then add their own handlers.
 */
export function registerHilosSignalHandlers(router: VueSignalRouter): void {
  router.on(actionError, ({ action, reason }) => {
    console.error(`[Action error] ${action}: ${reason}`)
  })

  router.on(tableData, ({ tables }) => {
    const tableStore = useTableStore()
    tableStore.applyTablesPayload(tables)
  })

  router.on(tableMutation, ({ tableKey, mutation }) => {
    const tableStore = useTableStore()
    tableStore.applyTableMutation(tableKey, mutation)
  })

  router.on(tableActionError, ({ tableKey, message }) => {
    if (message) {
      console.error(`[Table action error] ${tableKey}: ${message}`)
    }
  })

  router.on(guardianAgentStatusUpdate, ({ agentId, status }) => {
    const guardianStore = useGuardianStore()
    guardianStore.setGuardianAgentStatus(agentId, status)
  })

  router.on(subscriptionPageHilosGuardian, (snapshot) => {
    const guardianStore = useGuardianStore()
    guardianStore.setGuardianAgentStatuses(snapshot)
  })

  router.on(subscriptionPageHilosGuardianAgent, (snapshot) => {
    const guardianStore = useGuardianStore()
    guardianStore.setGuardianAgentStatuses(snapshot)
  })

  router.on(subscriptionUpdated, () => {})

  router.onPrefix(SUBSCRIPTION_PAGE_PREFIX, (data: unknown) => {
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
