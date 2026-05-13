import { onBeforeUnmount, ref, watch } from 'vue'
import { useBrowserStore } from '@hilos/sdk/stores'

const BROWSER_DELETE_FAIL_TIMEOUT_MS = 12000

/**
 * Delete confirmation flow for browser rows: send action, show loading, close when the row disappears.
 */
export function useBrowserTableDeleteMutationModal<T>(
  pageKey: string,
  tableKey: string,
  getRowKey: (item: T) => string | number | null | undefined,
) {
  const browserStore = useBrowserStore()
  const showDeleteModal = ref(false)
  const deleteTarget = ref<T | null>(null)
  const deleteLoading = ref(false)
  let deleteFailTimeoutId: ReturnType<typeof setTimeout> | null = null

  const clearFailTimeout = () => {
    if (deleteFailTimeoutId !== null) {
      clearTimeout(deleteFailTimeoutId)
      deleteFailTimeoutId = null
    }
  }

  const resetDeleteModal = () => {
    clearFailTimeout()
    showDeleteModal.value = false
    deleteTarget.value = null
    deleteLoading.value = false
  }

  const openDeleteModal = (item: T) => {
    deleteTarget.value = item
    showDeleteModal.value = true
  }

  const confirmDelete = (sendAction: () => void) => {
    if (deleteTarget.value == null) return
    const rowKey = getRowKey(deleteTarget.value)
    if (rowKey === null || rowKey === undefined || rowKey === '') return

    deleteLoading.value = true
    clearFailTimeout()
    deleteFailTimeoutId = setTimeout(() => {
      deleteFailTimeoutId = null
      if (deleteLoading.value) {
        deleteLoading.value = false
      }
    }, BROWSER_DELETE_FAIL_TIMEOUT_MS)

    sendAction()
  }

  watch(
    () => browserStore.pages[pageKey]?.tables[tableKey]?.rowsByKey,
    (rowsByKey) => {
      if (deleteTarget.value == null || !deleteLoading.value) return
      const rowKey = getRowKey(deleteTarget.value)
      if (rowKey === null || rowKey === undefined || rowKey === '') return
      if (rowsByKey?.[String(rowKey)] !== undefined) return

      resetDeleteModal()
    },
    { deep: true },
  )

  onBeforeUnmount(() => {
    clearFailTimeout()
  })

  return {
    showDeleteModal,
    deleteTarget,
    deleteLoading,
    resetDeleteModal,
    openDeleteModal,
    confirmDelete,
  }
}
