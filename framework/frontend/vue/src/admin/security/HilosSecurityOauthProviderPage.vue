<!-- HilosSecurityOauthProviderPage — the framework Hilos OAuth provider page
(HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): one provider's fields inside the
admin shell. The route {providerId} names the provider; the fields and providers
tables are global, so the core headless presets their provider filter from the
route and the server narrows both windows to it (createHilosOAuthProviderFields /
createHilosOAuthProviderSummary). A provider the project does not declare narrows
them to nothing, and the page says "provider not found". Each field shows its
effective value and where it comes from and can be edited or reset to env / the
recipe; the client secret is write-only — shown as set / not set, never read back,
and its dialog always opens empty: it replaces, it does not show. The provider's
recipe is shown as reference, without actions. Writes are tracked actions
(createHilosSecurityOauthActions): the value redraws from the reactive table after
the backend echo, never optimistically, and a refusal surfaces with the backend's
domain phrase. Editing happens in a modal — inline forms are forbidden
(rules-and-violations.md section E). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosOAuthProviderFields,
  createHilosOAuthProviderSummary,
  createHilosSecurityOauthActions,
  HilosPages,
  type HilosOAuthFieldRow,
  type HilosSecurityOauthContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
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

const resetAction = useTrackedAction()

function resetField(row: HilosOAuthFieldRow): void {
  void resetAction.run(sendProviderReset(row.providerKey, row.field))
}

// Edit dialog: one field of the provider. The secret's dialog always opens empty.
const editOpen = ref(false)
const editRow = ref<HilosOAuthFieldRow | null>(null)
const editValue = ref('')
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction

function openEdit(row: HilosOAuthFieldRow): void {
  clearEditError()
  editRow.value = row
  editValue.value = row.secret ? '' : (row.value ?? '')
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  // The secret typed into the dialog is not kept once it is closed.
  editValue.value = ''
}

async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value) {
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
            :disabled="row.source !== 'db' || resetAction.busy.value"
            :data-id="`hilos-oauth-field-reset-${row.field}`"
            @click="resetField(row)"
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
      :title="
        editRow
          ? `${editRow.secret ? 'Replace' : 'Edit'} · ${editRow.label}`
          : 'Edit field'
      "
      @cancel="closeEdit"
    >
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
          :disabled="editBusy"
          data-id="hilos-oauth-field-save"
          @click="submitEdit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
