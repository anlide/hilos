<!-- HilosCommunicationsChannelPage — the framework Hilos channel-config page
(HilosPages.COMMUNICATIONS_CHANNEL): one delivery channel's config fields inside
the admin shell. The route {channelId} names the channel; the fields table is
global (one row per field of every channel), so the core headless presets its
channel filter from the route and the server narrows the window to this channel
(createHilosChannelFields) — the frame around the rows, empty state included, is
what that table declares. Each editable field shows its effective value and source
and can be overridden (edit, in a modal) or reset to its env/default; a secret is
shown as set/not-set and never editable. A "Send test notification" button
exercises the real delivery path (HIL-201). Writes are tracked actions
(createHilosCommunicationsActions): the value redraws from the reactive table's
snapshot signal after the backend echo, never optimistically, and a validation
failure surfaces as a toast with the backend's domain phrase. Editing happens in a
modal — inline forms are forbidden (rules-and-violations.md section E) — and the
modal merges against the live row through the shared row-edit helper (rowEdit.ts,
conflict-resolution.md), saying what happened elsewhere on one line of room held
in advance (HilosEditNotice). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  HilosPages,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type HilosChannelFieldRow,
  type HilosCommunicationsContext,
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
  context: HilosCommunicationsContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosCommunicationsChannelPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

// The route channel, as a core signal the fields table follows: navigating to
// another channel sets the table's channel filter again and asks the server for
// that channel's window.
const channelSignal = computedSignal(
  () =>
    (router.currentRoute.get().params.channelId as string | undefined) ?? '',
)
const channel = useSignal(channelSignal)

const fields = createHilosChannelFields(props.context, channelSignal)
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

/** The one field the dialog edits: the field's typed value. */
interface ChannelEditFields {
  value: boolean | number | string | null
}

/**
 * The text the dialog's input shows for a typed value: a switch reads '1' /
 * '0', an empty value reads as nothing, anything else as itself.
 */
function formText(
  type: string,
  value: boolean | number | string | null,
): string {
  if (type === 'boolean') {
    return value === true ? '1' : '0'
  }

  return value === null ? '' : String(value)
}

/** The one line the dialog says about the other side, for what the helper found. */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosChannelFieldRow | undefined,
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

// Edit dialog: one field's override value.
const editOpen = ref(false)
const editRow = ref<HilosChannelFieldRow | null>(null)
const editBaseline = ref<RowEditBaseline<ChannelEditFields>>(
  openRowEdit<ChannelEditFields>({ value: null }),
)
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
const editTitle = computed(() =>
  editRow.value ? `Edit · ${editRow.value.label}` : 'Edit field',
)

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

// The live row the open dialog is about: the row the table holds in focus, which
// the server follows wherever it goes; undefined once the row is gone.
const liveRow = useSignal(fields.controller.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value ? { value: liveRow.value.value } : undefined,
    editBaseline.value,
    { value: editRow.value ? editedValue(editRow.value) : null },
  ),
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() =>
  noticeText(editNotice.value, liveRow.value),
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))

function openEdit(row: HilosChannelFieldRow): void {
  // Flush pending and take the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row removed by someone else (now a
  // placeholder) declines to open.
  const fresh = fields.controller.focusRow(row.key)
  if (!fresh) {
    return
  }
  clearEditError()
  editRow.value = fresh
  editValue.value = formText(fresh.type, fresh.value)
  editBaseline.value = openRowEdit<ChannelEditFields>({ value: fresh.value })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  fields.controller.releaseFocus()
}

// Put a step of the helper into the dialog: the snapshot moves, and a value the
// step takes lands in the input the way the dialog opened with it.
function applyStep(step: RowEditStep<ChannelEditFields>): void {
  const row = editRow.value
  if (!row) {
    return
  }
  editBaseline.value = step.baseline
  const taken = step.take.value
  if (taken !== undefined) {
    editValue.value = formText(row.type, taken)
  }
}

// The helper hands a step whenever the other side moved a field the person
// left alone, or both arrived at the same value; the dialog applies it at once.
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
  if (!row || editBusy.value || live.value.gone) {
    return
  }
  if (!live.value.dirty) {
    closeEdit()

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

    <HilosViewportTable :controller="fields.controller">
      <template #cell-field="{ row }">
        <div class="fw-semibold">{{ row.label }}</div>
        <code class="small text-body-secondary">{{ row.field }}</code>
      </template>
      <template #cell-value="{ row }">
        <span v-if="row.secret" class="text-body-secondary fst-italic">
          {{ row.valueSource === 'env' ? 'Set in env' : 'Not set' }}
        </span>
        <span v-else>{{ displayValue(row) }}</span>
      </template>
      <template #cell-valueSource="{ row }">
        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
          {{ SOURCE_LABEL[row.valueSource] ?? row.valueSource }}
        </span>
      </template>
      <template #cell-actions="{ row }">
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
            :disabled="row.valueSource !== 'settings' || resetAction.busy.value"
            :data-id="`hilos-channel-field-reset-${row.field}`"
            @click="resetField(row)"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
          </button>
        </div>
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
            data-autofocus
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
            data-autofocus
          />
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-channel-edit-notice"
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
          :mergeable="false"
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
              data-id="hilos-channel-edit-save"
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
