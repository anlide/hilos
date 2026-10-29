<!-- The channel Configuration tab keeps the fields table and its focused edit
and reset dialogs. The summary from the same page answer supplies the shared
channel head; values still change only on the backend echo. -->
<script setup lang="ts">
import { computedSignal, type Hideable, HilosPages } from '@hilos/core/base'
import {
  createHilosChannelFieldEdit,
  createHilosChannelFields,
  createHilosChannelSummary,
  createHilosCommunicationsActions,
  hilosChannelDisplayValue,
  type HilosChannelFieldRow,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosActionReason from '../../HilosActionReason.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import HilosCommunicationsChannelHead from './HilosCommunicationsChannelHead.vue'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosCommunicationsChannelConfigurationPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

// The route channel, as a core signal the fields table follows: navigating to
// another channel sets the table's channel filter again and asks the server for
// that channel's window.
const channelSignal = computedSignal(
  () =>
    (router.currentRoute.get().params.channelId as string | undefined) ?? '',
)
const summary = createHilosChannelSummary(props.context)
const fields = createHilosChannelFields(
  props.context,
  channelSignal,
  HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
)
const actions = createHilosCommunicationsActions(props.context)
const { sendChannelReset } = actions

onMounted(() => {
  fields.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  fields.dispose()
})

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

/** The source badge label: where the effective value comes from. */
const SOURCE_LABEL: Record<string, string> = {
  settings: 'Override',
  env: 'From env',
  default: 'Default',
}

// Edit dialog: one field's override value, as the core window has it; this
// view binds the input.
const editor = createHilosChannelFieldEdit(fields.controller, actions)
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
const editAction = useTrackedAction()
const {
  busy: editBusy,
  run: runEditAction,
  clearError: clearEditError,
} = editAction
const editInputType = computed(() => inputType(editRow.value?.type))
const editStep = computed(() =>
  editRow.value?.type === 'float' ? 'any' : undefined,
)
const editHidden = computed(() => editForm.value.hidden)
// A number input hands back a number, while the form and the wire hold text.
const editValue = computed({
  get: () => editForm.value.text,
  set: (typed: string | number) => editor.patchForm({ text: String(typed) }),
})
const editValueBool = computed({
  get: () => editForm.value.text === '1',
  set: (on: boolean) => editor.patchForm({ text: on ? '1' : '0' }),
})
const editTitle = computed(() =>
  editRow.value ? `Edit · ${editRow.value.label}` : 'Edit field',
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
// The live row the open reset dialog is about: the row the table holds in
// focus, which the server follows wherever it goes; undefined once the row is
// gone.
const liveRow = useSignal(fields.controller.focusedRow)

// Reset dialog: back to env/default, only on confirm. It reads the same live row
// the edit dialog does — one dialog is open at a time, and the focus is one.
const resetOpen = ref(false)
const resetRow = ref<HilosChannelFieldRow | null>(null)
const resetAction = useTrackedAction()
const {
  busy: resetBusy,
  run: runResetAction,
  clearError: clearResetError,
} = resetAction
const resetShown = computed(() => liveRow.value ?? resetRow.value)
const resetGone = computed(
  () => liveRow.value === undefined || liveRow.value.valueSource !== 'settings',
)

function openEdit(row: HilosChannelFieldRow): void {
  // The window takes the row into focus, so the dialog edits the latest
  // committed row and follows it from here; a row removed by someone else (now
  // a placeholder) declines to open.
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
// unchanged draft, or dispatches the tracked action and closes on its
// `::success` reply; a failure stays open with the entered value.
function submitEdit(): void {
  void editor.save(runEditAction)
}

/** Whether the field holds a value of its own in settings — the only one a reset removes. */
function canReset(row: HilosChannelFieldRow): boolean {
  return row.valueSource === 'settings'
}

/**
 * Why the field's reset is off, in the server's words, or null while it
 * applies: read only while the button is off, so the ? and the disabled reset
 * follow one current state of the row.
 */
function resetReason(row: HilosChannelFieldRow): Hideable<string | null> {
  return canReset(row) ? null : row.resetDisabledReason
}

function openReset(row: HilosChannelFieldRow): void {
  // Flush pending and take the row into focus; a row already removed by someone
  // else does not open a reset.
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
  if (await runResetAction(sendChannelReset(row.channel, row.field))) {
    closeReset()
  }
}
</script>

<template>
  <HilosCommunicationsChannelHead
    :page="HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION"
    :scopes="context.scopes"
    :summary="summary"
  >
    <HilosViewportTable :controller="fields.controller">
      <template #cell-field="{ row }">
        <div class="fw-semibold">{{ row.label }}</div>
        <code class="small text-body-secondary">{{ row.field }}</code>
      </template>
      <template #cell-value="{ row }">
        <span v-if="row.secret" class="text-body-secondary fst-italic">
          {{ row.valueSource === 'env' ? 'Set in env' : 'Not set' }}
        </span>
        <HilosHideable v-else :value="row.value">
          <template #default="{ value }">
            <span>{{ hilosChannelDisplayValue(value) }}</span>
          </template>
        </HilosHideable>
      </template>
      <template #cell-valueSource="{ row }">
        <span class="badge text-bg-secondary-subtle text-secondary-emphasis">
          {{ SOURCE_LABEL[row.valueSource] ?? row.valueSource }}
        </span>
      </template>
      <template #cell-actions="{ row }">
        <template v-if="row.editable">
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
          <HilosActionReason :reason="resetReason(row)" />
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            title="Reset to default"
            aria-label="Reset to default"
            :disabled="!canReset(row)"
            :data-id="`hilos-channel-field-reset-${row.field}`"
            @click="openReset(row)"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
          </button>
        </template>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="editOpen"
      :confirm-on-close="live.dirty"
      :busy="editSaving"
      @cancel="closeEdit"
    >
      <template #header>
        <ConflictHeader :title="editTitle" :conflict="live.conflict" />
      </template>
      <HilosActionError :action="editAction" details-title="Couldn't save" />
      <form v-if="editRow" @submit.prevent="submitEdit">
        <template v-if="editHidden">
          <div class="form-label">{{ editRow.label }}</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <div
            v-if="editInputType === 'checkbox'"
            class="form-check form-switch"
          >
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
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-channel-edit-notice"
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
              data-id="hilos-channel-edit-save"
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
        <dd class="col-8" data-id="hilos-channel-reset-now">
          <HilosHideable :value="resetShown.value">
            <template #default="{ value }">
              {{ hilosChannelDisplayValue(value) }}
            </template>
          </HilosHideable>
        </dd>
        <dt class="col-4">Back to</dt>
        <dd class="col-8 text-break" data-id="hilos-channel-reset-default">
          <HilosHideable :value="resetShown.defaultValue">
            <template #default="{ value }">
              {{ hilosChannelDisplayValue(value) }}
            </template>
          </HilosHideable>
          {{ ' ' }}
          <span
            class="badge text-bg-secondary-subtle text-secondary-emphasis ms-2"
          >
            {{ SOURCE_LABEL[resetShown.defaultValueSource] }}
          </span>
        </dd>
      </dl>
      <p
        v-if="resetGone"
        class="mb-0 mt-2 text-body-secondary"
        data-id="hilos-channel-reset-gone"
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
          data-id="hilos-channel-reset-confirm"
          @click="submitReset"
        >
          Reset
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosCommunicationsChannelHead>
</template>
