<!-- The Tracked table page draws one table's section: its header, its columns,
its journal service triggers, and the history window the view builds after the
page answers, only for a journaled table. The address names the table and may
narrow the history to one record; the receipt modal reads through the Change
Log's own reading action. Bootstrap classes only. -->
<script setup lang="ts">
import {
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_COPY as copy,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  changeLogChannelBadge,
  changeLogColumnModeCard,
  changeLogRecordRouteParam,
  createHilosChangeLogHistoryTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogColumnStorage,
  formatChangeLogHistoryChanges,
  formatChangeLogMutation,
  formatChangeLogOnBehalfOf,
  formatChangeLogRecordKey,
  formatChangeLogTableActivity,
  formatChangeLogTableOwner,
  formatChangeLogTime,
  formatChangeLogTrigger,
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
import {
  computed,
  inject,
  onUnmounted,
  ref,
  shallowRef,
  useId,
  watch,
} from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
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
  throw new Error('HilosChangeLogTablePage requires a provided Hilos router.')
}

const columnsHeadingId = useId()
const triggersHeadingId = useId()
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
const activity = computed(() => {
  void timeZone.value
  return table.value ? formatChangeLogTableActivity(table.value) : ''
})
function time(moment: string): string {
  void timeZone.value
  return formatChangeLogTime(moment)
}
const hasNoise = computed(
  () => table.value?.columns.some(({ mode }) => mode === 'noise') === true,
)
const tablePath = computed(() =>
  router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
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
  return router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
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
  <HilosAdminPage :page="HilosPages.CHANGE_LOG_TABLE">
    <p v-if="table === null" class="text-body-secondary">—</p>
    <template v-else>
      <div
        class="d-flex flex-wrap align-items-center gap-2 border rounded-3 p-3 mb-3"
        data-id="hilos-change-log-table-header"
      >
        <i class="bi bi-table text-body-secondary" aria-hidden="true"></i>
        <code class="fs-6 fw-semibold text-break">{{ table.name }}</code>
        <span class="small text-body-secondary">{{
          formatChangeLogTableOwner(table.owner)
        }}</span>
        <span
          v-if="table.journaled"
          class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-auto"
        >
          <i class="bi bi-journal-check me-1" aria-hidden="true"></i>
          {{ copy.journaled }}
        </span>
        <span v-else class="badge bg-body-secondary text-body border ms-auto">
          {{ CHANGE_LOG_TABLES_UNTRACKED_NOTE }}
        </span>
        <div v-if="table.journaled" class="w-100 small text-body-secondary">
          {{ activity }}
        </div>
      </div>

      <p v-if="!table.journaled" class="alert alert-secondary small py-2">
        {{ copy.untracked }}
      </p>
      <template v-else>
        <section class="mb-4" data-id="hilos-change-log-table-columns">
          <h2 :id="columnsHeadingId" class="h5">{{ copy.columns }}</h2>
          <div class="table-responsive d-none d-md-block">
            <table
              class="table table-sm align-middle mb-0"
              :aria-labelledby="columnsHeadingId"
            >
              <thead>
                <tr>
                  <th scope="col">{{ copy.column }}</th>
                  <th scope="col">{{ copy.storedAs }}</th>
                  <th scope="col">{{ copy.inJournal }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="column in table.columns" :key="column.name">
                  <td>
                    <code class="text-break">{{ column.name }}</code>
                  </td>
                  <td class="small">
                    {{ formatChangeLogColumnStorage(column) }}
                  </td>
                  <td>
                    <span
                      v-if="changeLogColumnModeCard(column.mode)"
                      class="badge text-wrap"
                      :class="changeLogColumnModeCard(column.mode)?.tone"
                    >
                      <i
                        :class="`bi ${changeLogColumnModeCard(column.mode)?.icon} me-1`"
                        aria-hidden="true"
                      ></i>
                      {{ changeLogColumnModeCard(column.mode)?.label }}
                    </span>
                    <template v-else>—</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div
            class="d-md-none"
            role="list"
            :aria-labelledby="columnsHeadingId"
          >
            <div
              v-for="column in table.columns"
              :key="column.name"
              class="card mb-2"
              role="listitem"
            >
              <div class="card-body py-2 px-3">
                <div class="fw-medium mb-1">
                  <code class="text-break">{{ column.name }}</code>
                </div>
                <dl class="row mb-0 small g-0">
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.storedAs }}
                  </dt>
                  <dd class="col-7 mb-0">
                    {{ formatChangeLogColumnStorage(column) }}
                  </dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.inJournal }}
                  </dt>
                  <dd class="col-7 mb-0">
                    <span
                      v-if="changeLogColumnModeCard(column.mode)"
                      class="badge text-wrap"
                      :class="changeLogColumnModeCard(column.mode)?.tone"
                    >
                      <i
                        :class="`bi ${changeLogColumnModeCard(column.mode)?.icon} me-1`"
                        aria-hidden="true"
                      ></i>
                      {{ changeLogColumnModeCard(column.mode)?.label }}
                    </span>
                    <template v-else>—</template>
                  </dd>
                </dl>
              </div>
            </div>
          </div>
          <p v-if="hasNoise" class="small text-body-secondary mt-2 mb-0">
            {{ copy.noisyNote }}
          </p>
        </section>

        <section class="mb-4" data-id="hilos-change-log-table-triggers">
          <h2 :id="triggersHeadingId" class="h5">{{ copy.triggers }}</h2>
          <p class="small text-body-secondary mb-2">{{ copy.triggersLead }}</p>
          <div class="table-responsive d-none d-md-block">
            <table
              class="table table-sm align-middle mb-0"
              :aria-labelledby="triggersHeadingId"
            >
              <thead>
                <tr>
                  <th scope="col">{{ copy.trigger }}</th>
                  <th scope="col">{{ copy.owner }}</th>
                  <th scope="col">{{ copy.fires }}</th>
                  <th scope="col">{{ copy.writesTo }}</th>
                  <th scope="col">{{ copy.validFrom }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="trigger in table.triggers" :key="trigger.name">
                  <td>
                    <code class="text-break">{{ trigger.name }}</code>
                  </td>
                  <td>
                    <span class="badge bg-body-secondary text-body border">{{
                      copy.service
                    }}</span>
                  </td>
                  <td class="small text-nowrap">
                    {{ formatChangeLogTrigger(trigger).fires }}
                  </td>
                  <td class="small">
                    {{ formatChangeLogTrigger(trigger).writesTo }}
                  </td>
                  <td class="small text-nowrap">
                    {{ formatChangeLogTrigger(trigger).validFrom }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div
            class="d-md-none"
            role="list"
            :aria-labelledby="triggersHeadingId"
          >
            <div
              v-for="trigger in table.triggers"
              :key="trigger.name"
              class="card mb-2"
              role="listitem"
            >
              <div class="card-body py-2 px-3">
                <div
                  class="d-flex flex-wrap align-items-center gap-1 fw-medium mb-1"
                >
                  <code class="text-break">{{ trigger.name }}</code>
                  <span class="badge bg-body-secondary text-body border">{{
                    copy.service
                  }}</span>
                </div>
                <dl class="row mb-0 small g-0">
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.fires }}
                  </dt>
                  <dd class="col-7 mb-0">
                    {{ formatChangeLogTrigger(trigger).fires }}
                  </dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.writesTo }}
                  </dt>
                  <dd class="col-7 mb-0">
                    {{ formatChangeLogTrigger(trigger).writesTo }}
                  </dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.validFrom }}
                  </dt>
                  <dd class="col-7 mb-0">
                    {{ formatChangeLogTrigger(trigger).validFrom }}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
          <p class="small text-body-secondary mt-2 mb-0">
            {{ copy.triggersFootnote }}
          </p>
        </section>

        <section v-if="history">
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
    </template>
    <HilosChangeLogReceiptModal :reader="reader" :dialog="dialog" />
  </HilosAdminPage>
</template>
