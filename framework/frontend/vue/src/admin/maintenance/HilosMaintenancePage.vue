<!-- HilosMaintenancePage — the framework Hilos maintenance section
(HilosPages.MAINTENANCE, HIL-1119): the admin section of the node freeze, inside the
admin shell. Its one block today is the verifier circle — who will check the system
after a freeze — read-only here: adding and removing a member are separate leaves.
The list is live, and so is the online mark on it: a named person opening or closing
a tab re-draws that person's row without a reload. All table logic, the row
view-model, and the block's words — the column labels included — are the core
headless's (createHilosMaintenanceCircleTable, HILOS_MAINTENANCE_CIRCLE_COPY); this
view owns only the markup, so a project mounts it by passing its
HilosMaintenanceContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosMaintenanceCircleTable,
  HILOS_MAINTENANCE_CIRCLE_COPY,
  HilosPages,
  MAINTENANCE_CIRCLE_IDENTIFIER_FIELD,
  MAINTENANCE_CIRCLE_ONLINE_FIELD,
  type HilosMaintenanceCircleRow,
  type HilosMaintenanceContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project context: scope stores and the connection. */
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
        <div class="fw-semibold">{{ HILOS_MAINTENANCE_CIRCLE_COPY.title }}</div>
        <div class="small text-body-secondary">
          {{ HILOS_MAINTENANCE_CIRCLE_COPY.rule }}
        </div>
        <div class="small text-body-secondary">
          {{ HILOS_MAINTENANCE_CIRCLE_COPY.volatile }}
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
  </HilosAdminPage>
</template>
