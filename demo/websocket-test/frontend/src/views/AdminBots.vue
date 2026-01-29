<template>
  <div class="row">
    <div class="col-12 col-lg-10 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin Bots</h5>
        </div>
        <div class="card-body">
          <Table
            :items="bots"
            item-key="id"
            :colspan="5"
            :searchable="true"
            search-placeholder="Search bots..."
            :search-fields="['id', 'name', 'type']"
            :sortable="true"
            :sortable-fields="['id', 'name']"
            :paginated="true"
            :fixed-items-per-page="25"
            :show-actions="true"
            :show-add-button="true"
            :show-edit-button="true"
            :show-delete-button="true"
            :pending-changes="pendingChanges"
            :change-markers="changeMarkers"
            @add="handleAdd"
            @edit="handleEdit"
            @delete="handleDelete"
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
              <th>Type</th>
              <th>Status</th>
              <th>Actions</th>
            </template>
            <template #row="{ item, handleEdit, handleDelete, showEditButton, showDeleteButton, changeType }">
              <td>
                <span v-if="changeType === 'added'" class="badge bg-success me-1">+</span>
                <span v-else-if="changeType === 'updated'" class="badge bg-warning me-1">~</span>
                <span v-else-if="changeType === 'deleted'" class="badge bg-danger me-1">-</span>
                {{ item.id }}
              </td>
              <td>{{ item.name }}</td>
              <td>
                <span class="badge bg-info">{{ item.type }}</span>
              </td>
              <td>
                <span class="badge" :class="getStatusBadgeClass(item.status)">
                  {{ item.status }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-1">
                  <button
                    v-if="showEditButton"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    @click="handleEdit"
                  >
                    Edit
                  </button>
                  <button
                    v-if="showDeleteButton"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    @click="handleDelete"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </template>
            <template #empty>
              <p class="mb-0">No bots found</p>
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
// Generate 30 bots to test pagination with fixed 25 per page
const snapshotBots = ref(
  Array.from({ length: 30 }, (_, i) => ({
    id: i + 1,
    name: `Bot ${i + 1}`,
    type: i % 3 === 0 ? 'AI Assistant' : i % 3 === 1 ? 'Chat Bot' : 'Helper Bot',
    status: i % 4 === 0 ? 'active' : i % 4 === 1 ? 'inactive' : i % 4 === 2 ? 'maintenance' : 'error'
  }))
)

// Current displayed data (snapshot + pending changes)
const bots = ref([...snapshotBots.value])

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
      // Add new bot
      const newBot = {
        id: 31,
        name: 'Bot 31 (New)',
        type: 'AI Assistant',
        status: 'active'
      }
      bots.value.push(newBot)
      changeMarkers.value.added.push(31)
      pendingChanges.value.added++
    }, 3000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Update existing bot
      const botIndex = bots.value.findIndex(b => b.id === 5)
      if (botIndex !== -1) {
        bots.value[botIndex] = {
          ...bots.value[botIndex],
          name: 'Bot 5 (Updated)',
          status: 'maintenance'
        }
        changeMarkers.value.updated.push(5)
        pendingChanges.value.updated++
      }
    }, 5000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Mark for deletion
      changeMarkers.value.deleted.push(10)
      pendingChanges.value.deleted++
    }, 8000)
  )

  emulationTimeouts.push(
    setTimeout(() => {
      // Another update
      const botIndex = bots.value.findIndex(b => b.id === 15)
      if (botIndex !== -1) {
        bots.value[botIndex] = {
          ...bots.value[botIndex],
          status: 'active'
        }
        if (!changeMarkers.value.updated.includes(15)) {
          changeMarkers.value.updated.push(15)
          pendingChanges.value.updated++
        }
      }
    }, 10000)
  )
}

const updateSnapshot = () => {
  // Apply all pending changes to snapshot
  snapshotBots.value = bots.value.filter(b => !changeMarkers.value.deleted.includes(b.id))
  
  // Reset pending changes
  pendingChanges.value = { added: 0, updated: 0, deleted: 0 }
  changeMarkers.value = { added: [], updated: [], deleted: [] }
  
  // Update displayed data
  bots.value = [...snapshotBots.value]
}

onMounted(() => {
  // TEMPORARY: Start WebSocket emulation
  emulateWebSocketEvents()
})

onUnmounted(() => {
  emulationTimeouts.forEach(timeout => clearTimeout(timeout))
  emulationTimeouts = []
})

const getStatusBadgeClass = (status: string | null | undefined): string => {
  switch (status) {
    case 'active':
      return 'bg-success'
    case 'inactive':
      return 'bg-secondary'
    case 'maintenance':
      return 'bg-warning'
    case 'error':
      return 'bg-danger'
    default:
      return 'bg-secondary'
  }
}

const handleAdd = () => {
  console.log('Add bot clicked')
  // TODO: Implement add bot logic
}

const handleEdit = (item: unknown) => {
  console.log('Edit bot clicked', item)
  // TODO: Implement edit bot logic
}

const handleDelete = (item: unknown) => {
  console.log('Delete bot clicked', item)
  // TODO: Implement delete bot logic
}
</script>
