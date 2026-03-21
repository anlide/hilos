<template>
  <div class="row">
    <div class="col-12 col-lg-9 mx-auto">
      <div class="card" v-if="agent">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Guardian Agent</h5>
          <router-link to="/hilos/guardian" class="btn btn-sm btn-outline-secondary">Back to Guardian</router-link>
        </div>
        <div class="card-body">
          <div class="d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3 mb-4">
            <div>
              <h4 class="mb-1">{{ agent.title }}</h4>
              <p class="mb-2 text-body-secondary">{{ agent.category }}</p>
              <code>{{ agent.id }}</code>
            </div>
            <GuardianAgentControls :running="false" />
          </div>
          <p class="text-body-secondary mb-0">
            Controls are disabled for now. This page is ready for per-agent actions and live status wiring.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import GuardianAgentControls from '@/components/GuardianAgentControls.vue'
import { guardianAiAgentMap, isGuardianAiAgentId } from '@/constants/guardianAiAgents'

const route = useRoute()
const router = useRouter()

const agentId = computed(() => {
  const value = route.params.agentId
  return typeof value === 'string' ? value : null
})

const agent = computed(() => {
  const value = agentId.value
  return value && isGuardianAiAgentId(value) ? guardianAiAgentMap[value] : null
})

watch(agentId, (value) => {
  if (!value || !isGuardianAiAgentId(value)) {
    void router.replace('/hilos/guardian')
  }
}, { immediate: true })

useHead(() => ({
  title: agent.value ? `${agent.value.title} | Guardian | Demo WebSocket Chat` : 'Guardian | Demo WebSocket Chat',
  meta: [
    {
      name: 'description',
      content: agent.value
        ? `Guardian AI agent page for ${agent.value.title}.`
        : 'Guardian AI agent page.'
    }
  ]
}))
</script>
