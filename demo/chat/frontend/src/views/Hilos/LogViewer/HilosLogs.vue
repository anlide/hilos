<template>
  <LogViewerSectionShell title="Logs">
    <div class="alert alert-warning mb-3" role="status">
      <strong>Automatic log cleanup</strong> is not implemented in this build.
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Last rotation</h6>
          <p class="mb-0">{{ overview.lastRotationAt }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Rotations (all time)</h6>
          <p class="mb-0">{{ overview.totalRotationsAllTime }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Keys per agent (stub)</h6>
          <p class="mb-1">Distinct keys: {{ overview.logKeysPerAgent }}</p>
          <p class="mb-0 text-body-secondary small">Total weight: {{ formatBytes(overview.totalWeightAgentKeysBytes) }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Keys per worker (stub)</h6>
          <p class="mb-1">Distinct keys: {{ overview.logKeysPerWorker }}</p>
          <p class="mb-0 text-body-secondary small">Total weight: {{ formatBytes(overview.totalWeightWorkerKeysBytes) }}</p>
        </div>
      </div>
    </div>

    <h6 class="text-body-secondary">More</h6>
    <div class="list-group">
      <router-link class="list-group-item list-group-item-action" to="/hilos/logs/keys">By log key</router-link>
      <router-link class="list-group-item list-group-item-action" to="/hilos/logs/workers">By worker</router-link>
      <router-link class="list-group-item list-group-item-action" to="/hilos/logs/rotations">Rotation history</router-link>
      <router-link class="list-group-item list-group-item-action" to="/hilos/logs/view">Viewer</router-link>
    </div>
  </LogViewerSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import LogViewerSectionShell from '@/views/Hilos/LogViewer/LogViewerSectionShell.vue'
import { hilosLogsOverviewStub as overview } from '@/constants/hilosLogsStubs'

const formatBytes = (n: number): string => {
  if (n >= 1_048_576) {
    return `${(n / 1_048_576).toFixed(1)} MiB`
  }
  if (n >= 1024) {
    return `${(n / 1024).toFixed(1)} KiB`
  }
  return `${n} B`
}

useHead({
  title: 'Logs | Hilos | Chat Demo',
})
</script>
