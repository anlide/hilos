<!-- HilosMaintenancePage — the framework Hilos maintenance section
(HilosPages.MAINTENANCE, HIL-1119): the admin section of the node freeze, inside the
admin shell. Its one block today is the verifier circle — who will check the system
after a freeze. A verifier is named here in a dialog (HIL-1120); taking one out is
still done on the backup page (HIL-1121). The list is live, and so is the online mark
on it: a named person opening or closing a tab re-draws that person's row without a
reload, and a named person arrives as a row the same way. All table logic, the row
view-model, the action, and the block's words — the column labels and the dialog's
included — are the core headless's (createHilosMaintenanceCircleTable,
createHilosMaintenanceActions, HILOS_MAINTENANCE_CIRCLE_COPY); this view owns only the
markup, so a project mounts it by passing its HilosMaintenanceContext. Bootstrap
classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosMaintenanceActions,
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HilosPages,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosActionError from '../../HilosActionError.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosMaintenanceContext
}>()

const circle = createHilosMaintenanceCircleTable(props.context)
const circleTable = circle.controller

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => {
  circle.start()
})
onUnmounted(() => {
  circle.dispose()
})

const { sendMaintenanceCircleAdd } = createHilosMaintenanceActions(
  props.context,
)

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

const circleColumns: HilosTableColumnOf<HilosMaintenanceCircleRow>[] = [
  {
    key: MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.addressColumn,
    sortable: true,
  },
  {
    key: MAINTENANCE_CIRCLE_ONLINE_FIELD,
    label: HILOS_MAINTENANCE_CIRCLE_COPY.onlineColumn,
  },
]
</script>

<template>
  <HilosAdminPage :page="HilosPages.MAINTENANCE">
    <div class="card mb-3" data-id="hilos-maintenance-circle-panel">
      <div class="card-body">
        <div
          class="d-flex align-items-start justify-content-between gap-2 flex-wrap"
        >
          <div>
            <div class="fw-semibold">
              {{ HILOS_MAINTENANCE_CIRCLE_COPY.title }}
            </div>
            <div class="small text-body-secondary">
              {{ HILOS_MAINTENANCE_CIRCLE_COPY.rule }}
            </div>
            <div class="small text-body-secondary">
              {{ HILOS_MAINTENANCE_CIRCLE_COPY.volatile }}
            </div>
          </div>
          <button
            type="button"
            class="btn btn-outline-primary btn-sm text-nowrap"
            data-id="hilos-maintenance-circle-add"
            @click="openCircleAdd"
          >
            {{ HILOS_MAINTENANCE_CIRCLE_COPY.addButton }}
          </button>
        </div>
        <div class="mt-3">
          <HilosViewportTable
            data-id="hilos-maintenance-circle-table"
            :label="HILOS_MAINTENANCE_CIRCLE_COPY.title"
            :controller="circleTable"
            :columns="circleColumns"
            :empty-text="HILOS_MAINTENANCE_CIRCLE_COPY.empty"
          >
            <template #row="{ row }">
              <td :data-id="`hilos-maintenance-circle-row-${row.identifier}`">
                {{ row.identifier }}
              </td>
              <td>
                <span
                  :class="row.online ? 'text-success' : 'text-body-secondary'"
                  :data-id="`hilos-maintenance-circle-online-${row.identifier}`"
                  >{{
                    row.online
                      ? HILOS_MAINTENANCE_CIRCLE_COPY.online
                      : HILOS_MAINTENANCE_CIRCLE_COPY.offline
                  }}</span
                >
              </td>
            </template>
          </HilosViewportTable>
        </div>
      </div>
    </div>

    <HilosModal
      v-model="circleAddOpen"
      :title="HILOS_MAINTENANCE_CIRCLE_COPY.addTitle"
      :close-on-backdrop="!circleAddBusy"
      :close-on-esc="!circleAddBusy"
      @cancel="closeCircleAdd"
    >
      <HilosActionError :action="circleAddAction" />
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
  </HilosAdminPage>
</template>
