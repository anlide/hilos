<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-9 mx-auto d-flex flex-column h-100 min-h-0 overflow-hidden">
      <div v-if="agent" class="card flex-grow-1 overflow-auto min-h-0">
        <div class="card-header">
          <h5 class="mb-0">Guardian Agent</h5>
        </div>
        <div class="card-body">
          <template v-if="agent.id === 'oss_budget_distribution'">
            <div class="d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3 mb-4">
              <div>
                <h4 class="mb-1">{{ agent.title }}</h4>
                <p class="mb-2 text-body-secondary">{{ agent.category }}</p>
                <code>{{ agent.id }}</code>
              </div>
              <GuardianAgentControls
                :status="agentStatus"
                :loading="agent ? guardianStore.isGuardianAgentActionPending(agent.id) : false"
                :disabled="!connectionStore.isConnected || (agent ? guardianStore.isGuardianAgentActionBlocked(agent.id) : true)"
                @start="handleStart"
                @stop="handleStop"
              />
            </div>
            <p class="text-body-secondary mb-4">
              Intelligence: recommends how to allocate an annual open-source sponsorship budget from dependency
              signals (stub).
            </p>
            <section class="mb-4">
              <h6 class="text-body-secondary text-uppercase small">Budget pool</h6>
              <div class="row g-2">
                <div class="col-md-4">
                  <div class="border rounded p-3">
                    <div class="small text-body-secondary">{{ hilosOssBudgetPoolStub.fiscalYearLabel }}</div>
                    <div class="fs-5">${{ hilosOssBudgetPoolStub.annualBudgetUsd.toLocaleString() }}</div>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="border rounded p-3">
                    <div class="small text-body-secondary">Spent (stub)</div>
                    <div class="fs-5">${{ hilosOssBudgetPoolStub.spentUsd.toLocaleString() }}</div>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="border rounded p-3">
                    <div class="small text-body-secondary">Remaining (stub)</div>
                    <div class="fs-5">${{ hilosOssBudgetPoolStub.remainingUsd.toLocaleString() }}</div>
                  </div>
                </div>
              </div>
            </section>
            <section class="mb-4">
              <h6 class="text-body-secondary text-uppercase small">Top allocations (stub)</h6>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Package</th>
                      <th scope="col" class="text-end">Score</th>
                      <th scope="col" class="text-end">Recommended USD</th>
                      <th scope="col">Rationale</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in hilosOssBudgetTopDependenciesStub" :key="row.packageName">
                      <td><code>{{ row.packageName }}</code></td>
                      <td class="text-end">{{ row.score }}</td>
                      <td class="text-end">${{ row.recommendedUsd.toLocaleString() }}</td>
                      <td class="small text-body-secondary">{{ row.rationale }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
            <section>
              <h6 class="text-body-secondary text-uppercase small">Scoring rules (stub)</h6>
              <ul class="mb-0">
                <li v-for="(rule, idx) in hilosOssBudgetRulesStub" :key="idx" class="mb-1">
                  {{ rule }}
                </li>
              </ul>
            </section>
          </template>
          <template v-else>
            <div class="d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3 mb-4">
              <div>
                <h4 class="mb-1">{{ agent.title }}</h4>
                <p class="mb-2 text-body-secondary">{{ agent.category }}</p>
                <code>{{ agent.id }}</code>
              </div>
              <GuardianAgentControls
                :status="agentStatus"
                :loading="agent ? guardianStore.isGuardianAgentActionPending(agent.id) : false"
                :disabled="!connectionStore.isConnected || (agent ? guardianStore.isGuardianAgentActionBlocked(agent.id) : true)"
                @start="handleStart"
                @stop="handleStop"
              />
            </div>
            <p class="text-body-secondary mb-0">
              This stub simulates one guardian agent run. Start triggers a 5-second execution that ends in
              either done or failed unless you stop it first.
            </p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useWebSocket } from '../../plugins/websocket'
import { useRoute, useRouter } from 'vue-router'
import GuardianAgentControls from '../../components/GuardianAgentControls.vue'
import { guardianAiAgentMap, isGuardianAiAgentId } from '../../constants/guardianAiAgents'
import { GUARDIAN_AGENT_RUN_START, GUARDIAN_AGENT_RUN_STOP } from '../../constants/hilosActions'
import {
  hilosOssBudgetPoolStub,
  hilosOssBudgetRulesStub,
  hilosOssBudgetTopDependenciesStub,
} from '../../constants/hilosGuardianStubs'
import { sendAction } from '../../services/websocketActions'
import { useConnectionStore, useGuardianStore } from '../../stores'
import type { GuardianRunStatus } from '../../types/guardianAgentRuns'

const route = useRoute()
const router = useRouter()
const websocket = useWebSocket()
const connectionStore = useConnectionStore()
const guardianStore = useGuardianStore()

const agentId = computed(() => {
  const value = route.params.agentId
  return typeof value === 'string' ? value : null
})

const agent = computed(() => {
  const value = agentId.value
  return value && isGuardianAiAgentId(value) ? guardianAiAgentMap[value] : null
})

const agentStatus = computed<GuardianRunStatus>(() => {
  const id = agent.value?.id
  return id ? guardianStore.guardianAgentStatuses[id] ?? 'not_started' : 'not_started'
})

watch(agentId, (value) => {
  if (!value || !isGuardianAiAgentId(value)) {
    void router.replace('/hilos/guardian')
  }
}, { immediate: true })

const handleStart = () => {
  if (!agent.value) {
    return
  }

  guardianStore.setGuardianAgentActionPending(agent.value.id)
  sendAction(websocket, GUARDIAN_AGENT_RUN_START, { agentId: agent.value.id })
}

const handleStop = () => {
  if (!agent.value) {
    return
  }

  guardianStore.setGuardianAgentActionPending(agent.value.id)
  sendAction(websocket, GUARDIAN_AGENT_RUN_STOP, { agentId: agent.value.id })
}

useHead(() => ({
  title: agent.value ? `${agent.value.title} | Guardian | Chat Hilos Demo` : 'Guardian | Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: agent.value
        ? `Guardian AI agent page for ${agent.value.title}.`
        : 'Guardian AI agent page.',
    },
  ],
}))
</script>
