<template>
  <DaemonSectionShell title="Users">
    <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
      <p class="text-body-secondary mb-0 flex-grow-1">
        Same <code>user</code> records as the chat app (ID, name, activity, presence). For table search and chat-focused admin tools, use
        <router-link to="/hilos/admin_users">User management</router-link>.
      </p>
    </div>
    <p v-if="!connectionStore.isConnected" class="text-body-secondary mb-0">
      Connect to the server to load users.
    </p>
    <p v-else-if="!tableState" class="text-body-secondary mb-0">
      Loading users…
    </p>
    <template v-else>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">ID</th>
              <th scope="col">Name</th>
              <th scope="col">Last activity</th>
              <th scope="col">Presence</th>
              <th scope="col" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in rowsWithPresence" :key="u.id">
              <td><code>{{ u.id }}</code></td>
              <td>{{ u.name }}</td>
              <td class="small">{{ formatDate(u.lastActivity) }}</td>
              <td>
                <span class="badge" :class="getPresenceBadgeClass(u.presence)">
                  {{ u.presence || 'offline' }}
                </span>
              </td>
              <td class="text-end">
                <router-link
                  class="btn btn-sm btn-outline-primary"
                  :to="`/hilos/users/${encodeURIComponent(String(u.id))}`"
                >
                  Open
                </router-link>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="rowsWithPresence.length === 0" class="text-body-secondary small mb-0 mt-2">No users found.</p>
      </div>
    </template>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { getTableDisplayRows } from '@hilos/sdk/composables'
import { useConnectionStore, useTableStore } from '@hilos/sdk/stores'
import type { ChatUserTableRow } from '@hilos/sdk/types/chatUserTableRow'
import { useChatStore } from '@/stores'

const connectionStore = useConnectionStore()
const tableStore = useTableStore()
const chatStore = useChatStore()
const tableKey = 'hilosUsers'
const tableState = computed(() => tableStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<ChatUserTableRow>(tableState.value))

const rowsWithPresence = computed(() =>
  displayRows.value.map((row) => {
    const liveUser = chatStore.users.find((u) => u.id === row.id)
    const presence = liveUser?.presence ?? row.presence
    return { ...row, presence }
  }),
)

const formatDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return 'Never'
  try {
    return new Date(dateStr).toLocaleString()
  } catch {
    return dateStr
  }
}

const getPresenceBadgeClass = (presence: string | null | undefined): string => {
  switch (presence) {
    case 'online':
      return 'bg-success'
    case 'unstable':
      return 'bg-warning'
    case 'offline':
      return 'bg-secondary'
    default:
      return 'bg-secondary'
  }
}

useHead({
  title: 'Users | Hilos | Chat Demo',
})
</script>
