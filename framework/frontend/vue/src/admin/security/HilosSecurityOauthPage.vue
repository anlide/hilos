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
modal holds the address row in focus and merges against it through the shared
row-edit helper (rowEdit.ts, conflict-resolution.md), saying what happened
elsewhere on one line of room held in advance (HilosEditNotice). Bootstrap
classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosOAuthProvidersTable,
  createHilosOAuthRedirect,
  createHilosSecurityOauthActions,
  HilosPages,
  keepMineRowEdit,
  openRowEdit,
  resolveHilosPath,
  resolveRowEdit,
  takeTheirsRowEdit,
  type HilosOAuthProviderRow,
  type HilosSecurityOauthContext,
  type RowEditBaseline,
  type RowEditNoticeKind,
  type RowEditStep,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
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
const { sendRedirectSet, sendRedirectReset } = createHilosSecurityOauthActions(
  props.context,
)

onMounted(() => {
  providers.start()
  redirect.start()
})
onUnmounted(() => {
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

const resetAction = useTrackedAction()

function resetRedirect(): void {
  void resetAction.run(sendRedirectReset())
}

/** The one field the return-address dialog edits. */
interface RedirectEditFields {
  value: string
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveValue: string | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your text stays to copy.'
    case 'conflict':
      return liveValue === undefined
        ? ''
        : `Changed elsewhere to "${liveValue === '' ? '—' : liveValue}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

// Edit dialog: the return address.
const editOpen = ref(false)
const editValue = ref('')
const editBaseline = ref<RowEditBaseline<RedirectEditFields>>(
  openRowEdit<RedirectEditFields>({ value: '' }),
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
const liveRow = useSignal(redirect.controller.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? { value: liveRow.value.value } : undefined,
    editBaseline.value,
    { value: editValue.value },
  ),
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() =>
  noticeText(editNotice.value, liveRow.value?.value),
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))

function openEdit(): void {
  const row = redirectRow.value
  if (!row) {
    return
  }
  // Flush pending and take the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  const fresh = redirect.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearEditError()
  editValue.value = fresh.value
  editBaseline.value = openRowEdit<RedirectEditFields>({ value: fresh.value })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  redirect.controller.releaseFocus()
}

// Put a step of the helper into the dialog: the snapshot moves, and a value the
// step takes lands in the input.
function applyStep(step: RowEditStep<RedirectEditFields>): void {
  editBaseline.value = step.baseline
  if (step.take.value !== undefined) {
    editValue.value = step.take.value
  }
}

// The helper hands a step whenever the other side moved the address while the
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
  if (editBusy.value || live.value.gone || live.value.conflict) {
    return
  }
  if (!live.value.dirty) {
    closeEdit()

    return
  }
  if (await runEditAction(sendRedirectSet(editValue.value))) {
    closeEdit()
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
              {{ redirectRow.value }}
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
              :disabled="redirectRow?.source !== 'db' || resetAction.busy.value"
              data-id="hilos-oauth-redirect-reset"
              @click="resetRedirect"
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
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-oauth-redirect-edit-notice"
        />
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
        <ConflictActions
          :conflict="live.conflict"
          :disable-save="!live.dirty || editBusy || live.gone"
          :save-label="editSaveLabel"
          @save="submitEdit"
          @accept-mine="acceptMine"
          @accept-theirs="acceptTheirs"
        >
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
  </HilosAdminPage>
</template>
