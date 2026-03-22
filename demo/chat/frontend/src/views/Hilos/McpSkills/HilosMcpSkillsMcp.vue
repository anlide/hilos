<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h5 class="mb-0">MCP #{{ mcpId }}</h5>
          <div class="btn-group btn-group-sm">
            <router-link class="btn btn-outline-secondary" :to="`/hilos/mcp-skills/${mcpId}/logs`">
              Usage (week)
            </router-link>
            <router-link class="btn btn-outline-primary" :to="`/hilos/mcp-skills/${mcpId}/logs/view`">
              Log viewer
            </router-link>
          </div>
        </div>
        <div class="card-body">
          <p class="mb-2"><strong>Name (stub):</strong> {{ stubName }}</p>
          <p class="text-body-secondary small mb-3">
            Skills exposed by this MCP are listed below. This screen is a placeholder until the registry is wired to
            the backend.
          </p>
          <ul class="mb-0">
            <li v-for="s in stubSkills" :key="s">{{ s }}</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute } from 'vue-router'
import { hilosMcpSkillsDashboardStub } from '@/constants/hilosMcpSkillsStubs'

const route = useRoute()
const mcpId = computed(() => (typeof route.params.mcpId === 'string' ? route.params.mcpId : ''))

const row = computed(() => hilosMcpSkillsDashboardStub.find((r) => r.mcpId === mcpId.value))
const stubName = computed(() => row.value?.mcpName ?? 'unknown MCP')
const stubSkills = computed(() => row.value?.skills ?? ['(no stub data for this id)'])

useHead({
  title: 'MCP detail | Hilos | Chat Demo',
})
</script>
