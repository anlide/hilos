<!-- HilosSecuritySignInMethodsPage — the framework sign-in methods page
(HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427): one row per method the project
wired, each with a switch, whether the installation can serve it, and — for a
provider — a link to its own screen. The table, the row view-model and the switch
round-trip are the core headless's (createHilosSignInMethodsTable /
createHilosSignInMethodsActions); this view owns only the markup, so a project
mounts it by passing its HilosSignInMethodsContext.
The switch is a tracked action: it dispatches the one-method switch, the refusal
toasts ("at least one sign-in method must stay on") and the switch goes back, and
the switch redraws from the live enabled set when it arrives — that set, not the
row, is what says a method is on (the same set every sign-in surface reshapes
from). The screen is built from text: the mockup has no node for it yet (D-093).
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosSignInMethodsActions,
  createHilosSignInMethodsTable,
  HilosPages,
  resolveHilosPath,
  type HilosSignInMethodRow,
  type HilosSignInMethodsContext,
} from '@hilos/core'
import { onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosSwitch from '../../HilosSwitch.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosSignInMethodsContext
}>()

const methods = createHilosSignInMethodsTable(props.context)
const methodsTable = methods.controller
const enabledKeys = useSignal(methods.enabledKeys)
const { sendMethodSet } = createHilosSignInMethodsActions(props.context)

onMounted(() => methods.start())
onUnmounted(() => methods.dispose())

// One tracked runner for every switch: a single in-flight guard across rows is
// enough, and the busy flag disables every switch while one write is settling.
const { busy: switchBusy, run: runSwitch } = useTrackedAction()
const pendingMethodKey = ref<string | null>(null)

/** Whether the method is on now, by the live set rather than the row. */
function isOn(row: HilosSignInMethodRow): boolean {
  return enabledKeys.value.includes(row.methodKey)
}

/** The provider's own screen (its {providerId} route param is the key). */
function providerPath(providerKey: string): string {
  return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
    providerId: providerKey,
  })
}

// Dispatch the switch as a tracked action. Nothing is set optimistically: the
// switch follows the live set on success and remains on it after a refusal.
async function toggle(row: HilosSignInMethodRow, next: boolean): Promise<void> {
  pendingMethodKey.value = row.methodKey
  try {
    await runSwitch(sendMethodSet(row.methodKey, next))
  } finally {
    pendingMethodKey.value = null
  }
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.SECURITY_SIGN_IN_METHODS">
    <HilosViewportTable :controller="methodsTable">
      <template #cell-methodKey="{ row }">
        <div class="fw-semibold">{{ row.label }}</div>
        <code class="small text-body-secondary">{{ row.methodKey }}</code>
      </template>
      <template #cell-enabled="{ row }">
        <HilosSwitch
          class="mb-0"
          :checked="isOn(row)"
          :busy="pendingMethodKey === row.methodKey"
          :disabled="switchBusy"
          :aria-label="`Enable ${row.label}`"
          :data-id="`hilos-sign-in-method-enabled-${row.methodKey}`"
          @toggle="toggle(row, $event)"
        />
      </template>
      <template #cell-ready="{ row }">
        <span
          v-if="row.ready"
          class="badge text-bg-success-subtle text-success-emphasis"
        >
          {{ row.providerKey === null ? 'Ready' : 'Configured' }}
        </span>
        <span v-else class="badge text-bg-warning-subtle text-warning-emphasis">
          {{
            row.providerKey === null ? 'Nothing to send with' : 'Not configured'
          }}
        </span>
        <HilosLink
          v-if="row.providerKey !== null"
          :to="providerPath(row.providerKey)"
          class="btn btn-sm btn-link"
          :data-id="`hilos-sign-in-method-provider-${row.methodKey}`"
        >
          Configure
        </HilosLink>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
