<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Moderator</h5>
        </div>
        <div class="card-body">
          <Table
            :items="moderatorData"
            item-key="id"
            :colspan="4"
            :searchable="true"
            search-placeholder="Search moderator data..."
            :search-fields="['userId', 'username', 'action']"
            :sortable="true"
            :sortable-fields="['timestamp', 'action']"
            :paginated="false"
            :show-actions="false"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @update-snapshot="updateSnapshot"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>User ID</th>
              <th>Username</th>
              <th>
                <button
                  v-if="isFieldSortable('timestamp')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('timestamp')"
                >
                  Timestamp
                  <span v-if="sort.field === 'timestamp'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Timestamp</span>
              </th>
              <th>
                <button
                  v-if="isFieldSortable('action')"
                  class="btn btn-link p-0 text-decoration-none text-dark fw-bold"
                  @click="handleSort('action')"
                >
                  Action
                  <span v-if="sort.field === 'action'">
                    {{ sort.direction === 'asc' ? '↑' : '↓' }}
                  </span>
                </button>
                <span v-else>Action</span>
              </th>
            </template>
            <template #row="{ item, changeType }">
              <td>
                <span v-if="changeType === 'added'" class="badge bg-success me-1">+</span>
                <span v-else-if="changeType === 'updated'" class="badge bg-warning me-1">~</span>
                <span v-else-if="changeType === 'deleted'" class="badge bg-danger me-1">-</span>
                {{ item.userId }}
              </td>
              <td>{{ item.username }}</td>
              <td>{{ formatDate(item.timestamp) }}</td>
              <td>
                <span class="badge" :class="getActionBadgeClass(item.action)">
                  {{ item.action }}
                </span>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No moderator actions found</p>
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
const snapshotModeratorData = ref([
  {
    id: 1,
    userId: 1,
    username: 'User 1',
    timestamp: '2025-01-29T10:00:00Z',
    action: 'approved'
  },
  {
    id: 2,
    userId: 2,
    username: 'User 2',
    timestamp: '2025-01-29T09:30:00Z',
    action: 'rejected'
  },
  {
    id: 3,
    userId: 3,
    username: 'User 3',
    timestamp: '2025-01-29T08:00:00Z',
    action: 'flagged'
  },
  {
    id: 4,
    userId: 1,
    username: 'User 1',
    timestamp: '2025-01-29T07:00:00Z',
    action: 'approved'
  },
  {
    id: 5,
    userId: 4,
    username: 'User 4',
    timestamp: '2025-01-29T06:00:00Z',
    action: 'approved'
  }
])

// Current displayed data (snapshot + pending changes)
const moderatorData = ref([...snapshotModeratorData.value])

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
let emulationTimeouts: ReturnType<typeof setTimeout>[] = []

const emulateWebSocketEvents = () => {
  // Simulate multiple events at different times
  emulationTimeouts.push(
    setTimeout(() => {
      // Add new moderator action
      const newAction = {
        id: 6,
        userId: 5,
        username: 'User 5',
        timestamp: new Date().toISOString(),
        action: 'flagged'
      }
      moderatorData.value.push(newAction)
      changeMarkers.value.added.push(6)
      pendingChanges.value.added++
    }, 3000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Update existing action
      const actionIndex = moderatorData.value.findIndex(a => a.id === 2)
      if (actionIndex !== -1) {
        moderatorData.value[actionIndex] = {
          ...moderatorData.value[actionIndex],
          action: 'approved'
        }
        changeMarkers.value.updated.push(2)
        pendingChanges.value.updated++
      }
    }, 5000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Mark for deletion
      changeMarkers.value.deleted.push(5)
      pendingChanges.value.deleted++
    }, 10000)
  )
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  snapshotModeratorData.value = moderatorData.value.filter(a => !changeMarkers.value.deleted.includes(a.id))
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  
  // Update displayed data
  moderatorData.value = [...snapshotModeratorData.value]
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation
  emulateWebSocketEvents()
})

onUnmounted(() => {
  emulationTimeouts.forEach(timeout => clearTimeout(timeout))
  emulationTimeouts = []
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

const getActionBadgeClass = (action: string | null | undefined): string => {
  switch (action) {
    case 'approved':
      return 'bg-success'
    case 'rejected':
      return 'bg-danger'
    case 'flagged':
      return 'bg-warning'
    default:
      return 'bg-secondary'
  }
}
</script>
