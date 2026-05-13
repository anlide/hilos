import { defineStore } from 'pinia'
import type { BrowserPagePayload, BrowserPageRow } from '../types/browserState'

export interface BrowserTableState {
  rowsByKey: Record<string, BrowserPageRow>
}

export interface BrowserPageState {
  tables: Record<string, BrowserTableState>
}

export const useBrowserStore = defineStore('browser', {
  state: () => ({
    pages: {} as Record<string, BrowserPageState>,
  }),

  actions: {
    applyPagePayload(pageKey: string, payload: BrowserPagePayload) {
      const pageState = this.pages[pageKey] ?? { tables: {} }
      const nextTables = { ...pageState.tables }

      for (const [tableKey, changes] of Object.entries(payload.tables)) {
        const tableState = nextTables[tableKey] ?? { rowsByKey: {} }
        const rowsByKey = { ...tableState.rowsByKey }

        for (const row of changes.rows ?? []) {
          rowsByKey[String(row.rowKey)] = row
        }

        for (const rowKey of changes.deleted ?? []) {
          delete rowsByKey[String(rowKey)]
        }

        nextTables[tableKey] = { rowsByKey }
      }

      this.pages = {
        ...this.pages,
        [pageKey]: { tables: nextTables },
      }
    },
  },
})
