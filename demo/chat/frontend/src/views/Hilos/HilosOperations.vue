<template>
  <DaemonSectionShell title="Operations">
    <p class="text-body-secondary mb-3">
      Maintenance tasks for the site and build pipeline (stub — no server execution).
    </p>
    <ul class="list-group">
      <li
        v-for="op in hilosOperationsStub"
        :key="op.id"
        class="list-group-item d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"
      >
        <div>
          <div class="fw-semibold">{{ op.title }}</div>
          <div class="small text-body-secondary">{{ op.description }}</div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary align-self-md-end" disabled>
          Run
        </button>
      </li>
    </ul>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosOperationsStub } from '@/constants/hilosAccessStubs'

useHead({
  title: 'Operations | Hilos | Chat Demo',
})
</script>
