<!-- The Maintenance Circle tab: the existing live verifier table and its Add/Remove
modals. The core owns rows, actions and copy; this view binds its window and focus
to Circle and shares Maintenance's section head. -->
<script setup lang="ts">
import { HilosPages, isHiddenValue } from '@hilos/core/base'
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { computed, onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosEditNotice from '../../HilosEditNotice.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosMaintenanceContext
}>()

const circle = createHilosMaintenanceCircleTable(
  props.context,
  { openAdd: openCircleAdd },
  HilosPages.MAINTENANCE_CIRCLE,
)
const circleTable = circle.controller
// The row the remove dialog holds in focus, as it stands now — undefined once it is gone.
const focusedRow = useSignal(circleTable.focusedRow)

// Bind the table to Circle on mount, consuming its initial page window, and
// release its listeners and row focus on unmount.
onMounted(() => {
  circle.start()
})
onUnmounted(() => {
  circleTable.releaseFocus()
  circle.dispose()
})

const { sendMaintenanceCircleAdd, sendMaintenanceCircleRemove } =
  createHilosMaintenanceActions(props.context)

// The add dialog. It closes on the server's word only: a refusal keeps it open with
// what was typed, and the named row arrives over the live table, not from here.
const circleAddOpen = ref(false)
const circleAddIdentifier = ref('')
const circleAddAction = useTrackedAction()
const {
  busy: circleAddBusy,
  run: runCircleAddAction,
  clearError: clearCircleAddError,
} = circleAddAction

function openCircleAdd(): void {
  clearCircleAddError()
  circleAddIdentifier.value = ''
  circleAddOpen.value = true
}

function closeCircleAdd(): void {
  circleAddOpen.value = false
}

async function submitCircleAdd(): Promise<void> {
  if (circleAddBusy.value || circleAddIdentifier.value.trim() === '') {
    return
  }
  if (
    await runCircleAddAction(
      sendMaintenanceCircleAdd(circleAddIdentifier.value),
    )
  ) {
    closeCircleAdd()
  }
}

// The remove dialog. It closes on the server's word only, and the row leaves over the
// live table, not from here. The dialog holds its row in focus while open.
const circleRemoveOpen = ref(false)
const circleRemoveRow = ref<HilosMaintenanceCircleRow | null>(null)
const circleRemoveAction = useTrackedAction()
const {
  busy: circleRemoveBusy,
  run: runCircleRemoveAction,
  clearError: clearCircleRemoveError,
} = circleRemoveAction

// The live row the remove dialog is about; the row the dialog opened with stays on
// screen once it is gone.
const removeLive = computed(() =>
  circleRemoveRow.value ? focusedRow.value : undefined,
)
const removeShown = computed(() => removeLive.value ?? circleRemoveRow.value)
// Gone elsewhere. Our own removal in flight is not that: its echo makes the row a
// placeholder before the `::success` reply lands
// (TableViewportController.applyOwnDelta), and that placeholder is our doing.
const removeGone = computed(
  () =>
    circleRemoveRow.value !== null &&
    !circleRemoveBusy.value &&
    removeLive.value === undefined,
)

/**
 * What tells a circle row apart in its data-ids: the address, or the membership
 * id while the address is hidden — every hidden row would share one otherwise.
 *
 * @param row The circle row.
 */
function circleKey(row: HilosMaintenanceCircleRow): string {
  return isHiddenValue(row.identifier)
    ? `member-${row.memberId}`
    : row.identifier
}

function openCircleRemove(row: HilosMaintenanceCircleRow): void {
  // Flush pending and take the row into focus; a row already taken out by someone
  // else does not open the dialog.
  const fresh = circleTable.focusRow(String(row.memberId))
  if (!fresh) {
    return
  }
  clearCircleRemoveError()
  circleRemoveRow.value = fresh
  circleRemoveOpen.value = true
}

function closeCircleRemove(): void {
  circleRemoveOpen.value = false
  circleTable.releaseFocus()
}

async function submitCircleRemove(): Promise<void> {
  const row = circleRemoveRow.value
  if (!row || circleRemoveBusy.value || removeGone.value) {
    return
  }
  if (await runCircleRemoveAction(sendMaintenanceCircleRemove(row.memberId))) {
    closeCircleRemove()
  }
}
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MAINTENANCE_CIRCLE"
    head-kind="section"
    tabs-label="Maintenance pages"
  >
    <HilosViewportTable
      data-id="hilos-maintenance-circle-table"
      :controller="circleTable"
    >
      <template #cell-identifier="{ row }">
        <span :data-id="`hilos-maintenance-circle-row-${circleKey(row)}`">
          <HilosHideable :value="row.identifier" />
        </span>
      </template>
      <template #cell-online="{ row }">
        <span
          :class="row.online ? 'text-success' : 'text-body-secondary'"
          :data-id="`hilos-maintenance-circle-online-${circleKey(row)}`"
          >{{
            row.online
              ? HILOS_MAINTENANCE_CIRCLE_COPY.online
              : HILOS_MAINTENANCE_CIRCLE_COPY.offline
          }}</span
        >
      </template>
      <template #cell-actions="{ row }">
        <button
          type="button"
          class="btn btn-sm btn-outline-danger"
          :title="HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle"
          :aria-label="HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle"
          :data-id="`hilos-maintenance-circle-remove-${circleKey(row)}`"
          @click="openCircleRemove(row)"
        >
          <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
      </template>
    </HilosViewportTable>

    <HilosModal
      v-model="circleAddOpen"
      :title="HILOS_MAINTENANCE_CIRCLE_COPY.addTitle"
      :busy="circleAddBusy"
      @cancel="closeCircleAdd"
    >
      <HilosActionError
        :action="circleAddAction"
        :details-title="HILOS_MAINTENANCE_CIRCLE_COPY.addRefusalTitle"
      />
      <p class="mb-2 text-body-secondary">
        {{ HILOS_MAINTENANCE_CIRCLE_COPY.addLead }}
      </p>
      <label class="form-label" for="hilos-maintenance-circle-add-field">
        {{ HILOS_MAINTENANCE_CIRCLE_COPY.addField }}
      </label>
      <input
        id="hilos-maintenance-circle-add-field"
        v-model="circleAddIdentifier"
        type="text"
        class="form-control"
        autocomplete="off"
        :placeholder="HILOS_MAINTENANCE_CIRCLE_COPY.addPlaceholder"
        :disabled="circleAddBusy"
        data-id="hilos-maintenance-circle-add-field"
        data-autofocus
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="circleAddBusy"
          data-id="hilos-maintenance-circle-add-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-primary"
          :loading="circleAddBusy"
          :disabled="circleAddIdentifier.trim() === ''"
          data-id="hilos-maintenance-circle-add-confirm"
          @click="submitCircleAdd"
        >
          {{ HILOS_MAINTENANCE_CIRCLE_COPY.addConfirm }}
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      v-model="circleRemoveOpen"
      :title="HILOS_MAINTENANCE_CIRCLE_COPY.removeTitle"
      :busy="circleRemoveBusy"
      initial-focus="dialog"
      @cancel="closeCircleRemove"
    >
      <HilosActionError
        :action="circleRemoveAction"
        :details-title="HILOS_MAINTENANCE_CIRCLE_COPY.removeRefusalTitle"
      />
      <p v-if="removeShown" class="mb-0">
        {{ HILOS_MAINTENANCE_CIRCLE_COPY.removeAskBefore }}
        <HilosHiddenMark v-if="isHiddenValue(removeShown.identifier)" />
        <code v-else>{{ removeShown.identifier }}</code>
        {{ HILOS_MAINTENANCE_CIRCLE_COPY.removeAskAfter }}
      </p>
      <p class="mb-0 mt-2 text-body-secondary">
        {{ HILOS_MAINTENANCE_CIRCLE_COPY.removeNote }}
      </p>
      <HilosEditNotice
        :kind="removeGone ? 'deleted' : null"
        :text="HILOS_MAINTENANCE_CIRCLE_COPY.removeGone"
        data-id="hilos-maintenance-circle-remove-notice"
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="circleRemoveBusy"
          data-id="hilos-maintenance-circle-remove-cancel"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="circleRemoveBusy"
          :disabled="removeGone"
          data-id="hilos-maintenance-circle-remove-confirm"
          @click="submitCircleRemove"
        >
          {{
            removeGone
              ? HILOS_MAINTENANCE_CIRCLE_COPY.removeGoneConfirm
              : HILOS_MAINTENANCE_CIRCLE_COPY.removeConfirm
          }}
        </LoadingButton>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
