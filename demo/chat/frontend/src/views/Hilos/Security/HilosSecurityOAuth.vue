<template>
  <DaemonSectionShell title="Security — OAuth login providers">
    <p class="text-body-secondary small mb-3">
      External identity providers for user sign-in (stub).
    </p>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead>
          <tr>
            <th scope="col">Provider</th>
            <th scope="col">Enabled</th>
            <th scope="col">Allow login</th>
            <th scope="col" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in hilosSecurityOAuthProvidersStub" :key="row.providerId">
            <td class="fw-semibold">{{ row.label }}</td>
            <td>{{ row.enabled ? 'Yes' : 'No' }}</td>
            <td>{{ row.allowLogin ? 'Yes' : 'No' }}</td>
            <td class="text-end">
              <router-link
                class="btn btn-sm btn-outline-primary"
                :to="`/hilos/security/oauth/${row.providerId}`"
              >
                Configure
              </router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="mt-3">
      <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/security">Security overview</router-link>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosSecurityOAuthProvidersStub } from '@/constants/hilosSecurityStubs'

useHead({
  title: 'OAuth providers | Security | Hilos | Chat Demo',
})
</script>
