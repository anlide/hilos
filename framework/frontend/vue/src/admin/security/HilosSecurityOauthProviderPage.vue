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
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import {
  createHilosOauthProviderFieldEdit,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderHead,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
  type HilosOAuthFieldRow,
  type HilosOAuthProviderHead,
  type HilosSecurityOauthContext,
} from '@hilos/core/admin/security'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosAuthMethodIcon } from '../../auth/authMethodIcons.js'
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
const actions = createHilosSecurityOauthActions(props.context)
const { sendProviderReset } = actions

onMounted(() => {
  summary.start()
  fields.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  summary.dispose()
  fields.dispose()
})

const summaryRows = useSignal(summary.controller.rows)
/** The route provider's own row, or null until it arrives (or when it is not declared). */
const provider = computed(() => summaryRows.value[0]?.row ?? null)
const firstHead = createHilosOAuthProviderHead(props.context)
const headSignal = computedSignal<HilosOAuthProviderHead | null | undefined>(
  () => {
    if (
      props.context.scopes.page()?.key !== HilosPages.SECURITY_OAUTH_PROVIDER ||
      router.currentRoute.get().page !== HilosPages.SECURITY_OAUTH_PROVIDER ||
      router.pageLoading.get()
    ) {
      return undefined
    }
    const snapshot = firstHead.get()
    if (snapshot === null || snapshot === undefined) return snapshot
    const key = providerSignal.get()
    if (snapshot.providerKey !== key) return undefined

    // A table window may still belong to the previous provider. Only this
    // route's row can take over from its complete first-answer head.
    return (
      summary.controller.rows.get().find(({ row }) => row?.providerKey === key)
        ?.row ?? snapshot
    )
  },
)
const head = useSignal(headSignal)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.SECURITY_OAUTH_PROVIDER,
  computedSignal(() => headSignal.get()?.label),
)
onUnmounted(unbindName)
/** Only an explicit first-answer null declares an unknown provider. */
const notFound = computed(() => head.value === null)

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

// Edit dialog: one field of the provider, as the core window has it; this view
// binds the input. The secret's dialog always opens empty and forgets what was
// typed when it closes.
const editor = createHilosOauthProviderFieldEdit(fields.controller, actions)
const opened = useSignal(editor.opened)
const editOpen = computed({
  get: () => opened.value,
  set: (next: boolean) => {
    if (!next) editor.close()
  },
})
const editRow = useSignal(editor.row)
const editForm = useSignal(editor.form)
const live = useSignal(editor.state)
const editNoticeText = useSignal(editor.noticeText)
const editSaveLabel = useSignal(editor.saveLabel)
const saveAvailable = useSignal(editor.saveAvailable)
// The session's save in flight: nothing closes the dialog and its body stays
// locked until the reply, and Cancel waits with it.
const editSaving = useSignal(editor.saving)
const editValue = computed({
  get: () => editForm.value.value,
  set: (typed: string) => editor.setForm({ value: typed }),
})
const editAction = useTrackedAction()
const {
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editTitle = computed(() =>
  editRow.value
    ? `${editRow.value.secret ? 'Replace' : 'Edit'} · ${editRow.value.label}`
    : 'Edit field',
)
// The live row the open reset dialog is about: the row the table holds in
// focus, which the server follows wherever it goes; undefined once the row is
// gone.
const liveRow = useSignal(fields.controller.focusedRow)

// Reset dialog: one field back to env / the recipe, only on confirm. It reads the
// same live row the edit dialog does — one dialog is open at a time, and the
// focus is one.
const resetOpen = ref(false)
const resetRow = ref<HilosOAuthFieldRow | null>(null)
const resetAction = useTrackedAction()
const {
  busy: resetBusy,
  run: runResetAction,
  clearError: clearResetError,
} = resetAction
const resetShown = computed(() => liveRow.value ?? resetRow.value)
const resetGone = computed(
  () => liveRow.value === undefined || liveRow.value.source !== 'db',
)
// Warn only when this reset removes a required value from a ready provider.
const resetStopsSignIn = computed(
  () =>
    !resetGone.value &&
    provider.value?.configured === true &&
    (resetShown.value?.field === 'client_id' ||
      resetShown.value?.field === 'client_secret') &&
    !resetShown.value.defaultSetState,
)

/** What the reset dialog shows as the value now; the secret never reads back. */
function resetNowText(row: HilosOAuthFieldRow): string {
  if (row.secret) {
    return row.setState ? 'Set' : 'Not set'
  }

  return displayValue(row)
}

function openEdit(row: HilosOAuthFieldRow): void {
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
// unchanged draft (for the secret: nothing typed), or dispatches the tracked
// action and closes on its `::success` reply; a refusal stays in the dialog.
function submitEdit(): void {
  void editor.save(runEditAction)
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
  <HilosAdminPage :page="HilosPages.SECURITY_OAUTH_PROVIDER" head-kind="entity">
    <template #head-visual>
      <span
        v-if="head"
        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
      >
        <i
          :class="hilosAuthMethodIcon(head.providerKey)"
          aria-hidden="true"
          data-id="oauth-provider-head-icon"
        ></i>
      </span>
    </template>
    <template #head-badges>
      <span
        v-if="head"
        class="badge"
        :class="
          head.configured
            ? 'text-bg-success-subtle text-success-emphasis'
            : 'text-bg-warning-subtle text-warning-emphasis'
        "
        data-id="oauth-provider-head-state"
      >
        <i
          :class="
            head.configured
              ? 'bi bi-check-circle me-1'
              : 'bi bi-exclamation-triangle me-1'
          "
          aria-hidden="true"
        ></i>
        {{ head.configured ? 'Configured' : `${head.missingFields} missing` }}
      </span>
    </template>
    <template #head-description>
      <p v-if="head" class="text-body-secondary mb-0">
        Provider
        <code data-id="oauth-provider-head-key">{{ head.providerKey }}</code>
      </p>
    </template>
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
    <HilosSkeleton v-else-if="head === undefined" />
    <template v-else>
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
            title="Reset to default"
            aria-label="Reset to default"
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
      :busy="editSaving"
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
          :disable-save="!saveAvailable"
          :save-label="editSaveLabel"
          @save="submitEdit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
          <template #cancel-button>
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="editSaving"
              @click="requestClose"
            >
              Cancel
            </button>
          </template>
          <template #save-button="{ disabled, onSave }">
            <LoadingButton
              class="btn-primary"
              :loading="editBusy"
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
      :busy="resetBusy"
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
        <dd class="col-8 text-break" data-id="hilos-oauth-field-reset-default">
          <template v-if="resetShown.secret">
            {{ resetShown.defaultSetState ? 'Set' : 'Not set' }}
          </template>
          <HilosHideable v-else :value="resetShown.defaultValue">
            <template #default="{ value }">
              {{ value === null || value === '' ? '—' : value }}
            </template>
          </HilosHideable>
          {{ ' ' }}
          <span
            class="badge text-bg-secondary-subtle text-secondary-emphasis ms-2"
          >
            {{ SOURCE_LABEL[resetShown.defaultSource] }}
          </span>
        </dd>
      </dl>
      <div class="hilos-stack mt-2 text-body-secondary">
        <p class="mb-0 invisible" aria-hidden="true" inert>
          After this reset, sign-in with {{ provider?.label }} stops being
          offered.
        </p>
        <p
          v-if="resetStopsSignIn"
          class="mb-0"
          data-id="hilos-oauth-field-reset-signin"
        >
          After this reset, sign-in with {{ provider?.label }} stops being
          offered.
        </p>
      </div>
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
          :loading="resetBusy"
          :disabled="resetGone"
          data-id="hilos-oauth-field-reset-confirm"
          @click="submitReset"
        >
          Reset
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
