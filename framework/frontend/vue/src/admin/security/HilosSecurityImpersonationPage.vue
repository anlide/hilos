<!-- HilosSecurityImpersonationPage — the framework impersonation settings page
(HilosPages.SECURITY_IMPERSONATION, HIL-1170): the seven settings of
impersonation, one row each — whether it exists in the product, what may be done
inside someone else's account, whether its sign-in may be touched, whether the
administrator carries their own rights in, and whom it may take over. The six
yes-or-no rows carry a switch; the scope shows its value in words and a pencil
that opens a modal (the modal-only editing rule).
The table, the row view-model, the words and the two writes are the core
headless's (createHilosSecurityImpersonationTable /
createHilosSecurityImpersonationActions); this view owns only the markup, so a
project mounts it by passing its HilosImpersonationContext.
A switch is a tracked action, as on the sign-in methods page: a spinner on its
own row while it flies, the other switches disabled, no toast on success, and
the switch moves only when the table's row does; a refusal is the action's
toast. The scope's modal is the core row-edit session over the focused row
(createHilosImpersonationScopeEdit, rowEditSession.ts, conflict-resolution.md),
saying what happened elsewhere on one line of room held in advance
(HilosEditNotice), and this view binds the choice; Save closes it on the server's answer, whose
sentence is the toast, and a refusal stays in it. A viewer of the admin view
mode finds the switches and Save disabled by the SDK's own controls (HIL-1261).
The screen is built from text: the mockup still draws these rows on the
two-factor page (D-143). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosImpersonationScopeEdit,
  createHilosSecurityImpersonationActions,
  createHilosSecurityImpersonationTable,
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_IMPERSONATION_SCOPE_HINT,
  HILOS_IMPERSONATION_SCOPE_VALUES,
  HILOS_IMPERSONATION_SETTING_COPY,
  hilosImpersonationScopeOf,
  HilosPages,
  isHilosImpersonationSwitch,
  type HilosImpersonationContext,
  type HilosImpersonationScope,
  type HilosImpersonationSettingRow,
} from '@hilos/core'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import ConflictActions from '../../ConflictActions.vue'
import ConflictHeader from '../../ConflictHeader.vue'
import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosImpersonationContext
}>()

const settings = createHilosSecurityImpersonationTable(props.context)
const actions = createHilosSecurityImpersonationActions(props.context)
const { sendSwitchSet } = actions

onMounted(() => {
  settings.start()
  editor.start()
})
onUnmounted(() => {
  editor.dispose()
  settings.dispose()
})

/**
 * The name of a setting on the screen.
 *
 * @param row The row of the setting.
 */
function labelOf(row: HilosImpersonationSettingRow): string {
  return HILOS_IMPERSONATION_SETTING_COPY[row.rowKey]?.label ?? row.rowKey
}

// One tracked runner for every switch: a single in-flight guard across rows is
// enough, and the busy flag disables every switch while one write is settling.
const { busy: switchBusy, run: runSwitch } = useTrackedAction()
const pendingSwitchKey = ref<string | null>(null)

// Dispatch the switch as a tracked action. Nothing is set optimistically: the
// switch follows the row, which moves when the setting is written.
async function toggle(
  row: HilosImpersonationSettingRow,
  next: boolean,
): Promise<void> {
  pendingSwitchKey.value = row.rowKey
  try {
    await runSwitch(sendSwitchSet(row.rowKey, next))
  } finally {
    pendingSwitchKey.value = null
  }
}

// The scope's modal: the choice as made until Save, as the core window has it;
// this view binds the radio group.
const editor = createHilosImpersonationScopeEdit(settings.controller, actions)
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
const editScope = computed({
  get: () => editForm.value.scope,
  set: (scope: HilosImpersonationScope) => editor.patchForm({ scope }),
})
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

function openEdit(row: HilosImpersonationSettingRow): void {
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
// unchanged choice, or dispatches the tracked action and closes on its
// `::success` reply; a refusal stays in the modal.
function submitEdit(): void {
  void editor.save(runEdit)
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_IMPERSONATION">
    <HilosViewportTable
      :controller="settings.controller"
      data-id="hilos-impersonation-table"
    >
      <template #cell-rowKey="{ row }">
        <div class="fw-semibold">{{ labelOf(row) }}</div>
        <div class="small text-body-secondary">
          {{ HILOS_IMPERSONATION_SETTING_COPY[row.rowKey]?.hint }}
        </div>
      </template>
      <template #cell-value="{ row }">
        <HilosHideable
          v-if="isHilosImpersonationSwitch(row.rowKey)"
          :value="row.enabled"
        >
          <template #default="{ value }">
            <HilosSwitch
              class="mb-0"
              :checked="value"
              :busy="pendingSwitchKey === row.rowKey"
              :disabled="switchBusy"
              :aria-label="labelOf(row)"
              :data-id="`hilos-impersonation-switch-${row.rowKey}`"
              @toggle="toggle(row, $event)"
            />
          </template>
        </HilosHideable>
        <span v-else data-id="hilos-impersonation-scope-value">
          <HilosHideable :value="row.value">
            <template #default>
              {{
                HILOS_IMPERSONATION_SCOPE_COPY[hilosImpersonationScopeOf(row)]
              }}
            </template>
          </HilosHideable>
        </span>
      </template>
      <template #cell-actions="{ row }">
        <button
          v-if="!isHilosImpersonationSwitch(row.rowKey)"
          type="button"
          class="btn btn-sm btn-outline-primary"
          title="Edit"
          :aria-label="`Edit ${labelOf(row)}`"
          data-id="hilos-impersonation-scope-edit"
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
          <div class="form-label fs-6">{{ labelOf(editRow) }}</div>
          <HilosHiddenMark />
        </template>
        <template v-else>
          <fieldset aria-describedby="hilos-impersonation-scope-hint">
            <legend class="form-label fs-6">{{ labelOf(editRow) }}</legend>
            <div
              v-for="value in HILOS_IMPERSONATION_SCOPE_VALUES"
              :key="value"
              class="form-check"
            >
              <input
                :id="`hilos-impersonation-scope-${value}-field`"
                v-model="editScope"
                class="form-check-input"
                type="radio"
                name="hilos-impersonation-scope"
                :value="value"
                :data-id="`hilos-impersonation-scope-${value}`"
                data-autofocus
              />
              <label
                class="form-check-label"
                :for="`hilos-impersonation-scope-${value}-field`"
                >{{ HILOS_IMPERSONATION_SCOPE_COPY[value] }}</label
              >
            </div>
          </fieldset>
          <p id="hilos-impersonation-scope-hint" class="form-text mb-0">
            {{ HILOS_IMPERSONATION_SCOPE_HINT }}
          </p>
        </template>
        <HilosEditNotice
          :kind="editNotice"
          :text="editNoticeText"
          data-id="hilos-impersonation-scope-notice"
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
              data-id="hilos-impersonation-scope-save"
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
