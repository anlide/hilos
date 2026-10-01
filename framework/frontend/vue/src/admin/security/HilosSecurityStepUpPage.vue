<!-- HilosSecurityStepUpPage — the framework page of operations that ask for
confirmation (HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor:
one row per operation the step-up directory declares, with whether the
framework or the project declared it and a switch. The table, the row
view-model and the switch round-trip are the core headless's
(createHilosSecurityStepUpTable / createHilosSecurityStepUpActions); this view
owns only the markup, so a project mounts it by passing its
HilosTwoFactorContext. The switch is a tracked action: a spinner on its own
row while it flies, the other switches dimmed, and the switch redraws from the
table's live row. The page heading names the table. The screen is built from
text: the mockup still draws the list on the two-factor page (D-143). Bootstrap
classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
  type HilosStepUpOperationRow,
  type HilosTwoFactorContext,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosTwoFactorContext
}>()

const operations = createHilosSecurityStepUpTable(props.context)
const { sendOperationSet } = createHilosSecurityStepUpActions(props.context)

onMounted(() => operations.start())
onUnmounted(() => operations.dispose())

const { busy: operationBusy, run: runOperation } = useTrackedAction()
const pendingOperationKey = ref<string | null>(null)

async function toggleOperation(
  row: HilosStepUpOperationRow,
  enabled: boolean,
): Promise<void> {
  pendingOperationKey.value = row.operationKey
  try {
    await runOperation(sendOperationSet(row.operationKey, enabled))
  } finally {
    pendingOperationKey.value = null
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_STEP_UP">
    <HilosViewportTable
      :controller="operations.controller"
      data-id="hilos-step-up-table"
    >
      <template #cell-operationKey="{ row }">
        <span
          class="fw-semibold"
          :data-id="`hilos-step-up-row-${row.operationKey}`"
          >{{ row.label }}</span
        >
      </template>
      <template #cell-owner="{ row }">
        <span class="badge text-bg-light border">
          {{
            row.owner === 'framework'
              ? HILOS_STEP_UP_ADMIN_COPY.framework
              : HILOS_STEP_UP_ADMIN_COPY.project
          }}
        </span>
      </template>
      <template #cell-enabled="{ row }">
        <HilosSwitch
          class="mb-0"
          :checked="row.enabled"
          :busy="pendingOperationKey === row.operationKey"
          :disabled="operationBusy"
          :aria-label="`Require confirmation for ${row.label}`"
          :data-id="`hilos-step-up-switch-${row.operationKey}`"
          @toggle="toggleOperation(row, $event)"
        />
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
