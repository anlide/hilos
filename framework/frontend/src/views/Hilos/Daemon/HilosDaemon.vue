<template>
  <DaemonSectionShell title="Daemon">
    <p class="text-body-secondary">
      Stub dashboard. Metrics are fake; links go to detail pages.
    </p>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Process</h6>
          <p class="mb-1"><strong>Started:</strong> {{ stub.startedAt }}</p>
          <p class="mb-0"><strong>Build:</strong> <code>{{ stub.buildVersion }}</code></p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Non-exclusive workers</h6>
          <p class="mb-1">Workers: {{ stub.nonExclusiveWorkerCount }}</p>
          <p class="mb-2">Agents: {{ stub.nonExclusiveAgentCount }}</p>
          <router-link class="btn btn-sm btn-outline-primary me-1" to="/hilos/daemon/workers">Workers</router-link>
          <router-link class="btn btn-sm btn-outline-primary" to="/hilos/daemon/agents">Agents</router-link>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Exclusive workers</h6>
          <p class="mb-1">Workers: {{ stub.exclusiveWorkerCount }}</p>
          <p class="mb-2">Agents: {{ stub.exclusiveAgentCount }}</p>
          <router-link class="btn btn-sm btn-outline-secondary me-1" to="/hilos/daemon/workers">Workers</router-link>
          <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/daemon/agents">Agents</router-link>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Last backup</h6>
          <p class="mb-0">{{ stub.lastBackupAt }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Cron</h6>
          <p class="mb-2">Entries: {{ stub.cronEntryCount }}</p>
          <router-link class="btn btn-sm btn-outline-primary" to="/hilos/daemon/cron">Full list</router-link>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">WebSocket</h6>
          <p class="mb-2">Connections: {{ stub.websocketConnectionCount }}</p>
          <router-link class="btn btn-sm btn-outline-primary" to="/hilos/daemon/websockets">View list</router-link>
        </div>
      </div>
      <div class="col-12">
        <div class="border rounded p-3">
          <h6 class="text-body-secondary">HTTP servers (requests last hour)</h6>
          <ul class="list-group list-group-flush">
            <li
              v-for="s in stub.httpServers"
              :key="s.serverId"
              class="list-group-item d-flex justify-content-between align-items-center px-0"
            >
              <span>{{ s.label }} <code class="small">({{ s.serverId }})</code></span>
              <span>
                <span class="me-2">{{ s.requestsLastHour }} req</span>
                <router-link
                  class="btn btn-sm btn-outline-primary"
                  :to="`/hilos/daemon/http/${encodeURIComponent(s.serverId)}`"
                >
                  Open
                </router-link>
              </span>
            </li>
          </ul>
          <p class="small text-body-secondary mb-0 mt-2">
            Total: {{ httpRequestsSum }} requests / hour (stub)
          </p>
        </div>
      </div>
      <div class="col-12">
        <div class="alert alert-secondary mb-0" role="status">
          <strong>Cluster:</strong> {{ stub.clusterStateNote }}
        </div>
      </div>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from './DaemonSectionShell.vue'
import { hilosDaemonStub as stub } from '../../../constants/hilosDaemonStubs'

const httpRequestsSum = computed(() =>
  stub.httpServers.reduce((acc, s) => acc + s.requestsLastHour, 0),
)

useHead({
  title: 'Daemon | Hilos | Chat Demo',
})
</script>
