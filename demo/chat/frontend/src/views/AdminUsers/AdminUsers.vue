<!-- The chat admin-users page (PAGE_ADMIN_USERS) at /hilos/app/users: the admin
users table reached from the dashboard's "Chat administration" section. The
heading, the lead and the breadcrumb come from the page catalog on the backend
through the framework's HilosAdminPage shell. A user's
only action is a rename (edit) — no add or delete. The server-windowed table and
the framework user row view-model live with the page (adminUsersPage.ts), the
rename submit in adminUsersActions.ts. Authoritative-backend: a submit dispatches
a tracked action and the dialog closes on its `::success` reply (useTrackedAction,
step 7.4); a failure surfaces in the dialog. The dialog merges against the live
row through the shared row-edit helper (rowEdit.ts, conflict-resolution.md) and
says what happened elsewhere on one line of room held in advance
(HilosEditNotice); Save stays locked while nothing changed. Bootstrap classes
only (styling-rules.md). -->
<script setup lang="ts">
import {
  ConflictActions,
  ConflictHeader,
  HilosActionError,
  HilosAdminPage,
  HilosEditNotice,
  HilosHiddenMark,
  HilosHideable,
  HilosModal,
  HilosViewportTable,
  LoadingButton,
  useSignal,
  useTrackedAction,
} from '@hilos/vue'
import {
  hiddenAsWord,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type Hideable,
  type HilosTableColumn,
  type HilosUserRow,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import { PAGE_ADMIN_USERS } from '../../pages/keys'
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

/**
 * The one field the dialog edits: the display name — hidden for a viewer of the
 * admin view mode, and then the dialog shows the mark in place of the input.
 */
interface UserEditFields {
  name: Hideable<string>
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(live: RowEditState<UserEditFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return `Changed elsewhere to "${hiddenAsWord(live.fields.name.incoming)}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

// Edit dialog: rename one user.
const editOpen = ref(false)
const editRow = ref<HilosUserRow | null>(null)
// A hidden name stays the one hidden value, so the row-edit helper sees it
// unchanged and the dialog is never dirty; the input edits only a name.
const editName = ref<Hideable<string>>('')
const editNameText = computed({
  get: () => (isHiddenValue(editName.value) ? '' : editName.value),
  set: (next: string) => {
    editName.value = next
  },
})
const editBaseline = ref<RowEditBaseline<UserEditFields>>(
  openRowEdit<UserEditFields>({ name: '' }),
)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction

// The live row the open dialog is about: the row the table holds in focus, which
// the server follows wherever it goes; undefined once the row is gone.
const liveRow = useSignal(adminUsersTable.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? { name: liveRow.value.name } : undefined,
    editBaseline.value,
    {
      name: isHiddenValue(editName.value)
        ? editName.value
        : editName.value.trim(),
    },
  ),
)
// A hidden name counts as empty: there is nothing to save.
const editEmpty = computed(
  () => isHiddenValue(editName.value) || editName.value.trim() === '',
)
// The title and "Last activity" follow the live row while it is there, and
// keep the row the dialog opened with once it is gone.
const editShown = computed(() => liveRow.value ?? editRow.value)
const editTitle = computed(() =>
  editShown.value
    ? `Edit · ${hiddenAsWord(editShown.value.name)}`
    : 'Edit user',
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() => noticeText(live.value))
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))

function openEdit(row: HilosUserRow): void {
  // Flush pending and take the row into focus, so the form edits the latest
  // committed row and follows it from here; a row removed by someone else (now a
  // placeholder) declines to open.
  const fresh = adminUsersTable.focusRow(String(row.id))
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  editName.value = fresh.name
  editBaseline.value = openRowEdit<UserEditFields>({ name: fresh.name })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  adminUsersTable.releaseFocus()
}

// Put a step of the helper into the dialog: the snapshot moves, and a name the
// step takes lands in the input.
function applyStep(step: RowEditStep<UserEditFields>): void {
  editBaseline.value = step.baseline
  if (step.take.name !== undefined) {
    editName.value = step.take.name
  }
}

// The helper hands a step whenever the other side moved the name while the
// person left it alone, or both arrived at the same one; the dialog applies it
// at once.
watch(
  () => live.value.settle,
  (settle) => {
    if (editOpen.value && settle) {
      applyStep(settle)
    }
  },
)

function acceptMine(): void {
  editBaseline.value = keepMineRowEdit(live.value, editBaseline.value)
}

function acceptTheirs(): void {
  applyStep(takeTheirsRowEdit(live.value, editBaseline.value))
}

// Authoritative-backend: dispatch the tracked action, close on its `::success`
// reply; a failure stays open with the reason shown. An untouched edit closes
// without a round trip — there is nothing to save (rules-and-violations.md,
// section E).
async function submitEdit(): Promise<void> {
  const row = editRow.value
  const typed = editName.value
  if (
    !row ||
    isHiddenValue(typed) ||
    editBusy.value ||
    live.value.gone ||
    editEmpty.value
  ) {
    return
  }
  if (!live.value.dirty) {
    closeEdit()

    return
  }
  if (await runEditAction(sendAdminUserUpdate(row.id, typed.trim()))) {
    closeEdit()
  }
}
</script>

<template>
  <HilosAdminPage :page="PAGE_ADMIN_USERS">
    <section data-id="admin-users-view">
      <HilosViewportTable
        label="Users"
        :controller="adminUsersTable"
        :columns="columns"
        searchable
        search-placeholder="Search users…"
        empty-text="No users yet."
      >
        <template #row="{ row }">
          <td class="text-body-secondary">{{ row.id }}</td>
          <td class="fw-medium"><HilosHideable :value="row.name" /></td>
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
        :confirm-on-close="live.dirty"
        @cancel="closeEdit"
      >
        <template #header>
          <ConflictHeader :title="editTitle" :conflict="live.conflict" />
        </template>
        <HilosActionError :action="editAction" details-title="Couldn't save" />
        <form v-if="editShown" @submit.prevent="submitEdit">
          <div v-if="isHiddenValue(editName)" class="mb-3">
            <span class="form-label d-block">Name</span>
            <HilosHiddenMark />
          </div>
          <div v-else class="mb-3">
            <label class="form-label" for="admin-users-name">Name</label>
            <input
              id="admin-users-name"
              v-model="editNameText"
              type="text"
              class="form-control"
              required
              minlength="2"
              maxlength="64"
              data-id="admin-users-name"
              data-autofocus
            />
          </div>
          <HilosEditNotice
            :kind="editNotice"
            :text="editNoticeText"
            data-id="admin-users-edit-notice"
          />
          <div class="mb-0">
            <span class="form-label d-block">Last activity</span>
            <div class="form-control-plaintext">
              {{ editShown.lastActivity ?? '—' }}
            </div>
          </div>
        </form>
        <template #actions="{ requestClose }">
          <ConflictActions
            :conflict="live.conflict"
            :disable-save="editEmpty || !live.dirty || editBusy || live.gone"
            :save-label="editSaveLabel"
            @save="submitEdit"
            @accept-mine="acceptMine"
            @accept-theirs="acceptTheirs"
          >
            <template #cancel-button>
              <button
                type="button"
                class="btn btn-secondary"
                :disabled="editBusy"
                @click="requestClose"
              >
                Cancel
              </button>
            </template>
            <template #save-button="{ disabled, onSave }">
              <LoadingButton
                class="btn-primary"
                :loading="editLoading"
                :disabled="disabled"
                data-id="admin-users-save"
                @click="onSave"
              >
                {{ editSaveLabel }}
              </LoadingButton>
            </template>
          </ConflictActions>
        </template>
      </HilosModal>
    </section>
  </HilosAdminPage>
</template>
