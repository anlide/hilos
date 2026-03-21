<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-xl-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Guardian</h5>
          <router-link to="/hilos" class="btn btn-sm btn-outline-secondary">Back to Hilos</router-link>
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
                <div class="w-100 w-xl-auto ms-xl-auto">
                  <GuardianAgentControls :running="runningById[agent.id] ?? false" />
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
import GuardianAgentControls from '@/components/GuardianAgentControls.vue'
import { guardianAiAgentIds, guardianAiAgents } from '@/constants/guardianAiAgents'

useHead({
  title: 'Guardian | Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: 'Guardian AI agents registered for the Hilos demo project.'
    }
  ]
})

const runningById = computed(() => {
  return guardianAiAgentIds.reduce<Record<string, boolean>>((acc, id) => {
    acc[id] = false
    return acc
  }, {})
})
</script>
