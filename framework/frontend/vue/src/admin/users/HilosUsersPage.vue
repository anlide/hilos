<!-- HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
users table inside the admin shell. All table logic and the row view-model are
the core headless's (createHilosUsersTable / HilosUserRow); this view owns only
the column set and the cell markup, so a project mounts it by passing its
HilosUsersContext. The framework owns every cell except the trailing actions
cell, which a project fills through the `#row-actions` slot (e.g. a link to the
detail page) — the framework's own row action, the takeover, is drawn ahead of that
slot. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosImpersonate,
  createHilosUsersTable,
  HilosPages,
  type HilosTableColumnOf,
  type HilosUserRow,
  USER_CONNECTIONS_SLOT,
  USER_ONLINE_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  type HilosUsersContext,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

defineSlots<{
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  'row-actions'(props: { row: HilosUserRow }): unknown
}>()

const users = createHilosUsersTable(props.context)
const usersTable = users.controller

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => users.start())
onUnmounted(() => users.dispose())

// The takeover: a confirm modal before an admin assumes a user's identity, as
// every mutation on this project is confirmed in one. The button is on every row
// but your own — taking yourself over is refused server-side, and a control whose
// only outcome is a refusal is not one.
const impersonate = createHilosImpersonate(props.context)
const currentUid = useSignal(impersonate.currentUserId)
const impersonateOpen = ref(false)
const impersonateRow = ref<HilosUserRow | null>(null)
const impersonateAction = useTrackedAction()
const {
  loading: impersonateLoading,
  busy: impersonateBusy,
  run: runImpersonate,
  clearError: clearImpersonateError,
} = impersonateAction

function openImpersonate(row: HilosUserRow): void {
  clearImpersonateError()
  impersonateRow.value = row
  impersonateOpen.value = true
}

function closeImpersonate(): void {
  impersonateOpen.value = false
}

// Authoritative-backend: the visible effect — the shell banner, and this admin
// session becoming the non-admin target, which drops this admin-only page — is
// server-driven through the handshake broadcast, so a success only closes the
// confirm; a refusal keeps it open with the sentence the page sent back.
async function submitImpersonate(): Promise<void> {
  const row = impersonateRow.value
  if (!row || impersonateBusy.value) {
    return
  }
  if (await runImpersonate(impersonate.start(row.id))) {
    closeImpersonate()
  }
}

// Only the two presence columns name a source: they are built from the inline
// connections slot, which is the runtime summary a node keeps and the one thing here
// that can stop being current. The rest come from the user record in the database,
// and a database does not go quiet.
const columns: HilosTableColumnOf<HilosUserRow>[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  {
    key: USER_PRESENCE_FIELD,
    label: 'Presence',
    sortable: true,
    source: USER_CONNECTIONS_SLOT,
  },
  {
    key: USER_ONLINE_SESSION_COUNT_FIELD,
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
    source: USER_CONNECTIONS_SLOT,
  },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]
</script>

<template>
  <HilosAdminPage :page="HilosPages.USERS">
    <HilosViewportTable
      label="Users"
      :controller="usersTable"
      :columns="columns"
      searchable
      search-placeholder="Search users…"
      empty-text="No users yet."
    >
      <template #row="{ row }">
        <td class="text-body-secondary">{{ row.id }}</td>
        <td class="fw-medium">{{ row.name }}</td>
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
        <td>{{ row.lastActivity ?? '—' }}</td>
        <td class="text-end">
          <button
            v-if="row.id !== currentUid"
            type="button"
            class="btn btn-sm btn-outline-secondary me-2"
            title="Impersonate"
            aria-label="Impersonate"
            :data-id="`hilos-users-impersonate-${row.id}`"
            @click="openImpersonate(row)"
          >
            <i class="bi bi-person-badge" aria-hidden="true"></i>
          </button>
          <slot name="row-actions" :row="row" />
        </td>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="impersonateOpen"
      :title="
        impersonateRow
          ? `Impersonate · ${impersonateRow.name}`
          : 'Impersonate user'
      "
      initial-focus="dialog"
      @cancel="closeImpersonate"
    >
      <HilosActionError :action="impersonateAction" />
      <p v-if="impersonateRow" class="mb-0">
        Become <strong>{{ impersonateRow.name }}</strong> and see the app as
        they do? You can stop from the banner at any time.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="impersonateBusy"
          data-id="hilos-users-impersonate-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="impersonateLoading"
          :disabled="impersonateBusy"
          data-id="hilos-users-impersonate-confirm"
          @click="submitImpersonate"
        >
          Impersonate
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
