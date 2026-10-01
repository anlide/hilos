<!-- HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
users table inside the admin shell. All table logic, the row view-model, and what
the table declares about its frame — columns, search, empty state — are the core
headless's (createHilosUsersTable / HilosUserRow); this view owns only the markup,
so a project mounts it by passing its HilosUsersContext. The framework owns every
cell except the trailing actions cell, which a project fills through the
`#row-actions` slot (e.g. a link to the detail page) — the framework's own row
action, the takeover, is drawn ahead of that slot. The bar's "Past deadline on"
filter narrows the list to the people past one legal document's deadline and
lives in the address too (`/hilos/users/terms`, HIL-945): the legal section's
root links its count there, and changing the filter rewrites the address.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosImpersonate,
  createHilosUsersTable,
  hiddenAsWord,
  HilosPages,
  type HilosUserRow,
  type HilosUsersContext,
} from '@hilos/core'
import { inject, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
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

// The lapsed filter is read from the address the page opened on and written
// back as it changes; mounted without a navigator, the list opens whole and
// leaves the address alone.
const router = inject(hilosRouterKey, undefined)
const users = createHilosUsersTable(props.context, router)
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
</script>

<template>
  <HilosAdminPage :page="HilosPages.USERS">
    <HilosViewportTable :controller="usersTable">
      <template #cell-id="{ row }">{{ row.id }}</template>
      <template #cell-name="{ row }"
        ><HilosHideable :value="row.name"
      /></template>
      <template #cell-presence="{ row }">
        <span
          class="badge"
          :class="
            row.presence === 'online' ? 'text-bg-success' : 'text-bg-secondary'
          "
          >{{ row.presence }}</span
        >
      </template>
      <template #cell-onlineSessionCount="{ row }">{{
        row.onlineSessionCount
      }}</template>
      <template #cell-lastActivity="{ row }">{{
        row.lastActivity ?? '—'
      }}</template>
      <template #cell-actions="{ row }">
        <button
          v-if="row.id !== currentUid"
          type="button"
          class="btn btn-sm btn-outline-secondary"
          title="Impersonate"
          aria-label="Impersonate"
          :data-id="`hilos-users-impersonate-${row.id}`"
          @click="openImpersonate(row)"
        >
          <i class="bi bi-person-badge" aria-hidden="true"></i>
        </button>
        <slot name="row-actions" :row="row" />
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="impersonateOpen"
      :title="
        impersonateRow
          ? `Impersonate · ${hiddenAsWord(impersonateRow.name)}`
          : 'Impersonate user'
      "
      initial-focus="dialog"
      @cancel="closeImpersonate"
    >
      <HilosActionError
        :action="impersonateAction"
        details-title="Couldn't sign in as this user"
      />
      <p v-if="impersonateRow" class="mb-0">
        Become <strong><HilosHideable :value="impersonateRow.name" /></strong>
        and see the app as they do? You can stop from the banner at any time.
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
