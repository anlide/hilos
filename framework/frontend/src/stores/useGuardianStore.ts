import { defineStore } from 'pinia'

const guardianActionUnlockTimers: Record<string, ReturnType<typeof setTimeout> | undefined> = {}

export const useGuardianStore = defineStore('guardian', {
  state: () => ({
    guardianAgentPendingById: {} as Record<string, boolean>,
    guardianAgentCooldownById: {} as Record<string, boolean>,
  }),

  getters: {
    isGuardianAgentActionPending(): (agentId: string) => boolean {
      return (agentId: string) => this.guardianAgentPendingById[agentId] === true
    },
    isGuardianAgentActionBlocked(): (agentId: string) => boolean {
      return (agentId: string) =>
        this.guardianAgentPendingById[agentId] === true || this.guardianAgentCooldownById[agentId] === true
    },
  },

  actions: {
    setGuardianAgentActionPending(agentId: string) {
      this.guardianAgentPendingById = {
        ...this.guardianAgentPendingById,
        [agentId]: true,
      }
    },

    settleGuardianAgentAction(agentId: string) {
      if (this.guardianAgentPendingById[agentId] !== true) {
        return
      }
      this.guardianAgentPendingById = {
        ...this.guardianAgentPendingById,
        [agentId]: false,
      }

      this.guardianAgentCooldownById = {
        ...this.guardianAgentCooldownById,
        [agentId]: true,
      }

      const existingTimer = guardianActionUnlockTimers[agentId]
      if (existingTimer) {
        clearTimeout(existingTimer)
      }

      guardianActionUnlockTimers[agentId] = setTimeout(() => {
        this.guardianAgentCooldownById = {
          ...this.guardianAgentCooldownById,
          [agentId]: false,
        }
        delete guardianActionUnlockTimers[agentId]
      }, 1000)
    },
  },
})
