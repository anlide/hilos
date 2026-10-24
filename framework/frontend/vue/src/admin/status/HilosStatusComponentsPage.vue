<!-- The read-only server viewport, including its standard live/Apply strip. -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core/base'
import {
  createHilosStatusComponentsTable,
  HILOS_STATUS_COMPONENTS_COPY,
  STATUS_COMPONENT_NAME_FIELD,
  STATUS_COMPONENT_AGENT_TYPE_FIELD,
  STATUS_COMPONENT_STATE_FIELD,
  type HilosStatusComponentsContext,
} from '@hilos/core/admin/status'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusComponentsContext
}>()
const table = createHilosStatusComponentsTable(props.context)
const stateClasses = {
  operational: 'text-bg-success',
  checking: 'text-bg-secondary',
  partial_outage: 'text-bg-warning',
  major_outage: 'text-bg-danger',
  maintenance: 'text-bg-info',
  unknown: 'bg-body-secondary text-body-emphasis',
} as const
onMounted(table.start)
onUnmounted(table.dispose)
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.STATUS_COMPONENTS"
    head-kind="section"
    tabs-label="Status pages"
  >
    <HilosViewportTable
      :controller="table.controller"
      data-id="hilos-status-components-table"
    >
      <template #[`cell-${STATUS_COMPONENT_NAME_FIELD}`]="{ row }">
        <div
          :data-id="`hilos-status-component-${row.componentKey}`"
          class="text-break"
        >
          <span class="fw-semibold">{{ row.name }}</span>
          <div
            v-if="row.description !== null"
            class="small text-body-secondary"
          >
            {{ row.description }}
          </div>
        </div>
      </template>
      <template #[`cell-${STATUS_COMPONENT_AGENT_TYPE_FIELD}`]="{ row }">
        <code class="text-break">{{ row.agentType }}</code>
      </template>
      <template #[`cell-${STATUS_COMPONENT_STATE_FIELD}`]="{ row }">
        <span
          class="badge text-wrap"
          :class="stateClasses[row.state]"
          :data-id="`hilos-status-component-state-${row.componentKey}`"
          >{{ HILOS_STATUS_COMPONENTS_COPY.states[row.state] }}</span
        >
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
