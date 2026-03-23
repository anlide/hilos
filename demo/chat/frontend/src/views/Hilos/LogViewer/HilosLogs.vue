<template>
  <LogViewerSectionShell title="Logs">
    <div class="alert alert-warning mb-3" role="status">
      <strong>Automatic log cleanup</strong> is not implemented in this build.
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Last rotation</h6>
          <div v-if="isOverviewLoading" class="placeholder-glow" aria-hidden="true">
            <p class="mb-0"><span class="placeholder col-8"></span></p>
          </div>
          <p v-else class="mb-0">{{ lastRotationDisplay }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Rotations (all time)</h6>
          <div v-if="isOverviewLoading" class="placeholder-glow" aria-hidden="true">
            <p class="mb-0"><span class="placeholder col-4"></span></p>
          </div>
          <p v-else class="mb-0">{{ rotationsDisplay }}</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Keys per agent</h6>
          <div v-if="isOverviewLoading" class="placeholder-glow" aria-hidden="true">
            <p class="mb-1"><span class="placeholder col-5"></span></p>
            <p class="mb-0 text-body-secondary small"><span class="placeholder col-5"></span></p>
          </div>
          <template v-else>
            <p class="mb-1">Distinct keys: {{ keysPerAgentDisplay }}</p>
            <p class="mb-0 text-body-secondary small">Total weight: {{ weightAgentDisplay }}</p>
          </template>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <h6 class="text-body-secondary">Keys per worker</h6>
          <div v-if="isOverviewLoading" class="placeholder-glow" aria-hidden="true">
            <p class="mb-1"><span class="placeholder col-5"></span></p>
            <p class="mb-0 text-body-secondary small"><span class="placeholder col-5"></span></p>
          </div>
          <template v-else>
            <p class="mb-1">Distinct keys: {{ keysPerWorkerDisplay }}</p>
            <p class="mb-0 text-body-secondary small">Total weight: {{ weightWorkerDisplay }}</p>
          </template>
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
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useHead } from '@unhead/vue'
import LogViewerSectionShell from '@/views/Hilos/LogViewer/LogViewerSectionShell.vue'
import { useChatStore } from '@/stores'

const chatStore = useChatStore()
const { hilosLogsOverview } = storeToRefs(chatStore)

/** True until the first WebSocket payload for this page (subscription_page_hilos_logs). */
const isOverviewLoading = computed(() => hilosLogsOverview.value === null)

const lastRotationDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (!s.lastRotationAt) {
    return 'Never'
  }
  const d = new Date(s.lastRotationAt)
  if (Number.isNaN(d.getTime())) {
    return s.lastRotationAt
  }
  return d.toLocaleString()
})

const rotationsDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (s.totalRotationsAllTime === null) {
    return '—'
  }
  return String(s.totalRotationsAllTime)
})

const keysPerAgentDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (s.logKeysPerAgent === null) {
    return '—'
  }
  return String(s.logKeysPerAgent)
})

const keysPerWorkerDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (s.logKeysPerWorker === null) {
    return '—'
  }
  return String(s.logKeysPerWorker)
})

const weightAgentDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (s.totalWeightAgentKeysBytes === null) {
    return '—'
  }
  return formatBytes(s.totalWeightAgentKeysBytes)
})

const weightWorkerDisplay = computed(() => {
  const s = hilosLogsOverview.value
  if (s === null) {
    return ''
  }
  if (!s.available) {
    return 'Unavailable'
  }
  if (s.totalWeightWorkerKeysBytes === null) {
    return '—'
  }
  return formatBytes(s.totalWeightWorkerKeysBytes)
})

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
