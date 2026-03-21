<template>
  <div class="row">
    <div class="col-12 col-xl-10 mx-auto">
      <div class="card">
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
  title: 'Guardian | Demo WebSocket Chat',
  meta: [
    {
      name: 'description',
      content: 'Guardian AI agents registered for the Hilos demo project.'
    }
  ]
})

const runningById = computed(() =>
  Object.fromEntries(guardianAiAgentIds.map((id) => [id, false]))
)
</script>
