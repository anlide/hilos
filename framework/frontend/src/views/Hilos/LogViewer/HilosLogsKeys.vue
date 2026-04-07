<template>
  <LogViewerSectionShell title="Logs — By key">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Key</th>
            <th>Weight</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.key">
            <td><code>{{ row.key }}</code></td>
            <td>{{ formatBytes(row.weightBytes) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </LogViewerSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import LogViewerSectionShell from './LogViewerSectionShell.vue'
import { hilosLogsByKeyStub as rows } from '../../../constants/hilosLogsStubs'

const formatBytes = (n: number): string => `${(n / 1024).toFixed(1)} KiB`

useHead({
  title: 'Logs by key | Hilos | Chat Demo',
})
</script>
