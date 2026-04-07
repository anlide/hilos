<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">System Intelligence Layer</h5>
        </div>
        <div class="card-body">
          <section class="mb-4">
            <h6 class="text-body-secondary text-uppercase small">Requests by role (last calendar week, stub)</h6>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="border rounded p-3">
                  <div class="small text-body-secondary">Users</div>
                  <div class="fs-4">128</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="border rounded p-3">
                  <div class="small text-body-secondary">Admins</div>
                  <div class="fs-4">34</div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="border rounded p-3">
                  <div class="small text-body-secondary">Developers</div>
                  <div class="fs-4">56</div>
                </div>
              </div>
            </div>
          </section>

          <section class="mb-4">
            <h6 class="text-body-secondary text-uppercase small">Top MCP intensity (stub)</h6>
            <ol class="mb-0">
              <li v-for="t in topMcp" :key="t.mcpId" class="mb-1">
                <router-link
                  :to="{
                    path: `/hilos/mcp-skills/${t.mcpId}/logs/view`,
                    query: { preset: 'sil_top5', week: '1' },
                  }"
                >
                  {{ t.label }}
                </router-link>
                <span class="text-body-secondary small"> — {{ t.calls }} calls (stub)</span>
              </li>
            </ol>
          </section>

          <section>
            <h6 class="text-body-secondary text-uppercase small">Tokens and limits (stub)</h6>
            <p class="mb-1">
              <strong>Spent this week:</strong> 1,240,000 tokens (stub)
            </p>
            <p class="mb-0 text-body-secondary small">
              Soft limit: 2,000,000 / week · Hard limit: 5,000,000 / week (stub). SIL may exceed soft limits when
              explicitly approved (future).
            </p>
          </section>

          <div class="mt-4 d-flex flex-wrap gap-2">
            <router-link class="btn btn-sm btn-outline-primary" to="/hilos/sil/requests">All SIL requests</router-link>
            <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/mcp-skills">MCP and Skills</router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'

const topMcp = [
  { mcpId: '1', label: 'filesystem', calls: 920 },
  { mcpId: '2', label: 'git', calls: 410 },
  { mcpId: '3', label: 'web_fetch', calls: 305 },
]

useHead({
  title: 'System Intelligence Layer | Hilos | Chat Demo',
})
</script>
