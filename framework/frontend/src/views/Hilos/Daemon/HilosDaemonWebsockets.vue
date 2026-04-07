<template>
  <DaemonSectionShell title="Daemon — WebSocket connections">
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Connection</th>
            <th>User</th>
            <th>Remote</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="w in items" :key="w.connectionId">
            <td><code>{{ w.connectionId }}</code></td>
            <td>{{ w.userId }}</td>
            <td><code>{{ w.remoteAddr }}</code></td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from './DaemonSectionShell.vue'
import { hilosDaemonWebsocketsStub as items } from '../../../constants/hilosDaemonStubs'

useHead({
  title: 'WebSockets | Daemon | Hilos | Chat Demo',
})
</script>
