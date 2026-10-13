<!-- HilosMaintenancePage — the framework Hilos maintenance section
(HilosPages.MAINTENANCE): the live protected-mode state and verifier circle inside the
admin shell. Manual entry is confirmed here and waits for its own tracked answer,
independently of the live state frame. The circle says who will check the system
after a freeze. A verifier is named here in a dialog (HIL-1120) and taken out here
too, from the row's remove button (HIL-1121); the dialog confirming a removal holds
its row in focus, so a row taken out in another tab is said to be gone before anybody
presses Remove. The list is live, and so is the online mark on it: a named person
opening or closing a tab re-draws that person's row without a reload, and a named
person arrives as a row the same way. All table logic, the row view-model, the
actions, and the block's words — the column labels and the dialogs' included — are
the core headless's (createHilosMaintenanceCircleTable,
createHilosMaintenanceActions, HILOS_MAINTENANCE_CIRCLE_COPY); this view owns only the
markup, so a project mounts it by passing its HilosMaintenanceContext. Bootstrap
classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  ActionError,
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  createHilosMaintenanceState,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
  HilosPages,
  hilosMaintenanceCard,
  hilosMaintenancePosition,
  isHiddenValue,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
} from '@hilos/core'
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

const stateHandle = createHilosMaintenanceState(props.context)
const maintenanceState = useSignal(stateHandle.state)
const nowMs = ref(Date.now())
const position = computed(() =>
  hilosMaintenancePosition(maintenanceState.value),
)
const stateCard = computed(() =>
  hilosMaintenanceCard(maintenanceState.value, nowMs.value),
)
let clockTick: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  stateHandle.start()
  clockTick = setInterval(() => {
    nowMs.value = Date.now()
  }, 30_000)
})
onUnmounted(() => {
  stateHandle.dispose()
  clearInterval(clockTick)
})

const circle = createHilosMaintenanceCircleTable(props.context, {
  openAdd: openCircleAdd,
})
const circleTable = circle.controller
// The row the remove dialog holds in focus, as it stands now — undefined once it is gone.
const focusedRow = useSignal(circleTable.focusedRow)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => {
  circle.start()
})
onUnmounted(() => {
  circle.dispose()
})

const {
  sendMaintenanceEnable,
  sendMaintenanceCircleAdd,
  sendMaintenanceCircleRemove,
} = createHilosMaintenanceActions(props.context)

// A state push may replace the open banner while this action still awaits its ack.
const enableOpen = ref(false)
const enableAction = useTrackedAction({
  describeError: (error) =>
    error instanceof ActionError && error.outcome === 'fail'
      ? error.message
      : HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote,
})
const { loading: enableLoading, busy: enableBusy } = enableAction

function openEnable(): void {
  if (enableBusy.value || position.value !== 'open') return
  enableAction.clearError()
  enableOpen.value = true
}

function closeEnable(): void {
  // The controlled model guards the header's close button as well as Cancel.
  if (!enableBusy.value) enableOpen.value = false
}

async function submitEnable(): Promise<void> {
  if (enableBusy.value || position.value !== 'open') return
  if (await enableAction.run(sendMaintenanceEnable())) closeEnable()
}

// The add dialog. It closes on the server's word only: a refusal keeps it open with
// what was typed, and the named row arrives over the live table, not from here.
const circleAddOpen = ref(false)
const circleAddIdentifier = ref('')
const circleAddAction = useTrackedAction()
const {
  loading: circleAddLoading,
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
  loading: circleRemoveLoading,
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
  <HilosAdminPage :page="HilosPages.MAINTENANCE">
    <div aria-live="polite" data-id="hilos-maintenance-state">
      <div
        v-if="position === 'open'"
        class="alert alert-success d-flex flex-column flex-sm-row gap-3 align-items-start mb-3"
        data-id="hilos-maintenance-state-open"
      >
        <div class="d-flex gap-3 flex-grow-1">
          <i class="bi bi-check-circle-fill fs-5" aria-hidden="true"></i>
          <div>
            <h2 class="h6 fw-semibold mb-1">
              {{ HILOS_MAINTENANCE_STATE_COPY.openTitle }}
            </h2>
            <p class="small mb-0">
              {{ HILOS_MAINTENANCE_STATE_COPY.openDescription }}
            </p>
          </div>
        </div>
        <LoadingButton
          class="btn-warning btn-sm text-nowrap"
          :disabled="enableBusy"
          data-id="hilos-maintenance-enable"
          @click="openEnable"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.enableTitle }}
        </LoadingButton>
      </div>

      <section
        v-else-if="stateCard"
        class="card border-warning mb-3"
        :data-id="`hilos-maintenance-state-${stateCard.position}`"
        :aria-label="stateCard.title"
      >
        <div
          class="card-header d-flex flex-wrap align-items-center gap-2 bg-warning-subtle"
        >
          <h2 class="h6 fw-semibold mb-0">{{ stateCard.title }}</h2>
          <span class="badge text-bg-warning">{{ stateCard.phase }}</span>
          <span class="small text-body-secondary ms-auto">
            {{ HILOS_MAINTENANCE_STATE_COPY.operationPrefix }}
            <code>{{ stateCard.operation ?? '—' }}</code>
          </span>
        </div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-3">{{ HILOS_MAINTENANCE_STATE_COPY.phase }}</dt>
            <dd class="col-sm-9">
              <span class="badge text-bg-warning">{{ stateCard.phase }}</span>
              <span
                v-if="stateCard.phaseNote"
                class="text-body-secondary ms-2"
                >{{ stateCard.phaseNote }}</span
              >
            </dd>

            <template v-if="stateCard.position === 'manual'">
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.closedSince }}
              </dt>
              <dd class="col-sm-9">
                <code>{{ stateCard.startedAt ?? '—' }}</code>
                <span class="text-body-secondary ms-2">{{
                  stateCard.elapsed
                }}</span>
              </dd>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.turnedOn }}
              </dt>
              <dd class="col-sm-9">{{ stateCard.turnedOn }}</dd>
            </template>
            <template v-else>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.operation }}
              </dt>
              <dd class="col-sm-9">
                <code>{{ stateCard.operation ?? '—' }}</code>
              </dd>
              <dt class="col-sm-3">
                {{ HILOS_MAINTENANCE_STATE_COPY.started }}
              </dt>
              <dd class="col-sm-9">
                <code>{{ stateCard.startedAt ?? '—' }}</code>
              </dd>
            </template>

            <dt class="col-sm-3">
              {{ HILOS_MAINTENANCE_STATE_COPY.codesInHand }}
            </dt>
            <dd class="col-sm-9" data-id="hilos-maintenance-pass-count">
              {{ stateCard.passCount }}
            </dd>
            <dt class="col-sm-3">{{ HILOS_MAINTENANCE_STATE_COPY.circle }}</dt>
            <dd class="col-sm-9">{{ stateCard.circle }}</dd>
          </dl>
        </div>
      </section>
    </div>

    <HilosModal
      :model-value="enableOpen"
      :title="HILOS_MAINTENANCE_ENABLE_COPY.enableTitle"
      :close-on-backdrop="!enableBusy"
      :close-on-esc="!enableBusy"
      initial-focus="dialog"
      @update:model-value="closeEnable"
    >
      <div data-id="hilos-maintenance-enable-modal">
        <HilosActionError
          :action="enableAction"
          :details-title="HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle"
        />
        <p class="mb-2">{{ HILOS_MAINTENANCE_ENABLE_COPY.lead }}</p>
        <ul class="mb-2 ps-3">
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.outside }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.outsideNote }}
          </li>
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.inside }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.insideNote }}
          </li>
          <li>
            <strong>{{ HILOS_MAINTENANCE_ENABLE_COPY.agents }}</strong>
            — {{ HILOS_MAINTENANCE_ENABLE_COPY.agentsNote }}
          </li>
        </ul>
        <p class="mb-0 text-body-secondary">
          {{ HILOS_MAINTENANCE_ENABLE_COPY.footer }}
        </p>
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="enableBusy"
          data-id="hilos-maintenance-enable-cancel"
          @click="requestClose"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.cancel }}
        </button>
        <LoadingButton
          class="btn-warning"
          :loading="enableLoading"
          :disabled="enableBusy || position !== 'open'"
          data-id="hilos-maintenance-enable-confirm"
          @click="submitEnable"
        >
          {{ HILOS_MAINTENANCE_ENABLE_COPY.confirm }}
        </LoadingButton>
      </template>
    </HilosModal>

    <div class="card mb-3" data-id="hilos-maintenance-circle-panel">
      <div class="card-body">
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
      </div>
    </div>

    <HilosModal
      v-model="circleAddOpen"
      :title="HILOS_MAINTENANCE_CIRCLE_COPY.addTitle"
      :close-on-backdrop="!circleAddBusy"
      :close-on-esc="!circleAddBusy"
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
          :loading="circleAddLoading"
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
      :close-on-backdrop="!circleRemoveBusy"
      :close-on-esc="!circleRemoveBusy"
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
          :loading="circleRemoveLoading"
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
