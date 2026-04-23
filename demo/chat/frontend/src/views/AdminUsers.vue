<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <client-only>
      <div class="card d-flex flex-column flex-grow-1 min-h-0 overflow-hidden">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Admin Users</h5>
          <TableRefreshToolbarButton
            v-if="tableState"
            :loading="refreshLoading"
            @click="refreshTable"
          />
        </div>
        <div class="card-body overflow-auto">
          <Table
            :items="users"
            item-key="id"
            :colspan="5"
            :placeholder-when-empty="!connectionStore.isConnected"
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
            @update-snapshot="handleApplyChanges"
          >
            <template #header="{ sort, handleSort, isFieldSortable }">
              <th>
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
              <th>
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
              <th>
                <button
                  v-if="isFieldSortable('lastActivity')"
                  class="btn btn-link p-0 text-decoration-none text-body fw-bold"
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
            <template #row="row">
              <td>
                {{ row.item.id }}
              </td>
              <td>{{ row.item.name }}</td>
              <td>{{ formatDate(row.item.lastActivity) }}</td>
              <td>
                <span class="badge" :class="getPresenceBadgeClass(row.item.presence)">
                  {{ row.item.presence || 'offline' }}
                </span>
              </td>
              <td>
                <div v-if="row.showEditButton" class="d-flex gap-1">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit"
                    aria-label="Edit"
                    @click="row.handleEdit"
                  >
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    <span class="visually-hidden">Edit</span>
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

  <Modal
    v-model="showModal"
    :title="modalTitle"
    modal-name="admin-users-modal"
    modal-type="edit"
    :confirm-on-close="isFormDirty"
    @cancel="resetForm"
    @ok="saveUser"
  >
    <form @submit.prevent="saveUser">
      <div class="mb-3">
        <label class="form-label" for="user-name">Name</label>
        <input
          id="user-name"
          v-model="formUser.name"
          type="text"
          class="form-control"
          required
          minlength="2"
          maxlength="50"
          data-autofocus
        />
      </div>
      <div class="mb-0">
        <label class="form-label">Last Activity</label>
        <div class="form-control-plaintext">{{ formatDate(formUser.lastActivity) }}</div>
      </div>
    </form>
    <template #actions="{ requestClose }">
      <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
      <LoadingButton
        type="button"
        variant="btn-primary"
        :loading="saveLoading"
        :disabled="!isFormValid"
        :loading-delay="300"
        @click="saveUser"
      >
        Save
      </LoadingButton>
    </template>
  </Modal>
  <template #placeholder>
    <div class="card flex-grow-1 overflow-auto">
      <div class="card-header"><h5 class="mb-0">Admin Users</h5></div>
      <div class="card-body">
        <p class="mb-0 text-body-secondary">Access denied for guests.</p>
      </div>
    </div>
  </template>
  </client-only>
    </div>
  </div>
</template>

<!-- TODO: extract useTableCrud composable after conflict resolution feature is implemented -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { Table, Modal, LoadingButton } from '@hilos/sdk/components'
import { getTableDisplayRows, getTablePendingChanges, getTableChangeMarkers } from '@hilos/sdk/composables'
import { useConnectionStore, useTableStore } from '@hilos/sdk/stores'
import { useChatStore } from '@/stores'
import { sendAction } from '@/services/websocketActions'
import { useTableRefresh } from '@/composables/useTableRefresh'
import TableRefreshToolbarButton from '@/components/TableRefreshToolbarButton.vue'
import { TableActionConstants } from '@hilos/sdk/constants/tableActions'
import type { ChatUserTableRow } from '@hilos/sdk/types/chatUserTableRow'
import type { Presence } from '@/types/domain/Presence'

type UserEntity = ChatUserTableRow & { presence?: Presence }

const chatStore = useChatStore()
const connectionStore = useConnectionStore()
const tableStore = useTableStore()
const websocket = useWebSocket()

const tableKey = 'adminUsers'
const { refreshLoading, refreshTable } = useTableRefresh(tableKey)
const tableState = computed(() => tableStore.tableData[tableKey])
const displayRows = computed(() => getTableDisplayRows<UserEntity>(tableStore.tableData[tableKey]))
const pendingChanges = computed(() => getTablePendingChanges(tableStore.tableData[tableKey]))
const changeMarkers = computed(() => getTableChangeMarkers(tableStore.tableData[tableKey]))

const users = computed(() => {
  return displayRows.value.map((row) => {
    const liveUser = chatStore.users.find((u) => u.id === row.id)
    const presence = liveUser?.presence ?? row.presence
    return { ...row, presence }
  })
})

const handleApplyChanges = () => {
  const { hasDeletes } = tableStore.applyPendingMutations(tableKey)
  if (hasDeletes) {
    refreshTable()
  }
}

const showModal = ref(false)
const selectedUser = ref<UserEntity | null>(null)
const formUser = ref<UserEntity>({
  id: 0,
  name: '',
  lastActivity: '',
  presence: 'offline'
})
const baselineUser = ref<UserEntity | null>(null)

const modalTitle = computed(() => 'Edit User')

const cloneUser = (user: UserEntity): UserEntity => {
  return JSON.parse(JSON.stringify(user)) as UserEntity
}

const isFormDirty = computed(() => {
  if (!baselineUser.value) return false
  return JSON.stringify(formUser.value) !== JSON.stringify(baselineUser.value)
})

const isFormValid = computed(() => {
  const name = formUser.value.name.trim()
  return name.length >= 2 && name.length <= 50
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
  if (typeof item !== 'object' || item === null) return
  const user = item as UserEntity
  selectedUser.value = user
  formUser.value = cloneUser(user)
  baselineUser.value = cloneUser(user)
  showModal.value = true
}

const saveLoading = ref(false)

const saveUser = () => {
  if (!selectedUser.value || !isFormValid.value) return
  saveLoading.value = true
  sendAction(websocket, TableActionConstants.USER_UPDATE, {
    id: selectedUser.value.id,
    name: formUser.value.name.trim(),
  })
  resetForm()
}

const resetForm = () => {
  showModal.value = false
  selectedUser.value = null
  baselineUser.value = null
  saveLoading.value = false
  formUser.value = {
    id: 0,
    name: '',
    lastActivity: '',
    presence: 'offline'
  }
}
</script>
