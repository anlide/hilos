<script setup lang="ts">
import {
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogChannelBadge,
  changeLogRecordRouteParam,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTime,
  formatChangeLogWho,
  readChangeLogRecordRouteParam,
  readHilosChangeLogTable,
  type HilosChangeLogHistoryRow,
  type HilosChangeLogHistoryTable,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import {
  computedSignal,
  HilosPages,
  hilosTimeZone,
  isHiddenValue,
  subscribeSignal,
} from '@hilos/core/base'
import { computed, inject, onUnmounted, ref, shallowRef, watch } from 'vue'

import HilosChangeLogTableShell from './HilosChangeLogTableShell.vue'
import HilosHiddenMark from '../../HilosHiddenMark.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosChangeLogReceiptModal from './HilosChangeLogReceiptModal.vue'

const props = defineProps<{
  /** The project's connection, page scope, and shared tracked actions. */
  context: HilosChangeLogReceiptContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosChangeLogTableHistoryPage requires a provided Hilos router.',
  )
}

const route = useSignal(router.currentRoute)
const tableId = computed(
  () => route.value.params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? '',
)
const record = computed(() =>
  readChangeLogRecordRouteParam(
    route.value.params[CHANGE_LOG_RECORD_ROUTE_PARAM],
  ),
)
const pageTable = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
)
const table = computed(() => readHilosChangeLogTable(pageTable.value))
const journaled = computed(() => table.value?.journaled === true)
const timeZone = useSignal(hilosTimeZone)
function time(moment: string): string {
  void timeZone.value
  return formatChangeLogTime(moment)
}
const tablePath = computed(() =>
  router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: tableId.value,
  }),
)
const history = shallowRef<HilosChangeLogHistoryTable | null>(null)
// The viewport table reads its controller's declaration once, so every rebuilt
// window is drawn by a table of its own.
const historyGeneration = ref(0)
const reader = createHilosChangeLogReceiptReader(props.context)
const dialog = useSignal(reader.dialog)
const offLeave = router.onLeave(() => reader.close())
const offIdentity = subscribeSignal(router.pageIdentity, () => reader.close())
const offRoute = subscribeSignal(router.currentRoute, () => reader.close())
// A re-decided page replaces its scope even when the address stays the same.
// Discard attribution synchronously, before a viewer can render the new scope.
const offScope = subscribeSignal(
  computedSignal(() => props.context.scopes.page()),
  () => reader.close(),
)

// The router clears its buffered page response after publishing the route.
// Rebind before the next render, once that synchronous navigation has finished.
watch(
  [tableId, record, journaled],
  ([name, key, tracked]) => {
    history.value?.dispose()
    history.value = null
    if (!tracked) return
    history.value = createHilosChangeLogHistoryTable(props.context, {
      table: name,
      record: key,
      pageHeading: true,
      fields: () =>
        (table.value?.columns ?? [])
          .filter(({ mode }) => mode !== 'record_key')
          .map(({ name: field }) => field),
    })
    history.value.start()
    historyGeneration.value += 1
  },
  { immediate: true },
)

// An arrow, not a declaration: a hoisted function would lose the router's
// narrowing above.
/** Address this table narrowed to a record whose key is not masked. */
const recordPath = (row: HilosChangeLogHistoryRow): string | undefined => {
  const key = row.recordKey
  const parts = (key ?? []).filter(
    (part): part is number | string => part !== null,
  )
  if (key === null || parts.length === 0 || parts.length !== key.length) {
    return undefined
  }
  return router.resolvePath(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: tableId.value,
    [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
  })
}

/** Open the receipt a history row carries, if it has one. */
function openReceipt(row: HilosChangeLogHistoryRow): void {
  if (row.receiptId !== null) void reader.openReceipt(row.receiptId)
}

onUnmounted(() => {
  offLeave()
  offIdentity()
  offRoute()
  offScope()
  reader.dispose()
  history.value?.dispose()
})
</script>
<template>
  <HilosChangeLogTableShell
    :context="context"
    :page="HilosPages.CHANGE_LOG_TABLE_HISTORY"
  >
    <template v-if="table !== null">
      <p v-if="!table.journaled" class="alert alert-secondary small py-2">
        {{ copy.untracked }}
      </p>
      <section v-if="history">
        <p
          v-if="history.controller.frame.declaration.subtitle"
          class="small text-body-secondary mb-2"
          data-id="hilos-table-subtitle"
        >
          {{ history.controller.frame.declaration.subtitle }}
        </p>
        <div v-if="record !== null" class="mb-2">
          <span
            class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle d-inline-flex align-items-center gap-1"
            data-id="hilos-change-log-record-filter"
          >
            {{ copy.onlyRecord }} {{ formatChangeLogRecordKey(record) }}
            <HilosLink
              v-if="tablePath !== undefined"
              :to="tablePath"
              class="link-primary"
              data-id="hilos-change-log-record-filter-clear"
              :aria-label="copy.showAllRecords"
              ><i class="bi bi-x-lg" aria-hidden="true"></i
            ></HilosLink>
          </span>
        </div>
        <HilosViewportTable
          :key="historyGeneration"
          :controller="history.controller"
        >
          <template #cell-createdAt="{ row }">{{
            time(row.createdAt)
          }}</template>
          <template #cell-recordKey="{ row }">
            <HilosLink
              v-if="recordPath(row) !== undefined"
              :to="recordPath(row)!"
              :data-id="`hilos-change-log-history-record-${row.rowKey}`"
              >{{ formatChangeLogRecordKey(row.recordKey) }}</HilosLink
            >
            <span v-else>{{ formatChangeLogRecordKey(row.recordKey) }}</span>
          </template>
          <template #cell-mutation="{ row }">
            <span class="badge bg-body-secondary text-body border">{{
              formatChangeLogMutation(row.mutation)
            }}</span>
          </template>
          <template #cell-changes="{ row }">
            <div
              v-for="(line, index) in formatChangeLogHistoryChanges(row)"
              :key="index"
              class="small text-break"
            >
              {{ line }}
            </div>
          </template>
          <template #cell-actorLabel="{ row }">
            <span :class="{ 'text-body-secondary': row.actorDeleted }">
              <template v-if="isHiddenValue(formatChangeLogWho(row))">
                <HilosHiddenMark /><template v-if="row.actorId !== null">
                  (#{{ row.actorId }})</template
                >
              </template>
              <template v-else>{{ formatChangeLogWho(row) }}</template>
            </span>
            <div
              v-if="row.receiptId === null"
              class="small text-body-secondary"
            >
              {{ copy.pastApplication }}
            </div>
            <div
              v-if="row.subjectId !== null"
              class="small text-body-secondary"
            >
              {{ CHANGE_LOG_ON_BEHALF_OF_TEXT }}
              <template v-if="isHiddenValue(formatChangeLogOnBehalfOf(row))">
                <HilosHiddenMark /> (#{{ row.subjectId }})
              </template>
              <template v-else>{{
                formatChangeLogOnBehalfOf(row) ?? `#${row.subjectId}`
              }}</template>
            </div>
            <span
              v-if="
                row.receiptId !== null &&
                changeLogChannelBadge(row.channel) !== null
              "
              class="badge bg-body-secondary text-body border mt-1"
            >
              <i
                :class="`bi ${changeLogChannelBadge(row.channel)?.icon} me-1`"
                aria-hidden="true"
              ></i>
              {{ changeLogChannelBadge(row.channel)?.label }}
            </span>
          </template>
          <template #cell-actions="{ row }">
            <button
              v-if="row.receiptId !== null"
              type="button"
              class="btn btn-sm btn-outline-secondary text-nowrap"
              :data-id="`hilos-change-log-history-receipt-${row.rowKey}`"
              :aria-label="`${copy.receipt} #${row.receiptId}`"
              @click="openReceipt(row)"
            >
              {{ copy.receipt }}
            </button>
            <span v-else class="small text-body-secondary text-nowrap">{{
              copy.noReceipt
            }}</span>
          </template>
        </HilosViewportTable>
        <p class="small text-body-secondary mt-2 mb-0">
          {{ copy.historyNote }}
        </p>
      </section>
    </template>
    <HilosChangeLogReceiptModal :reader="reader" :dialog="dialog" />
  </HilosChangeLogTableShell>
</template>
