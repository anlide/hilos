import { defineStore } from 'pinia'
import { applyTableMutations } from '../composables/useTableData'
import type { TableDataState, TableMutationEntry } from '../types/table'

export const useTableStore = defineStore('table', {
  state: () => ({
    tableData: {} as Record<string, TableDataState>,
    pendingTableRefreshKey: null as string | null,
  }),

  actions: {
    /**
     * Apply tables payload from subscription or refresh response.
     * Payload: { [tableKey]: { rows, totalCount, offset, limit } }
     * Resets mutations for each table key received.
     */
    applyTablesPayload(tables: Record<string, unknown>) {
      if (typeof tables !== 'object' || tables === null) return

      for (const [key, raw] of Object.entries(tables)) {
        if (typeof raw !== 'object' || raw === null) continue
        const r = raw as Record<string, unknown>

        this.tableData = {
          ...this.tableData,
          [key]: {
            rows: Array.isArray(r['rows']) ? r['rows'] as Record<string, unknown>[] : [],
            totalCount: Number(r['totalCount'] ?? 0),
            offset: Number(r['offset'] ?? 0),
            limit: Number(r['limit'] ?? 0),
            mutations: [],
            ...(Array.isArray(r['catalogKeys']) && { catalogKeys: r['catalogKeys'] as string[] }),
            ...(typeof r['rowIdField'] === 'string' && { rowIdField: r['rowIdField'] as string }),
          },
        }
      }
    },

    /**
     * Append a single table mutation (real-time broadcast).
     */
    applyTableMutation(tableKey: string, mutation: TableMutationEntry) {
      const existing = this.tableData[tableKey]
      if (!existing) return

      this.tableData = {
        ...this.tableData,
        [tableKey]: {
          ...existing,
          mutations: [...existing.mutations, mutation],
        },
      }
    },

    /**
     * Apply accumulated pending mutations to the table state.
     * Returns whether any deletes were processed (caller should refresh if so).
     */
    applyPendingMutations(tableKey: string): { hasDeletes: boolean } {
      const existing = this.tableData[tableKey]
      if (!existing || existing.mutations.length === 0) return { hasDeletes: false }

      const result = applyTableMutations(existing)

      this.tableData = {
        ...this.tableData,
        [tableKey]: result.state,
      }

      return { hasDeletes: result.hasDeletes }
    },

    startTableRefresh(tableKey: string) {
      this.pendingTableRefreshKey = tableKey
    },

    completeTableRefreshForKey(tableKey: string) {
      if (this.pendingTableRefreshKey === tableKey) {
        this.pendingTableRefreshKey = null
      }
    },
  },
})
