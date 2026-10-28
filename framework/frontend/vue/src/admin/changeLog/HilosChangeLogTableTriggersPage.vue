<script setup lang="ts">
import {
  CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  createHilosChangeLogTableTriggersTable,
  readHilosChangeLogTable,
  formatChangeLogTrigger,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableTriggersTable,
} from '@hilos/core/admin/change-log'
import { computedSignal, HilosPages } from '@hilos/core/base'
import { computed, inject, onUnmounted, ref, shallowRef, watch } from 'vue'

import HilosChangeLogTableShell from './HilosChangeLogTableShell.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project's connection, page scopes and tracked actions. */
  context: HilosChangeLogReceiptContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosChangeLogTableTriggersPage requires a provided Hilos router.',
  )
}
const route = useSignal(router.currentRoute)
const tableId = computed(
  () => route.value.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? '',
)
const scope = useSignal(computedSignal(() => props.context.scopes.page()))
const pageTable = props.context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
const table = useSignal(
  computedSignal(() => {
    const currentRoute = router.currentRoute.get()
    if (
      props.context.scopes.page()?.key !==
        HilosPages.CHANGE_LOG_TABLE_TRIGGERS ||
      currentRoute.page !== HilosPages.CHANGE_LOG_TABLE_TRIGGERS
    ) {
      return null
    }
    const detail = readHilosChangeLogTable(pageTable.get())
    return detail?.name === currentRoute.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
      ? detail
      : null
  }),
)
const tableName = computed(() => table.value?.name)
const viewport = shallowRef<HilosChangeLogTableTriggersTable | null>(null)
const generation = ref(0)

// A replacement scope is a new page answer even at the same address. Rebind
// after navigation publishes its route and clears the buffered old answer.
watch(
  [scope, tableId, tableName],
  ([, name, permittedName]) => {
    viewport.value?.dispose()
    viewport.value = null
    if (permittedName === undefined || permittedName !== name) return
    viewport.value = createHilosChangeLogTableTriggersTable(props.context, {
      table: name,
    })
    viewport.value.start()
    generation.value += 1
  },
  { immediate: true },
)
onUnmounted(() => viewport.value?.dispose())
</script>

<template>
  <HilosChangeLogTableShell
    :context="context"
    :page="HilosPages.CHANGE_LOG_TABLE_TRIGGERS"
  >
    <section
      v-if="viewport"
      class="mb-4"
      data-id="hilos-change-log-table-triggers"
    >
      <p class="small text-body-secondary mb-2">{{ copy.triggersLead }}</p>
      <HilosViewportTable :key="generation" :controller="viewport.controller">
        <template #[`cell-${CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD}`]="{ row }">
          <code class="text-break">{{ row.name }}</code>
        </template>
        <template #[`cell-${CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD}`]>
          <span class="badge bg-body-secondary text-body border">{{
            copy.service
          }}</span>
        </template>
        <template #[`cell-${CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD}`]="{ row }">
          <span class="small text-nowrap">{{
            formatChangeLogTrigger(row).fires
          }}</span>
        </template>
        <template
          #[`cell-${CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD}`]="{ row }"
        >
          <span class="small">{{ formatChangeLogTrigger(row).writesTo }}</span>
        </template>
        <template
          #[`cell-${CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD}`]="{ row }"
        >
          <span class="small text-nowrap">{{
            formatChangeLogTrigger(row).validFrom
          }}</span>
        </template>
      </HilosViewportTable>
      <p class="small text-body-secondary mt-2 mb-0">
        {{ copy.triggersFootnote }}
      </p>
    </section>
  </HilosChangeLogTableShell>
</template>
