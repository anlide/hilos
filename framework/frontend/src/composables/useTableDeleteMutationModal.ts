import { ref, watch, onBeforeUnmount } from 'vue'
import { useTableStore } from '../stores'

/** If no delete mutation arrives within this window, loading state is cleared (modal stays open). */
export const TABLE_DELETE_FAIL_TIMEOUT_MS = 12000

/**
 * Delete confirmation flow: send action, show loading, close when TABLE_MUTATION delete matches row id.
 */
export function useTableDeleteMutationModal<T>(
  tableKey: string,
  getRowId: (item: T) => string | number | null | undefined,
) {
  const tableStore = useTableStore()
  const showDeleteModal = ref(false)
  const deleteTarget = ref<T | null>(null)
  const deleteLoading = ref(false)
  const mutationsLengthBeforeDelete = ref(0)
  const deleteSuccessHandled = ref(false)
  let deleteFailTimeoutId: ReturnType<typeof setTimeout> | null = null

  const clearFailTimeout = () => {
    if (deleteFailTimeoutId !== null) {
      clearTimeout(deleteFailTimeoutId)
      deleteFailTimeoutId = null
    }
  }

  const resetDeleteModal = () => {
    clearFailTimeout()
    deleteSuccessHandled.value = false
    showDeleteModal.value = false
    deleteTarget.value = null
    deleteLoading.value = false
    mutationsLengthBeforeDelete.value = 0
  }

  const openDeleteModal = (item: T) => {
    deleteTarget.value = item
    showDeleteModal.value = true
  }

  const confirmDelete = (sendAction: () => void) => {
    if (deleteTarget.value == null) return
    const rowId = getRowId(deleteTarget.value)
    if (rowId === null || rowId === undefined || rowId === '') return

    const state = tableStore.tableData[tableKey]
    mutationsLengthBeforeDelete.value = state?.mutations.length ?? 0
    deleteLoading.value = true

    clearFailTimeout()
    deleteFailTimeoutId = setTimeout(() => {
      deleteFailTimeoutId = null
      if (deleteLoading.value) {
        deleteLoading.value = false
      }
    }, TABLE_DELETE_FAIL_TIMEOUT_MS)

    sendAction()
  }

  watch(
    () => tableStore.tableData[tableKey]?.mutations,
    (mutations) => {
      if (deleteTarget.value == null) return
      const rowId = getRowId(deleteTarget.value)
      if (rowId === null || rowId === undefined || rowId === '' || !deleteLoading.value || deleteSuccessHandled.value) {
        return
      }
      const muts = mutations ?? []
      const tail = muts.slice(mutationsLengthBeforeDelete.value)
      const ok = tail.some(
        (m) => m.type === 'deleted' && String(m.rowId) === String(rowId),
      )
      if (!ok) return

      deleteSuccessHandled.value = true
      clearFailTimeout()
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
