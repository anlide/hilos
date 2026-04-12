<template>
  <LogViewerSectionShell title="Logs — Rotations">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Rotation</th>
            <th>At</th>
            <th>Size (stub)</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.rotationId">
            <td><code>#{{ row.rotationId }}</code></td>
            <td>{{ row.at }}</td>
            <td>{{ formatBytes(row.totalBytes) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </LogViewerSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import LogViewerSectionShell from '@hilos/sdk/views/Hilos/LogViewer/LogViewerSectionShell.vue'
import { hilosLogsRotationsStub as rows } from '@/constants/hilosLogsStubs'

const formatBytes = (n: number): string => `${(n / 1_048_576).toFixed(2)} MiB`

useHead({
  title: 'Log rotations | Hilos | Chat Demo',
})
</script>
