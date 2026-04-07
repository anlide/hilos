import { defineStore } from 'pinia'
import type { HilosLogsOverviewSnapshot } from '../types/hilosLogsOverview'

export const useHilosLogsStore = defineStore('hilosLogs', {
  state: () => ({
    hilosLogsOverview: null as HilosLogsOverviewSnapshot | null,
  }),

  actions: {
    setHilosLogsOverview(snapshot: HilosLogsOverviewSnapshot | null) {
      this.hilosLogsOverview = snapshot
    },
  },
})
