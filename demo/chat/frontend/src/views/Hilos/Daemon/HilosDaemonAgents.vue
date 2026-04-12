<template>
  <DaemonSectionShell title="Daemon — Agents">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Agent</th>
            <th>Worker</th>
            <th>Name</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in agents" :key="a.agentId">
            <td><code>{{ a.agentId }}</code></td>
            <td><code>{{ a.workerId }}</code></td>
            <td>{{ a.name }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosDaemonAgentsStub as agents } from '@/constants/hilosDaemonStubs'

useHead({
  title: 'Agents | Daemon | Hilos | Chat Demo',
})
</script>
