<template>
  <DaemonSectionShell :title="`HTTP server — ${serverLabel}`">
    <p class="text-body-secondary mb-2">
      Stub detail for server <code>{{ serverId }}</code>.
    </p>
    <ul class="list-unstyled mb-0">
      <li><strong>Requests (last hour, stub):</strong> {{ requests }}</li>
      <li><strong>Listen (stub):</strong> 0.0.0.0:{{ serverId === 'ws' ? '8081' : '8080' }}</li>
    </ul>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute } from 'vue-router'
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosDaemonStub } from '@/constants/hilosDaemonStubs'

const route = useRoute()
const serverId = computed(() => (typeof route.params.serverId === 'string' ? route.params.serverId : ''))

const serverMeta = computed(() =>
  hilosDaemonStub.httpServers.find((s) => s.serverId === serverId.value),
)

const serverLabel = computed(() => serverMeta.value?.label ?? serverId.value)
const requests = computed(() => serverMeta.value?.requestsLastHour ?? '—')

useHead(() => ({
  title: `HTTP ${serverId.value || 'server'} | Daemon | Hilos | Chat Demo`,
}))
</script>
