<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">MCP and Skills</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary small mb-3">
            Stub catalog of MCP servers and skills registered for this project (demo).
          </p>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">MCP</th>
                  <th scope="col">Skills</th>
                  <th scope="col" class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in hilosMcpSkillsDashboardStub" :key="row.mcpId">
                  <td>
                    <router-link :to="`/hilos/mcp-skills/${row.mcpId}`">{{ row.mcpName }}</router-link>
                    <span class="text-body-secondary small ms-1">(#{{ row.mcpId }})</span>
                  </td>
                  <td class="small">{{ row.skills.join(', ') }}</td>
                  <td class="text-end text-nowrap">
                    <router-link
                      class="btn btn-sm btn-outline-secondary me-1"
                      :to="`/hilos/mcp-skills/${row.mcpId}/logs`"
                    >
                      Usage
                    </router-link>
                    <router-link
                      class="btn btn-sm btn-outline-primary"
                      :to="`/hilos/mcp-skills/${row.mcpId}/logs/view`"
                    >
                      Logs
                    </router-link>
                  </td>
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
import { useHead } from '@unhead/vue'
import { hilosMcpSkillsDashboardStub } from '../../../constants/hilosMcpSkillsStubs'

useHead({
  title: 'MCP and Skills | Hilos | Chat Demo',
})
</script>
