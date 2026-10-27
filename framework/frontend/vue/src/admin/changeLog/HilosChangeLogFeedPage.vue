<script setup lang="ts">
import {
  CHANGE_LOG_NO_RECEIPT_TEXT,
  CHANGE_LOG_ON_BEHALF_OF_TEXT,
  CHANGE_LOG_OPEN_TABLES_LABEL,
  CHANGE_LOG_OVERVIEW_SECTION,
  changeLogChannelBadge,
  changeLogFeedEmpty,
  createHilosChangeLogFeedTable,
  createHilosChangeLogReceiptReader,
  formatChangeLogFeedOpenLabel,
  formatChangeLogOnBehalfOf,
  formatChangeLogTime,
  formatChangeLogTouched,
  formatChangeLogWho,
  readHilosChangeLogOverview,
  type HilosChangeLogFeedRow,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import {
  computedSignal,
  HilosPages,
  hilosTimeZone,
  isHiddenValue,
  subscribeSignal,
} from '@hilos/core/base'
import { computed, inject, onMounted, onUnmounted } from 'vue'

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
const pageOverview = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION),
)
const overview = computed(() => readHilosChangeLogOverview(pageOverview.value))
const empty = computed(() => changeLogFeedEmpty(overview.value))
const timeZone = useSignal(hilosTimeZone)
function time(moment: string): string {
  void timeZone.value
  return formatChangeLogTime(moment)
}
const tablesPath = router?.resolvePath(HilosPages.CHANGE_LOG_TABLES, {})
const feed = createHilosChangeLogFeedTable(props.context, {
  tables: () => overview.value?.journaledTables ?? [],
})
const reader = createHilosChangeLogReceiptReader(props.context)
const dialog = useSignal(reader.dialog)
const offLeave = router?.onLeave(() => reader.close())
const offIdentity = router
  ? subscribeSignal(router.pageIdentity, () => reader.close())
  : undefined
// A re-decided page replaces its scope even when the address stays the same.
// Discard attribution synchronously, before a viewer can render the new scope.
const offScope = subscribeSignal(
  computedSignal(() => props.context.scopes.page()),
  () => reader.close(),
)

/** Open only the address carried by the selected feed row. */
function openDetails(row: HilosChangeLogFeedRow): void {
  if (row.kind === 'receipt' && row.receiptId !== null) {
    void reader.openReceipt(row.receiptId)
  } else if (row.kind === 'entry' && row.entryId !== null) {
    void reader.openEntry(row.entryId)
  }
}

onMounted(() => feed.start())
onUnmounted(() => {
  offLeave?.()
  offIdentity?.()
  offScope()
  reader.dispose()
  feed.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.CHANGE_LOG_FEED"
    head-kind="section"
    tabs-label="Change Log pages"
  >
    <HilosViewportTable :controller="feed.controller">
      <template #cell-createdAt="{ row }">{{ time(row.createdAt) }}</template>
      <template #cell-actorLabel="{ row }">
        <span :class="{ 'text-body-secondary': row.actorDeleted }">
          <template v-if="isHiddenValue(formatChangeLogWho(row))">
            <HilosHiddenMark /><template v-if="row.actorId !== null">
              (#{{ row.actorId }})</template
            >
          </template>
          <template v-else>{{ formatChangeLogWho(row) }}</template>
        </span>
        <div v-if="row.subjectId !== null" class="small text-body-secondary">
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
      <template #cell-action="{ row }">
        <code v-if="row.receiptId !== null">{{ row.action ?? '—' }}</code>
        <span v-else class="text-body-secondary small">{{
          CHANGE_LOG_NO_RECEIPT_TEXT
        }}</span>
      </template>
      <template #cell-touched="{ row }">
        <code class="small text-body-secondary">{{
          formatChangeLogTouched(row.touched)
        }}</code>
      </template>
      <template #cell-actions="{ row }">
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary text-wrap"
          :data-id="`hilos-change-log-open-${row.rowKey}`"
          data-hilos-focus-return="change-log-open"
          @click="openDetails(row)"
        >
          {{ formatChangeLogFeedOpenLabel(row) }}
        </button>
      </template>
      <template #empty>
        <div data-id="hilos-change-log-feed-empty" :data-state="empty.kind">
          <i
            class="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
            aria-hidden="true"
          ></i>
          <div class="fw-semibold small mb-1">{{ empty.title }}</div>
          <p class="small text-body-secondary mb-3">{{ empty.hint }}</p>
          <HilosLink
            v-if="empty.kind === 'untracked' && tablesPath !== undefined"
            :to="tablesPath"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-change-log-open-tables"
            >{{ CHANGE_LOG_OPEN_TABLES_LABEL }}</HilosLink
          >
        </div>
      </template>
    </HilosViewportTable>
    <HilosChangeLogReceiptModal :reader="reader" :dialog="dialog" />
  </HilosAdminPage>
</template>
