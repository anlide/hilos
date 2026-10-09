<!-- HilosSecurityOauthPage — the framework Hilos OAuth providers page
(HilosPages.SECURITY_OAUTH, HIL-286): the providers table inside the admin shell,
with the one return address every provider redirects back to above it. One row per
provider the project declares (built from its provider directory, not a hardcoded
list): whether it can sign anyone in, where its client id comes from, whether a
secret is in force, and a link to its configuration page. The tables, the row
view-models and the round-trips are the core headless's
(createHilosOAuthProvidersTable / createHilosOAuthRedirect /
createHilosSecurityOauthActions); this view owns only the markup, so a project
mounts it by passing its HilosSecurityOauthContext. The address is edited in a
modal — inline forms are forbidden (rules-and-violations.md section E) — as a
tracked action: it redraws from the reactive table after the backend echo, never
optimistically, and a refusal surfaces with the backend's domain phrase. The
modal is the core row-edit session over the focused address row
(createHilosOauthRedirectEdit, rowEditSession.ts, conflict-resolution.md),
saying what happened elsewhere on one line of room held in advance
(HilosEditNotice); this view binds the input. The ↺ resets
the address to env only through a confirm dialog built like the settings orphan
delete, holding the same row in focus. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosOauthRedirectEdit,
  createHilosOAuthProvidersTable,
  createHilosOAuthRedirect,
  createHilosSecurityOauthActions,
  HilosPages,
  resolveHilosPath,
  type HilosOAuthProviderRow,
  type HilosOAuthRedirectRow,
  type HilosSecurityOauthContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecurityOauthContext
}>()

const providers = createHilosOAuthProvidersTable(props.context)
const redirect = createHilosOAuthRedirect(props.context)
const actions = createHilosSecurityOauthActions(props.context)
const { sendRedirectReset } = actions

onMounted(() => {
  providers.start()
  redirect.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  providers.dispose()
  redirect.dispose()
})

const redirectRows = useSignal(redirect.controller.rows)
/** The one return-address row, once its window has arrived. */
const redirectRow = computed(() => redirectRows.value[0]?.row ?? null)

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** The provider's configuration page path (its {providerId} route param is the key). */
function providerPath(row: HilosOAuthProviderRow): string {
  return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
    providerId: row.providerKey,
  })
}

// Edit dialog: the return address, as the core window has it; this view binds
// the input.
const editor = createHilosOauthRedirectEdit(redirect.controller, actions)
const opened = useSignal(editor.opened)
const editOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) editor.close()
  },
})
const editForm = useSignal(editor.form)
const live = useSignal(editor.state)
const editNoticeText = useSignal(editor.noticeText)
const editSaveLabel = useSignal(editor.saveLabel)
const canSave = useSignal(editor.canSave)
const editHidden = computed(() => editForm.value.hidden)
const editValue = computed({
  get: () => editForm.value.text,
  set: (typed: string) => editor.patchForm({ text: typed }),
})
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editNotice = computed(() => live.value.notice?.kind ?? null)
// The live row the open reset dialog is about: the row the table holds in
// focus, which the server follows wherever it goes; undefined once the row is
// gone.
const liveRow = useSignal(redirect.controller.focusedRow)

// Reset dialog: the address back to env, only on confirm. It reads the same live
// row the edit dialog does — one dialog is open at a time, and the focus is one.
const resetOpen = ref(false)
const resetRow = ref<HilosOAuthRedirectRow | null>(null)
const resetAction = useTrackedAction()
const {
  loading: resetLoading,
  busy: resetBusy,
  run: runResetAction,
  clearError: clearResetError,
} = resetAction
const resetShown = computed(() => liveRow.value ?? resetRow.value)
const resetGone = computed(
  () => liveRow.value === undefined || liveRow.value.source !== 'db',
)

function openEdit(): void {
  const row = redirectRow.value
  if (!row) {
    return
  }
  // The window takes the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  clearEditError()
  editor.open(row.key)
}

function closeEdit(): void {
  editor.close()
}

function acceptMine(): void {
  editor.keepMine()
}

function acceptTheirs(): void {
  editor.takeTheirs()
}

// Save and Enter go through the window's one door: it refuses, closes an
// unchanged draft, or dispatches the tracked action with the address trimmed
// and closes on its `::success` reply; a refusal stays in the dialog.
function submitEdit(): void {
  void editor.save(runEditAction)
}

function openReset(): void {
  const row = redirectRow.value
  if (!row) {
    return
  }
  // Flush pending and take the row into focus; a row that is gone declines to
  // open.
  const fresh = redirect.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearResetError()
  resetRow.value = fresh
  resetOpen.value = true
}

function closeReset(): void {
  resetOpen.value = false
  redirect.controller.releaseFocus()
}

async function submitReset(): Promise<void> {
  if (!resetRow.value || resetBusy.value || resetGone.value) {
    return
  }
  if (await runResetAction(sendRedirectReset())) {
    closeReset()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_OAUTH">
    <section class="card mb-4" aria-labelledby="hilos-oauth-redirect-heading">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <h2 id="hilos-oauth-redirect-heading" class="h6 mb-1">
              Return address
            </h2>
            <p class="small text-body-secondary mb-2">
              Where every provider sends the browser back after sign-in.
              Register this address with each provider.
            </p>
            <code
              v-if="redirectRow?.setState"
              data-id="hilos-oauth-redirect-value"
            >
              <HilosHideable :value="redirectRow.value" />
            </code>
            <span
              v-else
              class="text-body-secondary fst-italic"
              data-id="hilos-oauth-redirect-value"
            >
              Not set
            </span>
            <span
              v-if="redirectRow"
              class="badge text-bg-secondary-subtle text-secondary-emphasis ms-2"
            >
              {{ SOURCE_LABEL[redirectRow.source] ?? redirectRow.source }}
            </span>
          </div>
          <div class="d-flex gap-1 flex-shrink-0">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              title="Edit"
              aria-label="Edit return address"
              data-id="hilos-oauth-redirect-edit"
              @click="openEdit"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              title="Reset return address to env"
              aria-label="Reset return address to env"
              :disabled="redirectRow?.source !== 'db'"
              data-id="hilos-oauth-redirect-reset"
              @click="openReset"
            >
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </div>
    </section>

    <HilosViewportTable :controller="providers.controller">
      <template #cell-label="{ row }">
        <div class="fw-semibold">{{ row.label }}</div>
        <code class="small text-body-secondary">{{ row.providerKey }}</code>
      </template>
      <template #cell-configured="{ row }">
        <span
          v-if="row.configured"
          class="badge text-bg-success-subtle text-success-emphasis"
        >
          Configured
        </span>
        <span
          v-else
          class="badge text-bg-warning-subtle text-warning-emphasis"
          :title="`${row.missingFields} required field(s) not set`"
        >
          {{ row.missingFields }} missing
        </span>
      </template>
      <template #cell-clientIdSource="{ row }">
        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
          {{ SOURCE_LABEL[row.clientIdSource] ?? row.clientIdSource }}
        </span>
      </template>
      <template #cell-secretSet="{ row }">
        <span class="text-body-secondary fst-italic">
          {{ row.secretSet ? 'Set' : 'Not set' }}
        </span>
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          :to="providerPath(row)"
          class="btn btn-sm btn-outline-primary"
          :aria-label="`Configure ${row.label}`"
          :data-id="`hilos-oauth-provider-open-${row.providerKey}`"
        >
          Configure
        </HilosLink>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="live.dirty"
      aria-label="Edit · Return address"
      @cancel="closeEdit"
    >
      <template #header>
        <ConflictHeader
          title="Edit · Return address"
          :conflict="live.conflict"
        />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form @submit.prevent="submitEdit">
        <template v-if="editHidden">
          <div class="form-label">Return address</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <label class="form-label" for="hilos-oauth-redirect-input">
            Return address
          </label>
          <input
            id="hilos-oauth-redirect-input"
            v-model="editValue"
            type="url"
            class="form-control"
            placeholder="https://app.example/auth/callback"
            data-id="hilos-oauth-redirect-input"
            data-autofocus
          />
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-oauth-redirect-edit-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!canSave"
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
              data-id="hilos-oauth-redirect-save"
              @click="onSave"
            >
              {{ editSaveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>

    <HilosModal
      v-model="resetOpen"
      title="Reset · Return address"
      :close-on-backdrop="!resetBusy"
      :close-on-esc="!resetBusy"
      initial-focus="dialog"
      @cancel="closeReset"
    >
      <HilosActionError
        :action="resetAction"
        details-title="Couldn't reset the return address"
      />
      <dl v-if="resetShown" class="row mb-0">
        <dt class="col-4">Now</dt>
        <dd class="col-8 text-break" data-id="hilos-oauth-redirect-reset-now">
          <HilosHideable v-if="resetShown.setState" :value="resetShown.value" />
          <template v-else>Not set</template>
        </dd>
        <dt class="col-4">Back to</dt>
        <dd class="col-8" data-id="hilos-oauth-redirect-reset-default">
          the env value — empty when env has none
        </dd>
      </dl>
      <p
        v-if="resetGone"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-oauth-redirect-reset-gone"
      >
        Already reset elsewhere.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="resetBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="resetLoading"
          :disabled="resetBusy || resetGone"
          data-id="hilos-oauth-redirect-reset-confirm"
          @click="submitReset"
        >
          Reset
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
