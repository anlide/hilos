<!-- Cluster maintenance reports: the core owns the window, facts and copy;
this view presents them under Maintenance's shared section head. -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core/base'
import {
  createHilosMaintenanceNodesTable,
  hilosMaintenanceNodePresentation,
  MAINTENANCE_NODE_ID_FIELD,
  MAINTENANCE_NODE_QUIESCED_FIELD,
  MAINTENANCE_NODE_PHASE_FIELD,
  MAINTENANCE_NODE_IS_LEADER_FIELD,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project's shared maintenance context. */
  context: HilosMaintenanceContext
}>()

const nodes = createHilosMaintenanceNodesTable(props.context)
const nodesTable = nodes.controller

onMounted(() => {
  nodes.start()
})
onUnmounted(() => {
  nodes.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.MAINTENANCE_NODES"
    head-kind="section"
    tabs-label="Maintenance pages"
  >
    <HilosViewportTable
      data-id="hilos-maintenance-nodes-table"
      :controller="nodesTable"
    >
      <template #[`cell-${MAINTENANCE_NODE_ID_FIELD}`]="{ row }">
        <span :data-id="`hilos-maintenance-nodes-row-${row.nodeId}`">{{
          row.nodeId
        }}</span>
      </template>
      <template #[`cell-${MAINTENANCE_NODE_QUIESCED_FIELD}`]="{ row }">
        <span
          :class="
            hilosMaintenanceNodePresentation(row).quiesced.tone
              ? `text-${hilosMaintenanceNodePresentation(row).quiesced.tone}-emphasis`
              : undefined
          "
          >{{ hilosMaintenanceNodePresentation(row).quiesced.text }}</span
        >
      </template>
      <template #[`cell-${MAINTENANCE_NODE_PHASE_FIELD}`]="{ row }">
        <span
          :class="{
            'badge text-bg-secondary':
              hilosMaintenanceNodePresentation(row).phase.badge,
          }"
          >{{ hilosMaintenanceNodePresentation(row).phase.text }}</span
        >
      </template>
      <template #[`cell-${MAINTENANCE_NODE_IS_LEADER_FIELD}`]="{ row }">{{
        hilosMaintenanceNodePresentation(row).role
      }}</template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
