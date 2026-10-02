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
toast. The scope's modal is the two-factor page's: it holds its row in focus
and merges against it through the shared row-edit helper (rowEdit.ts,
conflict-resolution.md), saying what happened elsewhere on one line of room
held in advance (HilosEditNotice); Save closes it on the server's answer, whose
sentence is the toast, and a refusal stays in it. A viewer of the admin view
mode finds the switches and Save disabled by the SDK's own controls (HIL-1261).
The screen is built from text: the mockup still draws these rows on the
two-factor page (D-143). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecurityImpersonationActions,
  createHilosSecurityImpersonationTable,
  HIDDEN_VALUE,
  HILOS_IMPERSONATION_SCOPE_COPY,
  HILOS_IMPERSONATION_SCOPE_HINT,
  HILOS_IMPERSONATION_SCOPE_VALUES,
  HILOS_IMPERSONATION_SETTING_COPY,
  hiddenAsWord,
  hilosImpersonationScopeOf,
  HilosPages,
  isHilosImpersonationSwitch,
  isHiddenValue,
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type Hideable,
  type HilosImpersonationContext,
  type HilosImpersonationScope,
  type HilosImpersonationSettingRow,
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
const { sendSwitchSet, sendScopeSet } = createHilosSecurityImpersonationActions(
  props.context,
)

onMounted(() => settings.start())
onUnmounted(() => settings.dispose())

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

/** The one field the scope's modal edits. */
interface ScopeEditFields {
  scope: Hideable<HilosImpersonationScope>
}

/**
 * The one line the modal says about the other side, for what the helper found;
 * the value in words, the way its cell says it.
 *
 * @param kind What the helper found, or null.
 * @param liveRow The scope's row as the server holds it now.
 */
function noticeText(
  kind: RowEditNoticeKind | null,
  liveRow: HilosImpersonationSettingRow | undefined,
): string {
  switch (kind) {
    case 'deleted':
      return 'Deleted elsewhere — your choice stays on screen.'
    case 'conflict':
      if (!liveRow) {
        return ''
      }
      return `Changed elsewhere to "${isHiddenValue(liveRow.value) ? hiddenAsWord(liveRow.value) : HILOS_IMPERSONATION_SCOPE_COPY[hilosImpersonationScopeOf(liveRow)]}".`
    case 'updated':
      return 'Updated just now'
    default:
      return ''
  }
}

// The scope's modal: the choice as made until Save.
const editOpen = ref(false)
const editRow = ref<HilosImpersonationSettingRow | null>(null)
const editScope = ref<HilosImpersonationScope>('act')
const editBaseline = ref<RowEditBaseline<ScopeEditFields>>(
  openRowEdit<ScopeEditFields>({ scope: 'act' }),
)
const editHidden = computed(() =>
  isHiddenValue(editBaseline.value.values.scope),
)
const editAction = useTrackedAction()
const {
  loading: editLoading,
  busy: editBusy,
  run: runEdit,
  clearError: clearEditError,
} = editAction

// The live row the open modal is about: the row the table holds in focus, which
// the server follows wherever it goes; undefined once the row is gone.
const liveRow = useSignal(settings.controller.focusedRow)
const live = computed(() =>
  resolveRowEdit(
    liveRow.value
      ? {
          scope: isHiddenValue(liveRow.value.value)
            ? HIDDEN_VALUE
            : hilosImpersonationScopeOf(liveRow.value),
        }
      : undefined,
    editBaseline.value,
    { scope: editHidden.value ? HIDDEN_VALUE : editScope.value },
  ),
)
const editNotice = computed(() => live.value.notice?.kind ?? null)
const editNoticeText = computed(() =>
  noticeText(editNotice.value, liveRow.value),
)
const editSaveLabel = computed(() => (live.value.gone ? 'Deleted' : 'Save'))
const editTitle = computed(() =>
  editRow.value ? labelOf(editRow.value) : 'Edit setting',
)

function openEdit(row: HilosImpersonationSettingRow): void {
  // Flush pending and take the row into focus, so the modal edits the latest
  // committed row and follows it from here; a row that is gone declines to open.
  const fresh = settings.controller.focusRow(row.rowKey)
  if (!fresh) {
    return
  }
  clearEditError()
  const freshHidden = isHiddenValue(fresh.value)
  const scope = hilosImpersonationScopeOf(fresh)
  editRow.value = fresh
  if (!freshHidden) {
    editScope.value = scope
  }
  editBaseline.value = openRowEdit<ScopeEditFields>({
    scope: freshHidden ? HIDDEN_VALUE : scope,
  })
  editOpen.value = true
}

function closeEdit(): void {
  editOpen.value = false
  settings.controller.releaseFocus()
}

// Put a step of the helper into the modal: the snapshot moves, and a value the
// step takes lands in the choice.
function applyStep(step: RowEditStep<ScopeEditFields>): void {
  editBaseline.value = step.baseline
  const taken = step.take.scope
  if (taken !== undefined && !isHiddenValue(taken)) {
    editScope.value = taken
  }
}

// The helper hands a step whenever the other side moved the value while the
// person left it alone, or both arrived at the same one; the modal applies it
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
  if (
    editRow.value === null ||
    editBusy.value ||
    live.value.gone ||
    live.value.conflict
  ) {
    return
  }
  if (!live.value.dirty || editHidden.value) {
    closeEdit()

    return
  }
  if (await runEdit(sendScopeSet(editScope.value))) {
    closeEdit()
  }
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
