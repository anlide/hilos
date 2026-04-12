<template>
  <DaemonSectionShell title="Daemon — Cron">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Schedule</th>
            <th>Handler</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cronRows" :key="c.id">
            <td><code>{{ c.id }}</code></td>
            <td><code>{{ c.schedule }}</code></td>
            <td>{{ c.handler }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosDaemonCronStub as cronRows } from '@/constants/hilosDaemonStubs'

useHead({
  title: 'Cron | Daemon | Hilos | Chat Demo',
})
</script>
