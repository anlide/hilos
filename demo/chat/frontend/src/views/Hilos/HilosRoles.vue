<template>
  <DaemonSectionShell title="Roles">
    <p class="text-body-secondary mb-3">Stub role catalog (no RBAC wiring in this demo).</p>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Role</th>
            <th>Id</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in hilosRolesStub" :key="r.roleId">
            <td class="fw-semibold">{{ r.name }}</td>
            <td><code>{{ r.roleId }}</code></td>
            <td class="text-body-secondary">{{ r.description }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosRolesStub } from '@/constants/hilosAccessStubs'

useHead({
  title: 'Roles | Hilos | Chat Demo',
})
</script>
