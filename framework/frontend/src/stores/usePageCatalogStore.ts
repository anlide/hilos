import { defineStore } from 'pinia'
import { normalizePageCatalog } from '../types/pageCatalog'
import type { PageCatalogState } from '../types/pageCatalog'

export const usePageCatalogStore = defineStore('pageCatalog', {
  state: () => ({
    pageCatalog: {} as PageCatalogState,
    hasPageCatalog: false,
  }),

  actions: {
    setPageCatalog(value: Record<string, unknown>) {
      this.pageCatalog = normalizePageCatalog(value)
      this.hasPageCatalog = Object.keys(this.pageCatalog).length > 0
    },
  },
})
