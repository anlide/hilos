<script setup lang="ts">
import {
  CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  createHilosChangeLogTableColumnsTable,
  readHilosChangeLogTable,
  formatChangeLogColumnStorage,
  changeLogColumnModeCard,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableColumnsTable,
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
    'HilosChangeLogTableColumnsPage requires a provided Hilos router.',
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
        HilosPages.CHANGE_LOG_TABLE_COLUMNS ||
      currentRoute.page !== HilosPages.CHANGE_LOG_TABLE_COLUMNS
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
const viewport = shallowRef<HilosChangeLogTableColumnsTable | null>(null)
const generation = ref(0)

const hasNoise = computed(
  () => table.value?.columns.some(({ mode }) => mode === 'noise') === true,
)

// A replacement scope is a new page answer even at the same address. Rebind
// after navigation publishes its route and clears the buffered old answer.
watch(
  [scope, tableId, tableName],
  ([, name, permittedName]) => {
    viewport.value?.dispose()
    viewport.value = null
    if (permittedName === undefined || permittedName !== name) return
    viewport.value = createHilosChangeLogTableColumnsTable(props.context, {
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
    :page="HilosPages.CHANGE_LOG_TABLE_COLUMNS"
  >
    <section
      v-if="viewport"
      class="mb-4"
      data-id="hilos-change-log-table-columns"
    >
      <HilosViewportTable :key="generation" :controller="viewport.controller">
        <template #[`cell-${CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD}`]="{ row }">
          <code class="text-break">{{ row.name }}</code>
        </template>
        <template #[`cell-${CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD}`]="{ row }">
          <span class="small">{{ formatChangeLogColumnStorage(row) }}</span>
        </template>
        <template #[`cell-${CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD}`]="{ row }">
          <span
            v-if="changeLogColumnModeCard(row.mode)"
            class="badge text-wrap"
            :class="changeLogColumnModeCard(row.mode)?.tone"
          >
            <i
              :class="`bi ${changeLogColumnModeCard(row.mode)?.icon} me-1`"
              aria-hidden="true"
            ></i>
            {{ changeLogColumnModeCard(row.mode)?.label }}
          </span>
          <template v-else>—</template>
        </template>
      </HilosViewportTable>
      <p v-if="hasNoise" class="small text-body-secondary mt-2 mb-0">
        {{ copy.noisyNote }}
      </p>
    </section>
  </HilosChangeLogTableShell>
</template>
