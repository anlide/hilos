<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Users</h5>
        </div>
        <div class="card-body">
          <Table
            :items="users"
            item-key="id"
            :colspan="5"
            :searchable="true"
            search-placeholder="Search users..."
            :search-fields="['id', 'name']"
            :sortable="true"
            :sortable-fields="['id', 'name', 'lastActivity']"
            :paginated="true"
            :show-actions="true"
            :show-add-button="false"
            :show-edit-button="true"
            :show-delete-button="false"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @edit="handleEdit"
            @update-snapshot="updateSnapshot"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>
                <button
                  v-if="isFieldSortable('id')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('id')"
                >
                  ID
                  <span v-if="sort.field === 'id'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>ID</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('name')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('name')"
                >
                  Name
                  <span v-if="sort.field === 'name'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Name</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('lastActivity')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('lastActivity')"
                >
                  Last Activity
                  <span v-if="sort.field === 'lastActivity'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Last Activity</span>
              </th>
              <th>
                Presence
              </th>
              <th>Actions</th>
            </template>
            <template #row="{ item, handleEdit, showEditButton, changeType }">
              <td>
                <span v-if="changeType === 'added'" class="badge bg-success me-1">+</span>
                <span v-else-if="changeType === 'updated'" class="badge bg-warning me-1">~</span>
                <span v-else-if="changeType === 'deleted'" class="badge bg-danger me-1">-</span>
                {{ item.id }}
              </td>
              <td>{{ item.name }}</td>
              <td>{{ formatDate(item.lastActivity) }}</td>
              <td>
                <span class="badge" :class="getPresenceBadgeClass(item.presence)">
                  {{ item.presence || 'offline' }}
                </span>
              </td>
              <td>
                <div v-if="showEditButton" class="d-flex gap-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    @click="handleEdit"
                  >
                    Edit
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No users found</p>
            </template>
          </Table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Table } from '@hilos/sdk/components'

// Snapshot data - represents table state at a specific point in time
const snapshotUsers = ref([
  {
    id: 1,
    name: 'User 1',
    lastActivity: '2025-01-29T10:00:00Z',
    presence: 'online'
  },
  {
    id: 2,
    name: 'User 2',
    lastActivity: '2025-01-29T09:30:00Z',
    presence: 'unstable'
  },
  {
    id: 3,
    name: 'User 3',
    lastActivity: '2025-01-29T08:00:00Z',
    presence: 'offline'
  }
])

// Current displayed data (snapshot + pending changes)
const users = ref([...snapshotUsers.value])

// Pending changes tracking
const pendingChanges = ref({
  added: 0,
  updated: 0,
  deleted: 0
})

const changeMarkers = ref({
  added: [] as number[],
  updated: [] as number[],
  deleted: [] as number[]
})

// TEMPORARY: WebSocket emulation for debugging
// TODO: Replace with real WebSocket implementation
let emulationTimeout: ReturnType<typeof setTimeout> | null = null

const emulateWebSocketEvents = () => {
  // Simulate: User 2 updated, User 4 added, User 3 marked for deletion
  setTimeout(() => {
    // Update existing user
    const user2Index = users.value.findIndex(u => u.id === 2)
    if (user2Index !== -1) {
      users.value[user2Index] = {
        ...users.value[user2Index],
        name: 'User 2 (Updated)',
        presence: 'online'
      }
      changeMarkers.value.updated.push(2)
      pendingChanges.value.updated++
    }

    // Add new user
    const newUser = {
      id: 4,
      name: 'User 4 (New)',
      lastActivity: new Date().toISOString(),
      presence: 'online'
    }
    users.value.push(newUser)
    changeMarkers.value.added.push(4)
    pendingChanges.value.added++

    // Mark for deletion
    changeMarkers.value.deleted.push(3)
    pendingChanges.value.deleted++
  }, 5000)
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  snapshotUsers.value = users.value.filter(u => !changeMarkers.value.deleted.includes(u.id))
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  
  // Update displayed data
  users.value = [...snapshotUsers.value]
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation after 5 seconds
  emulationTimeout = setTimeout(emulateWebSocketEvents, 5000)
})

onUnmounted(() => {
  if (emulationTimeout) {
    clearTimeout(emulationTimeout)
  }
})

const formatDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return 'Never'
  try {
    const date = new Date(dateStr)
    return date.toLocaleString()
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

const handleEdit = (item: unknown) => {
  console.log('Edit user clicked', item)
  // TODO: Implement edit user logic
}
</script>
