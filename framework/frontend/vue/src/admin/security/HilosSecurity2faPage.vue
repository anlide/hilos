<!-- HilosSecurity2faPage — the framework two-step verification admin page
(HilosPages.SECURITY_2FA, HIL-494): the eight second-factor settings, one row
each — whether the second factor is on at all and whether turning it off forgets
the trusted browsers (HIL-1303), then who must use it, the days a device is
trusted, the size of a set of backup codes, and the removal wait with its
bounds. The two first rows carry a switch, as on the Impersonation tab; each
other row shows its value in words and a pencil that opens a modal (the
modal-only editing rule), and a value a setting's rule refuses stays in the
modal with the refusal above it.
A switch is a tracked action: a spinner on its own row while it flies, the
other switches disabled, no toast on success, and the switch moves only when
the table's row does; a refusal is the action's toast. The first switch, while
on, does not move at all: it opens the window that turns the factor off — the
operation's "Confirm it's you" first, then what turning it off does and "Turn
off" (createHilosTwoFactorDisable, in the order of the account card's windows).
While the factor is off every row below the first is dimmed, its pencil or its
switch locked (the table's locked).
The table, the row view-model, the edit round-trip, the window and the words
are the core headless's (createHilosSecurityTwoFactorTable /
createHilosSecurityTwoFactorActions); this view owns only the markup, so a
project mounts it by passing its HilosTwoFactorContext. The modal is the core
row-edit session over the focused row (createHilosTwoFactorSettingEdit,
rowEditSession.ts, conflict-resolution.md), saying what happened elsewhere on
one line of room held in advance (HilosEditNotice); this view binds the input.
The screen is built from text: the mockup's node is a debt (D-115, and for the
switches, the dimmed rows and the window D-234). Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecurityTwoFactorActions,
  createHilosSecurityTwoFactorTable,
  createHilosTwoFactorDisable,
  createHilosTwoFactorSettingEdit,
  describeHilosSecondFactorSetting,
  HILOS_SECOND_FACTOR_REQUIRED_COPY,
  HILOS_SECOND_FACTOR_REQUIRED_VALUES,
  HILOS_SECOND_FACTOR_SETTING_COPY,
  HILOS_TWO_FACTOR_DISABLE_COPY as DISABLE_COPY,
  HilosSecondFactorSettingKey,
  isHilosSecondFactorSwitch,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
} from '@hilos/core/admin/security'
import { focusInitial, HILOS_STEP_UP_COPY, HilosPages } from '@hilos/core/base'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import HilosStepUpStep from '../../auth/HilosStepUpStep.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}>()

const settings = createHilosSecurityTwoFactorTable(props.context)
const actions = createHilosSecurityTwoFactorActions(props.context)
const locked = useSignal(settings.locked)

onMounted(() => {
  settings.start()
  editor.start()
  disable.start()
})
onUnmounted(() => {
  disable.dispose()
  editor.dispose()
  settings.dispose()
})

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosTwoFactorSettingRow): string {
  return HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

/**
 * Whether a row is locked: below the first while the factor is off.
 *
 * @param row The row of the setting.
 */
function isLocked(row: HilosTwoFactorSettingRow): boolean {
  return locked.value.includes(row.rowKey)
}

// One tracked runner for both switches: a single in-flight guard is enough,
// and the busy flag disables the other switch while one write is settling.
const { busy: switchBusy, run: runSwitch } = useTrackedAction()
const pendingSwitchKey = ref<string | null>(null)

// The window that turns the factor off, over the table; this view draws it.
const disable = createHilosTwoFactorDisable(props.context, settings.controller)
const disableStep = useSignal(disable.step)
const disableBusy = useSignal(disable.busy)
const disableRefusal = useSignal(disable.refusal)
const stepUpBusy = useSignal(disable.stepUp.busy)
const stepUpRefusal = useSignal(disable.stepUp.refusal)
const consequences = useSignal(disable.consequences)
const disableOpen = computed({
  get: () =>
    disableStep.value === 'step-up' ||
    disableStep.value === 'consequences' ||
    disableStep.value === 'refused',
  set: (next: boolean) => {
    if (!next) disable.close()
  },
})
const disableBody = ref<HTMLElement | null>(null)

// The step the window moves to takes the focus once it is drawn: the field that
// held it is gone, and the modal places focus only when it opens.
watch(disableStep, () => {
  void nextTick(() => {
    const dialog = disableBody.value?.closest<HTMLElement>('[role="dialog"]')
    if (dialog) {
      focusInitial(dialog)
    }
  })
})

// Turning the factor off opens its window and leaves the switch where it is;
// anything else is the switch's own tracked write. Nothing is set
// optimistically: the switch follows the row, which moves when the setting is
// written.
async function toggle(
  row: HilosTwoFactorSettingRow,
  next: boolean,
): Promise<void> {
  if (row.rowKey === HilosSecondFactorSettingKey.enabled && !next) {
    void disable.open()

    return
  }
  pendingSwitchKey.value = row.rowKey
  try {
    await runSwitch(actions.switchSet(row.rowKey, next))
  } finally {
    pendingSwitchKey.value = null
  }
}

// The edit modal: one setting at a time, as the core window has it; this view
// binds the list or the number input.
const editor = createHilosTwoFactorSettingEdit(settings.controller, actions)
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
const canSave = useSignal(editor.canSave)
const editHidden = computed(() => editForm.value.hidden)
// The number input's model: v-model on a number input hands back a number, and
// the value is text everywhere else (the form, the session, the wire).
const editValue = computed({
  get: () => editForm.value.text,
  set: (typed: string | number) => editor.patchForm({ text: String(typed) }),
})
const editValueText = editValue
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEdit,
  clearError: clearEditError,
} = editAction
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editTitle = computed(() =>
  editRow.value ? labelOf(editRow.value) : 'Edit setting',
)

function openEdit(row: HilosTwoFactorSettingRow): void {
  // The window takes the row into focus, so the modal edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  clearEditError()
  editor.open(row.rowKey)
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
// `::success` reply; a refusal stays in the modal.
function submitEdit(): void {
  void editor.save(runEdit)
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_2FA">
    <HilosViewportTable :controller="settings.controller">
      <template #cell-rowKey="{ row }">
        <div :class="{ 'opacity-50': isLocked(row) }">
          <div class="fw-semibold">{{ labelOf(row) }}</div>
          <div class="small text-body-secondary">
            {{ HILOS_SECOND_FACTOR_SETTING_COPY[row.rowKey]?.hint }}
          </div>
        </div>
      </template>
      <template #cell-value="{ row }">
        <HilosHideable
          v-if="isHilosSecondFactorSwitch(row.rowKey)"
          :value="row.enabled"
        >
          <template #default="{ value }">
            <HilosSwitch
              class="mb-0"
              :checked="value"
              :busy="
                pendingSwitchKey === row.rowKey ||
                (row.rowKey === HilosSecondFactorSettingKey.enabled &&
                  disableStep === 'opening')
              "
              :disabled="switchBusy || isLocked(row)"
              :aria-label="labelOf(row)"
              :data-id="`hilos-2fa-switch-${row.rowKey}`"
              @toggle="toggle(row, $event)"
            />
          </template>
        </HilosHideable>
        <span
          v-else
          :class="{ 'opacity-50': isLocked(row) }"
          :data-id="`hilos-2fa-value-${row.rowKey}`"
        >
          <HilosHideable :value="row.value">
            <template #default="{ value }">
              {{ describeHilosSecondFactorSetting(row.rowKey, value) }}
            </template>
          </HilosHideable>
        </span>
      </template>
      <template #cell-actions="{ row }">
        <button
          v-if="!isHilosSecondFactorSwitch(row.rowKey)"
          type="button"
          class="btn btn-sm btn-outline-primary"
          :title="`Edit ${labelOf(row)}`"
          :aria-label="`Edit ${labelOf(row)}`"
          :disabled="isLocked(row)"
          :data-id="`hilos-2fa-edit-${row.rowKey}`"
          @click="openEdit(row)"
        >
          <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
      </template>
    </HilosViewportTable>

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
        <template v-if="editHidden">
          <div class="form-label">{{ labelOf(editRow) }}</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <label class="form-label" for="hilos-2fa-input">
            {{ labelOf(editRow) }}
          </label>
          <select
            v-if="editRow.rowKey === HilosSecondFactorSettingKey.required"
            id="hilos-2fa-input"
            v-model="editValue"
            class="form-select"
            data-id="hilos-2fa-input"
            data-autofocus
          >
            <option
              v-for="value in HILOS_SECOND_FACTOR_REQUIRED_VALUES"
              :key="value"
              :value="value"
            >
              {{ HILOS_SECOND_FACTOR_REQUIRED_COPY[value] }}
            </option>
          </select>
          <input
            v-else
            id="hilos-2fa-input"
            v-model="editValueText"
            type="number"
            inputmode="numeric"
            class="form-control"
            data-id="hilos-2fa-input"
            data-autofocus
          />
        </template>
        <p class="form-text mb-0">
          {{ HILOS_SECOND_FACTOR_SETTING_COPY[editRow.rowKey]?.hint }}
          Default:
          <HilosHideable :value="editRow.defaultValue">
            <template #default="{ value }">
              {{ describeHilosSecondFactorSetting(editRow.rowKey, value) }}
            </template> </HilosHideable
          >.
        </p>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-2fa-edit-notice"
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
              data-id="hilos-2fa-save"
              @click="onSave"
            >
              {{ editSaveLabel }}
            </LoadingButton>
          </template>
        </ConflictActions>
      </template>
    </HilosModal>

    <HilosModal
      v-model="disableOpen"
      :title="
        disableStep === 'step-up'
          ? HILOS_STEP_UP_COPY.title
          : DISABLE_COPY.title
      "
      initial-focus="inner"
      :busy="disableBusy"
      @cancel="disable.close()"
    >
      <div ref="disableBody" data-id="hilos-2fa-disable-window">
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{ disableStep === 'step-up' ? stepUpRefusal : disableRefusal }}
        </div>
        <form
          v-if="disableStep === 'step-up'"
          id="hilos-2fa-disable-proof"
          data-id="hilos-2fa-disable-step-up"
          @submit.prevent="disable.confirmStepUp()"
        >
          <HilosStepUpStep :controller="disable.stepUp" />
        </form>
        <template v-else>
          <template v-if="disableStep === 'consequences'">
            <p>{{ DISABLE_COPY.intro }}</p>
            <ul data-id="hilos-2fa-disable-consequences">
              <li v-for="line in consequences" :key="line">{{ line }}</li>
            </ul>
          </template>
          <HilosFormError
            :message="disableRefusal"
            data-id="hilos-2fa-disable-refusal"
          />
        </template>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="disableBusy"
          data-id="hilos-2fa-disable-cancel"
          @click="requestClose"
        >
          {{ DISABLE_COPY.cancel }}
        </button>
        <LoadingButton
          v-if="disableStep === 'step-up'"
          class="btn-primary"
          type="submit"
          form="hilos-2fa-disable-proof"
          :loading="stepUpBusy"
          data-id="hilos-2fa-disable-step-up-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
        <LoadingButton
          v-else-if="disableStep === 'consequences'"
          class="btn-danger"
          :loading="disableBusy"
          :disabled="disableBusy"
          data-id="hilos-2fa-disable-confirm"
          @click="disable.confirm()"
          >{{ DISABLE_COPY.confirm }}</LoadingButton
        >
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
