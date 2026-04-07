<template>
  <DaemonSectionShell title="Daemon — Workers">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Worker</th>
            <th>Exclusive</th>
            <th>Agents</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="w in workers" :key="w.workerId">
            <td><code>{{ w.workerId }}</code></td>
            <td>{{ w.exclusive ? 'Yes' : 'No' }}</td>
            <td>{{ w.agentCount }}</td>
            <td class="text-body-secondary">{{ w.label }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from './DaemonSectionShell.vue'
import { hilosDaemonWorkersStub as workers } from '../../../constants/hilosDaemonStubs'

useHead({
  title: 'Workers | Daemon | Hilos | Chat Demo',
})
</script>
