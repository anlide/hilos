import { computed, watch, onBeforeUnmount } from 'vue'
import { useWebSocket } from '../plugins/websocket'
import { TableActionConstants } from '../constants/tableActions'
import { useTableStore } from '../stores/useTableStore'
import { sendAction } from '../services/websocketActions'

/** Max time the table refresh button stays in loading state if the server does not respond. */
const TABLE_REFRESH_UI_TIMEOUT_MS = 12_000

/**
 * Table refresh via WebSocket: loading state, safety timeout, and completion on table_data / table_action_error.
 */
export function useTableRefresh(tableKey: string) {
  const tableStore = useTableStore()
  const websocket = useWebSocket()
  let safetyTimer: ReturnType<typeof setTimeout> | null = null

  const refreshLoading = computed(() => tableStore.pendingTableRefreshKey === tableKey)

  const clearSafetyTimer = () => {
    if (safetyTimer !== null) {
      clearTimeout(safetyTimer)
      safetyTimer = null
    }
  }

  watch(refreshLoading, (loading) => {
    if (!loading) {
      clearSafetyTimer()
    }
  })

  const refreshTable = () => {
    if (refreshLoading.value) {
      return
    }
    tableStore.startTableRefresh(tableKey)
    clearSafetyTimer()
    safetyTimer = setTimeout(() => {
      safetyTimer = null
      tableStore.completeTableRefreshForKey(tableKey)
    }, TABLE_REFRESH_UI_TIMEOUT_MS)
    sendAction(websocket, TableActionConstants.TABLE_REFRESH, {
      [TableActionConstants.PAYLOAD_KEY_TABLE_KEY]: tableKey,
    })
  }

  onBeforeUnmount(() => {
    clearSafetyTimer()
    tableStore.completeTableRefreshForKey(tableKey)
  })

  return { refreshLoading, refreshTable }
}
