import { useTableStore } from '../stores/useTableStore'
import { extractBrowserPagePayload } from '../types/browserState'
import {
  actionError,
  tableData,
  tableMutation,
  tableMutationPending,
  tableActionError,
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
    tableStore.applyImmediateTableMutation(tableKey, mutation)
  })

  router.on(tableMutationPending, ({ tableKey, mutation }) => {
    const tableStore = useTableStore()
    tableStore.queuePendingTableMutation(tableKey, mutation)
  })

  router.on(tableActionError, ({ tableKey, message }) => {
    if (message) {
      console.error(`[Table action error] ${tableKey}: ${message}`)
    }
  })

  router.on(subscriptionUpdated, () => {})

  router.onPrefix(SUBSCRIPTION_PAGE_PREFIX, (data: unknown) => {
    if (extractBrowserPagePayload(data) !== null) {
      return
    }
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
