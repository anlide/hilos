<!-- HilosSecurityOauthProviderPage — the framework Hilos OAuth provider page
(HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): one provider's fields inside the
admin shell. The route {providerId} names the provider; the fields and providers
tables are global, so the core headless presets their provider filter from the
route and the server narrows both windows to it (createHilosOAuthProviderFields /
createHilosOAuthProviderSummary). A provider the project does not declare narrows
them to nothing, and the page says "provider not found". Each field shows its
effective value and where it comes from and can be edited or reset to env / the
recipe — the ↺ asks first, in a confirm dialog built like the settings orphan
delete; the client secret is write-only — shown as set / not set, never read back,
and its dialog always opens empty: it replaces, it does not show. The provider's
recipe is shown as reference, without actions. Writes are tracked actions
(createHilosSecurityOauthActions): the value redraws from the reactive table after
the backend echo, never optimistically, and a refusal surfaces with the backend's
domain phrase. Editing happens in a modal — inline forms are forbidden
(rules-and-violations.md section E) — and the modal holds its row in focus and
merges against it through the shared row-edit helper (rowEdit.ts,
conflict-resolution.md), saying what happened elsewhere on one line of room held
in advance (HilosEditNotice). The secret never reads back, so its live value is
always empty: there is nothing to merge, and the dialog only says when the row is
gone. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
  HilosPages,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type HilosOAuthFieldRow,
  type HilosSecurityOauthContext,
  type RowEditBaseline,
  type RowEditNoticeKind,
  type RowEditStep,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSecurityOauthContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosSecurityOauthProviderPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

// The route provider, as a core signal both tables follow: navigating to another
// provider sets their provider filter again and asks the server for its windows.
const providerSignal = computedSignal(
  () =>
    (router.currentRoute.get().params.providerId as string | undefined) ?? '',
)
const providerKey = useSignal(providerSignal)

const summary = createHilosOAuthProviderSummary(props.context, providerSignal)
const fields = createHilosOAuthProviderFields(props.context, providerSignal)
const { sendProviderSet, sendProviderReset } = createHilosSecurityOauthActions(
  props.context,
)

onMounted(() => {
  summary.start()
  fields.start()
})
onUnmounted(() => {
  summary.dispose()
  fields.dispose()
})

const summaryRows = useSignal(summary.controller.rows)
const summaryLoaded = useSignal(summary.controller.loaded)
/** The route provider's own row, or null until it arrives (or when it is not declared). */
const provider = computed(() => summaryRows.value[0]?.row ?? null)
/** A provider the project does not declare: the window came back empty. */
const notFound = computed(
  () => summaryLoaded.value && summaryRows.value.length === 0,
)

/** The source badge label: where a value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  db: 'Set in admin',
  env: 'From env',
  default: 'Default',
}

/** Human-readable effective value of a field that is not the secret. */
function displayValue(row: HilosOAuthFieldRow): string {
  return row.value === null || row.value === '' ? '—' : row.value
}

/** The one field the dialog edits; the secret reads as empty. */
interface FieldEditFields {
  value: string
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosOAuthFieldRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveRow ? `Changed elsewhere to "${displayValue(liveRow)}".` : ''
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

// Edit dialog: one field of the provider. The secret's dialog always opens empty.
const editOpen = ref(false)
const editRow = ref<HilosOAuthFieldRow | null>(null)
const editValue = ref('')
const editBaseline = ref<RowEditBaseline<FieldEditFields>>(
  openRowEdit<FieldEditFields>({ value: '' }),
)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction

// The live row the open dialog is about: the row the table holds in focus, which
// the server follows wherever it goes; undefined once the row is gone. An empty
// value reads as '' the way the input shows it.
const liveRow = useSignal(fields.controller.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? { value: liveRow.value.value ?? '' } : undefined,
    editBaseline.value,
    { value: editValue.value },
  ),
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() =>
  noticeText(editNotice.value, liveRow.value),
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))
const editTitle = computed(() =>
  editRow.value
    ? `${editRow.value.secret ? 'Replace' : 'Edit'} · ${editRow.value.label}`
    : 'Edit field',
)

// Reset dialog: one field back to env / the recipe, only on confirm. It reads the
// same live row the edit dialog does — one dialog is open at a time, and the
// focus is one.
const resetOpen = ref(false)
const resetRow = ref<HilosOAuthFieldRow | null>(null)
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
// A provider without both its client id and its secret is not offered at sign-in,
// so resetting either of them may take the provider off the sign-in page.
const resetStopsSignIn = computed(
  () =>
    resetRow.value?.field === 'client_id' ||
    resetRow.value?.field === 'client_secret',
)

/** What the reset dialog shows as the value now; the secret never reads back. */
function resetNowText(row: HilosOAuthFieldRow): string {
  if (row.secret) {
    return row.setState ? 'Set' : 'Not set'
  }

  return displayValue(row)
}

function openEdit(row: HilosOAuthFieldRow): void {
  // Flush pending and take the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  const fresh = fields.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  // The secret never reads back: its value is null and its dialog opens empty.
  editValue.value = fresh.value ?? ''
  editBaseline.value = openRowEdit<FieldEditFields>({ value: editValue.value })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  // The secret typed into the dialog is not kept once it is closed.
  editValue.value = ''
  fields.controller.releaseFocus()
}

// Put a step of the helper into the dialog: the snapshot moves, and a value the
// step takes lands in the input.
function applyStep(step: RowEditStep<FieldEditFields>): void {
  editBaseline.value = step.baseline
  if (step.take.value !== undefined) {
    editValue.value = step.take.value
  }
}

// The helper hands a step whenever the other side moved the value while the
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

async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value || live.value.gone || live.value.conflict) {
    return
  }
  // Nothing to save (for the secret: nothing typed) closes without a request.
  if (!live.value.dirty) {
    closeEdit()

    return
  }
  if (
    await runEditAction(
      sendProviderSet(row.providerKey, row.field, editValue.value),
    )
  ) {
    closeEdit()
  }
}

function openReset(row: HilosOAuthFieldRow): void {
  // Flush pending and take the row into focus; a row that is gone declines to
  // open.
  const fresh = fields.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearResetError()
  resetRow.value = fresh
  resetOpen.value = true
}

function closeReset(): void {
  resetOpen.value = false
  fields.controller.releaseFocus()
}

async function submitReset(): Promise<void> {
  const row = resetRow.value
  if (!row || resetBusy.value || resetGone.value) {
    return
  }
  if (await runResetAction(sendProviderReset(row.providerKey, row.field))) {
    closeReset()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_OAUTH_PROVIDER">
    <div
      v-if="notFound"
      class="alert alert-warning"
      role="status"
      data-id="hilos-oauth-provider-not-found"
    >
      Provider not found. This application declares no OAuth provider
      <code>{{ providerKey }}</code
      >.
    </div>
    <template v-else>
      <p class="text-body-secondary mb-3">
        <span class="fw-semibold text-body">{{ provider?.label }}</span>
        <code class="ms-2 small">{{ providerKey }}</code>
      </p>

      <HilosViewportTable :controller="fields.controller">
        <template #cell-field="{ row }">
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.field }}</code>
        </template>
        <template #cell-value="{ row }">
          <span
            v-if="row.secret"
            class="text-body-secondary fst-italic"
            :data-id="`hilos-oauth-field-value-${row.field}`"
          >
            {{ row.setState ? 'Set' : 'Not set' }}
          </span>
          <span v-else :data-id="`hilos-oauth-field-value-${row.field}`">
            {{ displayValue(row) }}
          </span>
        </template>
        <template #cell-source="{ row }">
          <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
            {{ SOURCE_LABEL[row.source] ?? row.source }}
          </span>
        </template>
        <template #cell-actions="{ row }">
          <button
            type="button"
            class="btn btn-sm btn-outline-primary"
            :title="row.secret ? 'Replace' : 'Edit'"
            :aria-label="`${row.secret ? 'Replace' : 'Edit'} ${row.label}`"
            :data-id="`hilos-oauth-field-edit-${row.field}`"
            @click="openEdit(row)"
          >
            <i
              :class="row.secret ? 'bi bi-key' : 'bi bi-pencil'"
              aria-hidden="true"
            ></i>
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :title="`Reset ${row.label} to env/default`"
            :aria-label="`Reset ${row.label} to env/default`"
            :disabled="row.source !== 'db'"
            :data-id="`hilos-oauth-field-reset-${row.field}`"
            @click="openReset(row)"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
          </button>
        </template>
      </HilosViewportTable>

      <section
        v-if="provider"
        class="card mt-4"
        aria-labelledby="hilos-oauth-recipe-heading"
        data-id="hilos-oauth-recipe"
      >
        <div class="card-body">
          <h2 id="hilos-oauth-recipe-heading" class="h6 mb-1">Recipe</h2>
          <p class="small text-body-secondary mb-3">
            {{
              provider.builtIn
                ? 'Shipped by Hilos for this provider. It is not edited here.'
                : 'Declared by this application for a provider Hilos ships no recipe for.'
            }}
          </p>
          <dl class="row small mb-0">
            <dt class="col-sm-4">Authorization endpoint</dt>
            <dd class="col-sm-8">
              <code>{{ provider.authorizeUrl }}</code>
            </dd>
            <dt class="col-sm-4">Token endpoint</dt>
            <dd class="col-sm-8">
              <code>{{ provider.tokenUrl }}</code>
            </dd>
            <dt class="col-sm-4">Userinfo endpoint</dt>
            <dd class="col-sm-8">
              <code>{{ provider.userInfoUrl }}</code>
            </dd>
            <dt class="col-sm-4">Account id field</dt>
            <dd class="col-sm-8">
              <code>{{ provider.subjectKey }}</code>
            </dd>
            <dt class="col-sm-4">Email field</dt>
            <dd class="col-sm-8">
              <code>{{ provider.emailKey }}</code>
            </dd>
            <dt class="col-sm-4">Name field</dt>
            <dd class="col-sm-8 mb-0">
              <code>{{ provider.nameKey }}</code>
            </dd>
          </dl>
        </div>
      </section>
    </template>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="live.dirty"
      :aria-label="editTitle"
      @cancel="closeEdit"
    >
      <template #header>
        <ConflictHeader :title="editTitle" :conflict="live.conflict" />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <label class="form-label" for="hilos-oauth-field-input">
          {{ editRow.label }}
        </label>
        <input
          id="hilos-oauth-field-input"
          v-model="editValue"
          :type="editRow.secret ? 'password' : 'text'"
          :autocomplete="editRow.secret ? 'new-password' : 'off'"
          class="form-control"
          data-id="hilos-oauth-field-input"
          data-autofocus
        />
        <p v-if="editRow.secret" class="form-text mb-0">
          The current secret is never shown. What you enter replaces it.
        </p>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-oauth-field-edit-notice"
        />
      </form>
      <template #actions="{ requestClose }">
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!live.dirty || editBusy || live.gone"
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
              data-id="hilos-oauth-field-save"
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
      :title="resetRow ? `Reset · ${resetRow.label}` : 'Reset field'"
      :close-on-backdrop="!resetBusy"
      :close-on-esc="!resetBusy"
      initial-focus="dialog"
      @cancel="closeReset"
    >
      <HilosActionError
        :action="resetAction"
        details-title="Couldn't reset the field"
      />
      <dl v-if="resetShown" class="row mb-0">
        <dt class="col-4">Now</dt>
        <dd class="col-8 text-break" data-id="hilos-oauth-field-reset-now">
          {{ resetNowText(resetShown) }}
        </dd>
        <dt class="col-4">Back to</dt>
        <dd class="col-8" data-id="hilos-oauth-field-reset-default">
          the env value, or the default when env has none
        </dd>
      </dl>
      <p
        v-if="resetStopsSignIn && provider"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-oauth-field-reset-signin"
      >
        If env has none, sign-in with {{ provider.label }} stops being offered.
      </p>
      <p
        v-if="resetGone"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-oauth-field-reset-gone"
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
          data-id="hilos-oauth-field-reset-confirm"
          @click="submitReset"
        >
          Reset
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
