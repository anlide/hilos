<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h5 class="mb-0">MCP usage — last calendar week (stub)</h5>
          <router-link
            class="btn btn-sm btn-primary"
            :to="{ path: `/hilos/mcp-skills/${mcpId}/logs/view`, query: { week: '1' } }"
          >
            View logs
          </router-link>
        </div>
        <div class="card-body">
          <p class="text-body-secondary small mb-3">
            Stub counts of MCP invocations by principal for MCP <strong>#{{ mcpId }}</strong>.
          </p>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Principal</th>
                  <th scope="col" class="text-end">Calls (stub)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in usageStub" :key="u.who">
                  <td>{{ u.who }}</td>
                  <td class="text-end">{{ u.calls }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const mcpId = computed(() => (typeof route.params.mcpId === 'string' ? route.params.mcpId : ''))

const usageStub = [
  { who: 'user:alice', calls: 42 },
  { who: 'admin:bob', calls: 18 },
  { who: 'agent:ModeratorAgent', calls: 7 },
]

useHead({
  title: 'MCP logs overview | Hilos | Chat Demo',
})
</script>
