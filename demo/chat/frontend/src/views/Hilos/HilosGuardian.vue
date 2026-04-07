<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-xl-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Guardian</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">
            Guardian AI agents available for this project. Select an agent to open its dedicated page.
          </p>
          <div class="list-group">
            <router-link
              v-for="agent in guardianAiAgents"
              :key="agent.id"
              :to="`/hilos/guardian/${agent.id}`"
              class="list-group-item list-group-item-action text-reset text-decoration-none"
            >
              <div class="d-flex flex-column flex-xl-row align-items-start align-items-xl-center justify-content-between gap-3">
                <div class="d-flex flex-column gap-1">
                  <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-2">
                    <h6 class="mb-0">{{ agent.title }}</h6>
                    <span class="badge border text-body-secondary">{{ agent.category }}</span>
                  </div>
                  <div class="d-flex flex-column gap-1">
                    <p class="mb-0 text-body-secondary">Guardian AI agent</p>
                    <code>{{ agent.id }}</code>
                  </div>
                </div>
                <div class="w-100 w-xl-auto ms-xl-auto" @click.stop.prevent>
                  <GuardianAgentControls
                    :status="guardianStatusesById[agent.id] ?? 'not_started'"
                    :loading="guardianStore.isGuardianAgentActionPending(agent.id)"
                    :disabled="!connectionStore.isConnected || guardianStore.isGuardianAgentActionBlocked(agent.id)"
                    @start="handleStart(agent.id)"
                    @stop="handleStop(agent.id)"
                  />
                </div>
              </div>
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import GuardianAgentControls from '@/components/GuardianAgentControls.vue'
import { guardianAiAgentIds, guardianAiAgents } from '@/constants/guardianAiAgents'
import { GUARDIAN_AGENT_RUN_START, GUARDIAN_AGENT_RUN_STOP } from '@/constants'
import { sendAction } from '@/services/websocketActions'
import { useConnectionStore, useGuardianStore } from '@hilos/sdk/stores'
import type { GuardianRunStatus } from '@/types/guardianAgentRuns'

useHead({
  title: 'Guardian | Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: 'Guardian AI agents registered for the Hilos demo project.'
    }
  ]
})

const connectionStore = useConnectionStore()
const guardianStore = useGuardianStore()
const websocket = useWebSocket()

const guardianStatusesById = computed(() => {
  return guardianAiAgentIds.reduce<Record<string, GuardianRunStatus>>((acc, id) => {
    acc[id] = guardianStore.guardianAgentStatuses[id] ?? 'not_started'
    return acc
  }, {})
})

const handleStart = (agentId: string) => {
  guardianStore.setGuardianAgentActionPending(agentId)
  sendAction(websocket, GUARDIAN_AGENT_RUN_START, { agentId })
}

const handleStop = (agentId: string) => {
  guardianStore.setGuardianAgentActionPending(agentId)
  sendAction(websocket, GUARDIAN_AGENT_RUN_STOP, { agentId })
}
</script>
