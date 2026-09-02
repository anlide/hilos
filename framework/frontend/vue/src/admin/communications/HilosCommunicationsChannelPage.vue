<!-- HilosCommunicationsChannelPage — the framework Hilos channel-config page
(HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
the admin shell. The route {channelId} names the channel; the fields table is
global (one row per field of every channel), so the core headless filters it to
this channel client-side (createHilosChannelFields). Each editable field shows its
effective value and source and can be overridden (edit, in a modal) or reset to
its env/default; a secret is shown as set/not-set and never editable. A "Send test
notification" button exercises the real delivery path (HIL-201). Writes are tracked
actions (createHilosCommunicationsActions): the value redraws from the reactive
table's snapshot signal after the backend echo, never optimistically, and a
validation failure surfaces as a toast with the backend's domain phrase. Editing
happens in a modal — inline forms are forbidden (rules-and-violations.md section E).
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  HilosPages,
  type HilosChannelFieldRow,
  type HilosCommunicationsContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosCommunicationsChannelPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

// The route channel, as a core signal so the filtered fields re-derive on
// navigation without a re-fetch of the (shared) global fields table.
const channelSignal = computedSignal(
  () =>
    (router.currentRoute.get().params.channelId as string | undefined) ?? '',
)
const channel = useSignal(channelSignal)

const fields = createHilosChannelFields(props.context, channelSignal)
const rows = useSignal(fields.rows)
const { sendChannelSet, sendChannelReset, sendChannelTest } =
  createHilosCommunicationsActions(props.context)

onMounted(() => fields.start())
onUnmounted(() => fields.dispose())

// Showing the backend's own phrase on a rejected write is the driver's default
// since HIL-779; this page used to be the one screen that asked for it.
const testAction = useTrackedAction()
const resetAction = useTrackedAction()

function sendTest(): void {
  void testAction.run(sendChannelTest(channel.value))
}

function resetField(row: HilosChannelFieldRow): void {
  void resetAction.run(sendChannelReset(row.channel, row.field))
}

/** Map a field type to the value input it edits with. */
function inputType(type: string | undefined): 'text' | 'number' | 'checkbox' {
  if (type === 'boolean') {
    return 'checkbox'
  }
  if (type === 'integer' || type === 'float') {
    return 'number'
  }

  return 'text'
}

/** Human-readable effective value of a non-secret field. */
function displayValue(row: HilosChannelFieldRow): string {
  if (typeof row.value === 'boolean') {
    return row.value ? 'On' : 'Off'
  }

  return row.value === null || row.value === '' ? '—' : String(row.value)
}

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

// Edit dialog: one field's override value.
const editOpen = ref(false)
const editRow = ref<HilosChannelFieldRow | null>(null)
const editValue = ref('')
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editInputType = computed(() => inputType(editRow.value?.type))
const editStep = computed(() =>
  editRow.value?.type === 'float' ? 'any' : undefined,
)
const editValueBool = computed({
  get: () => editValue.value === '1',
  set: (on: boolean) => {
    editValue.value = on ? '1' : '0'
  },
})

function openEdit(row: HilosChannelFieldRow): void {
  clearEditError()
  editRow.value = row
  editValue.value =
    row.value === null || row.value === undefined ? '' : String(row.value)
  if (row.type === 'boolean') {
    editValue.value = row.value === true ? '1' : '0'
  }
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
}

/** Coerce the edited string to the field's typed value for the set action. */
function editedValue(row: HilosChannelFieldRow): boolean | number | string {
  if (row.type === 'boolean') {
    return editValue.value === '1'
  }
  if (row.type === 'integer' || row.type === 'float') {
    return Number(editValue.value)
  }

  return editValue.value
}

async function submitEdit(): Promise<void> {
  const row = editRow.value
  if (!row || editBusy.value) {
    return
  }
  if (
    await runEditAction(
      sendChannelSet(row.channel, row.field, editedValue(row)),
    )
  ) {
    closeEdit()
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.COMMUNICATIONS_CHANNEL">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <p class="mb-0 text-body-secondary">
        Channel <code>{{ channel }}</code>
      </p>
      <LoadingButton
        class="btn-outline-primary btn-sm"
        :loading="testAction.loading.value"
        :disabled="testAction.busy.value"
        data-id="hilos-channel-test"
        @click="sendTest"
      >
        Send test notification
      </LoadingButton>
    </div>

    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0">
        <caption class="visually-hidden">
          Channel configuration fields
        </caption>
        <thead>
          <tr>
            <th scope="col">Field</th>
            <th scope="col">Value</th>
            <th scope="col">Source</th>
            <th scope="col" class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.key"
            :data-id="`hilos-channel-field-${row.field}`"
          >
            <td>
              <div class="fw-semibold">{{ row.label }}</div>
              <code class="small text-body-secondary">{{ row.field }}</code>
            </td>
            <td>
              <span v-if="row.secret" class="text-body-secondary fst-italic">
                {{ row.valueSource === 'env' ? 'Set in env' : 'Not set' }}
              </span>
              <span v-else>{{ displayValue(row) }}</span>
            </td>
            <td>
              <span
                class="badge text-bg-secondary-subtle text-secondary-emphasis"
              >
                {{ SOURCE_LABEL[row.valueSource] ?? row.valueSource }}
              </span>
            </td>
            <td class="text-end">
              <div v-if="row.editable" class="d-flex gap-1 justify-content-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary"
                  title="Edit"
                  aria-label="Edit"
                  :data-id="`hilos-channel-field-edit-${row.field}`"
                  @click="openEdit(row)"
                >
                  <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  title="Reset to env/default"
                  aria-label="Reset to env/default"
                  :disabled="
                    row.valueSource !== 'settings' || resetAction.busy.value
                  "
                  :data-id="`hilos-channel-field-reset-${row.field}`"
                  @click="resetField(row)"
                >
                  <i
                    class="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  ></i>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="rows.length === 0">
            <td colspan="4" class="text-center text-muted py-4">
              No configurable fields for this channel.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <HilosModal
      v-model="editOpen"
      :title="editRow ? `Edit · ${editRow.label}` : 'Edit field'"
      @cancel="closeEdit"
    >
      <HilosActionError :action="editAction" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <div v-if="editInputType === 'checkbox'" class="form-check form-switch">
          <input
            id="hilos-channel-edit-value"
            v-model="editValueBool"
            type="checkbox"
            class="form-check-input"
            role="switch"
            data-id="hilos-channel-edit-value"
          />
          <label class="form-check-label" for="hilos-channel-edit-value">
            {{ editRow.label }}
          </label>
        </div>
        <template v-else>
          <label class="form-label" for="hilos-channel-edit-value">
            {{ editRow.label }}
          </label>
          <input
            id="hilos-channel-edit-value"
            v-model="editValue"
            :type="editInputType"
            :step="editStep"
            class="form-control"
            data-id="hilos-channel-edit-value"
          />
        </template>
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
          data-id="hilos-channel-edit-save"
          @click="submitEdit"
        >
          Save
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
