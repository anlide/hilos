<!-- The chat admin-users page (PAGE_ADMIN_USERS) at /hilos/admin_users: the admin
users table reached from the dashboard's "Chat administration" section. A user's
only action is a rename (edit) — no add or delete. The server-windowed table and
the framework user row view-model live with the page (adminUsersPage.ts), the
rename submit in adminUsersActions.ts. Authoritative-backend: a submit dispatches
a tracked action and the dialog closes on its `::success` reply (useTrackedAction,
step 7.4); a failure surfaces in the dialog. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  HilosModal,
  HilosViewportTable,
  LoadingButton,
  useTrackedAction,
} from '@hilos/vue'
import { type HilosTableColumn, type HilosUserRow } from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import { sendAdminUserUpdate } from './adminUsersActions'
import {
  adminUsersTable,
  disposeAdminUsersTable,
  startAdminUsersTable,
} from './adminUsersPage'

defineOptions({ name: 'AdminUsersPage' })

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(startAdminUsersTable)
onUnmounted(disposeAdminUsersTable)

const columns: HilosTableColumn[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  { key: 'presence', label: 'Presence', sortable: true },
  {
    key: 'onlineSessionCount',
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

// Edit dialog: rename one user.
const editOpen = ref(false)
const editRow = ref<HilosUserRow | null>(null)
const editName = ref('')
const {
  loading: editLoading,
  busy: editBusy,
  error: editError,
  run: runEditAction,
  clearError: clearEditError,
} = useTrackedAction()

const editDirty = computed(() => {
  const name = editName.value.trim()

  return !!editRow.value && name !== '' && name !== editRow.value.name
})

function openEdit(row: HilosUserRow): void {
  // Flush pending so the form edits the latest committed row; a row removed by
  // someone else (now a placeholder) declines to open.
  const fresh = adminUsersTable.applyAndResolve(String(row.id))
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  editName.value = fresh.name
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
}

// Authoritative-backend: dispatch the tracked action, close on its `::success`
// reply; a failure stays open with the reason shown.
async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value) {
    return
  }
  const name = editName.value.trim()
  if (name === '' || name === row.name) {
    closeEdit()

    return
  }
  if (await runEditAction(sendAdminUserUpdate(row.id, name))) {
    closeEdit()
  }
}
</script>

<template>
  <section data-id="admin-users-view">
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0">Admin users</h1>
      <p class="mb-0 text-body-secondary">
        Application users: presence, activity, and rename.
      </p>
    </div>

    <HilosViewportTable
      :controller="adminUsersTable"
      :columns="columns"
      searchable
      search-placeholder="Search users…"
      empty-text="No users yet."
    >
      <template #row="{ row }">
        <td class="text-body-secondary">{{ row.id }}</td>
        <td class="fw-medium">{{ row.name }}</td>
        <td>{{ row.lastActivity ?? '—' }}</td>
        <td>
          <span
            class="badge"
            :class="
              row.presence === 'online'
                ? 'text-bg-success'
                : 'text-bg-secondary'
            "
            >{{ row.presence }}</span
          >
        </td>
        <td class="text-end">{{ row.onlineSessionCount }}</td>
        <td class="text-end">
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            title="Edit"
            aria-label="Edit"
            :data-id="`admin-users-edit-${row.id}`"
            @click="openEdit(row)"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
        </td>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :title="editRow ? `Edit · ${editRow.name}` : 'Edit user'"
      :confirm-on-close="editDirty"
      @cancel="closeEdit"
    >
      <div
        v-if="editError"
        class="alert alert-danger"
        role="alert"
        data-id="admin-users-error"
      >
        {{ editError }}
      </div>
      <form v-if="editRow" @submit.prevent="submitEdit">
        <div class="mb-3">
          <label class="form-label" for="admin-users-name">Name</label>
          <input
            id="admin-users-name"
            v-model="editName"
            type="text"
            class="form-control"
            required
            minlength="2"
            maxlength="64"
            data-id="admin-users-name"
          />
        </div>
        <div class="mb-0">
          <span class="form-label d-block">Last activity</span>
          <div class="form-control-plaintext">
            {{ editRow.lastActivity ?? '—' }}
          </div>
        </div>
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="editBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="editLoading"
          :disabled="!editDirty || editBusy"
          data-id="admin-users-save"
          @click="submitEdit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
