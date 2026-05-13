<template>
  <DaemonSectionShell title="Users">
    <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
      <p class="text-body-secondary mb-0 flex-grow-1">
        Same <code>user</code> records as the chat app (ID, name, activity, online sessions, presence). For table search and chat-focused admin tools, use
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
      <Table
        :items="users"
        item-key="id"
        :colspan="6"
        :searchable="true"
        search-placeholder="Search users..."
        :search-fields="['id', 'name']"
        :sortable="true"
        :sortable-fields="['id', 'name', 'lastActivity']"
      >
        <template #header="{ sort, handleSort, isFieldSortable }">
          <th scope="col">
            <button
              v-if="isFieldSortable('id')"
              class="btn btn-link p-0 text-decoration-none text-body fw-bold"
              @click="handleSort('id')"
            >
              ID
              <span v-if="sort.field === 'id'">
                {{ sort.direction === 'asc' ? '↑' : '↓' }}
              </span>
            </button>
            <span v-else>ID</span>
          </th>
          <th scope="col">
            <button
              v-if="isFieldSortable('name')"
              class="btn btn-link p-0 text-decoration-none text-body fw-bold"
              @click="handleSort('name')"
            >
              Name
              <span v-if="sort.field === 'name'">
                {{ sort.direction === 'asc' ? '↑' : '↓' }}
              </span>
            </button>
            <span v-else>Name</span>
          </th>
          <th scope="col">
            <button
              v-if="isFieldSortable('lastActivity')"
              class="btn btn-link p-0 text-decoration-none text-body fw-bold"
              @click="handleSort('lastActivity')"
            >
              Last activity
              <span v-if="sort.field === 'lastActivity'">
                {{ sort.direction === 'asc' ? '↑' : '↓' }}
              </span>
            </button>
            <span v-else>Last activity</span>
          </th>
          <th scope="col">Online sessions</th>
          <th scope="col">Presence</th>
          <th scope="col" class="text-end">Actions</th>
        </template>
        <template #row="row">
          <td><code>{{ row.item.id }}</code></td>
          <td>{{ row.item.name }}</td>
          <td class="small">{{ formatDate(row.item.lastActivity) }}</td>
          <td>{{ row.item.onlineSessionCount ?? 0 }}</td>
          <td>
            <span class="badge" :class="getPresenceBadgeClass(row.item.presence)">
              {{ row.item.presence || 'offline' }}
            </span>
          </td>
          <td class="text-end">
            <router-link
              class="btn btn-sm btn-outline-primary"
              :to="`/hilos/users/${encodeURIComponent(String(row.item.id))}`"
            >
              Open
            </router-link>
          </td>
        </template>
        <template #empty>
          <p class="mb-0">No users found.</p>
        </template>
      </Table>
    </template>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { Table } from '@hilos/sdk/components'
import { useBrowserStore, useConnectionStore } from '@hilos/sdk/stores'
import { userListRowsFromBrowserRows } from '@/entities/browserUserDetail'

const connectionStore = useConnectionStore()
const browserStore = useBrowserStore()
const browserPageKey = 'subscription_page_hilos_users'
const tableKey = 'hilosUsers'
const tableState = computed(() => browserStore.pages[browserPageKey]?.tables[tableKey] ?? null)
const users = computed(() => userListRowsFromBrowserRows(tableState.value?.rowsByKey))

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
