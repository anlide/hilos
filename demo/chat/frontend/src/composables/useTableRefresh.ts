import { computed, watch, onBeforeUnmount } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { TableActionConstants } from '@hilos/sdk/constants'
import { useChatStore } from '@/stores'
import { sendAction } from '@/services/websocketActions'
import { TABLE_REFRESH_UI_TIMEOUT_MS } from '@/constants'

/**
 * Table refresh via WebSocket: loading state, safety timeout, and completion on table_data / table_action_error.
 */
export function useTableRefresh(tableKey: string) {
  const chatStore = useChatStore()
  const websocket = useWebSocket()
  let safetyTimer: ReturnType<typeof setTimeout> | null = null

  const refreshLoading = computed(() => chatStore.pendingTableRefreshKey === tableKey)

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
    chatStore.startTableRefresh(tableKey)
    clearSafetyTimer()
    safetyTimer = setTimeout(() => {
      safetyTimer = null
      chatStore.completeTableRefreshForKey(tableKey)
    }, TABLE_REFRESH_UI_TIMEOUT_MS)
    sendAction(websocket, TableActionConstants.TABLE_REFRESH, {
      [TableActionConstants.PAYLOAD_KEY_TABLE_KEY]: tableKey,
    })
  }

  onBeforeUnmount(() => {
    clearSafetyTimer()
    chatStore.completeTableRefreshForKey(tableKey)
  })

  return { refreshLoading, refreshTable }
}
