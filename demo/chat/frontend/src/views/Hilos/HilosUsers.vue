<template>
  <DaemonSectionShell title="Users">
    <p class="text-body-secondary mb-3">Stub list. Open a user to view or edit (client-only demo).</p>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>User</th>
            <th>Email</th>
            <th>Role</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in hilosUsersStub" :key="u.userId">
            <td>{{ u.name }}</td>
            <td><code>{{ u.email }}</code></td>
            <td>{{ u.roleLabel }}</td>
            <td class="text-end">
              <router-link
                class="btn btn-sm btn-outline-primary"
                :to="`/hilos/users/${encodeURIComponent(u.userId)}`"
              >
                Open
              </router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosUsersStub } from '@/constants/hilosAccessStubs'

useHead({
  title: 'Users | Hilos | Chat Demo',
})
</script>
