<!-- The Tracked tables page draws the core's viewport and live schema metadata.
The opening page response supplies the complete noisy-column snapshot; filters
apply only to the tables viewport. Bootstrap classes only. -->
<script setup lang="ts">
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_COLUMN_MODES_TITLE,
  CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_NOISY_COLUMNS_TITLE,
  CHANGE_LOG_TABLES_NOTE,
  CHANGE_LOG_TABLES_OPEN_LABEL,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
  HilosPages,
  readHilosChangeLogNoisyColumns,
  type HilosChangeLogContext,
  type HilosChangeLogTablesRow,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, useId } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project's connection and page scope. */
  context: HilosChangeLogContext
}>()

const router = inject(hilosRouterKey)
const noisyHeadingId = useId()
const pageNoisyColumns = useSignal(
  props.context.scopes.pageDataSignal(CHANGE_LOG_NOISY_COLUMNS_SECTION),
)
const noisyColumns = computed(() =>
  readHilosChangeLogNoisyColumns(pageNoisyColumns.value),
)
const tables = createHilosChangeLogTablesTable(props.context)

/** Resolve the optional detail link once for each drawn actions cell. */
function tablePaths(row: HilosChangeLogTablesRow): string[] {
  const path = row.journaled
    ? router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, { tableId: row.name })
    : undefined
  return path === undefined ? [] : [path]
}

onMounted(() => tables.start())
onUnmounted(() => tables.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.CHANGE_LOG_TABLES">
    <p class="text-body-secondary">{{ CHANGE_LOG_TABLES_NOTE }}</p>

    <HilosViewportTable :controller="tables.controller">
      <template #cell-name="{ row }">
        <code
          class="text-break"
          :class="{ 'text-body-secondary': !row.journaled }"
          >{{ row.name }}</code
        >
        <div v-if="!row.journaled" class="small text-body-secondary">
          {{ CHANGE_LOG_TABLES_UNTRACKED_NOTE }}
        </div>
      </template>
      <template #cell-owner="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableOwner(row.owner)
        }}</span>
      </template>
      <template #cell-columns="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogColumnCounts(row.columns)
        }}</span>
      </template>
      <template #cell-changesLast24h="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableChanges(row.changesLast24h)
        }}</span>
      </template>
      <template #cell-lastChangeAt="{ row }">
        <span :class="{ 'text-body-secondary': !row.journaled }">{{
          formatChangeLogTableLastChange(row.lastChangeAt)
        }}</span>
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          v-for="path in tablePaths(row)"
          :key="path"
          :to="path"
          class="btn btn-sm btn-outline-secondary"
          :data-id="`hilos-change-log-open-table-${row.name}`"
          :aria-label="`${CHANGE_LOG_TABLES_OPEN_LABEL} ${row.name}`"
          >{{ CHANGE_LOG_TABLES_OPEN_LABEL }}</HilosLink
        >
      </template>
    </HilosViewportTable>

    <section class="mt-4" data-id="hilos-change-log-noisy-columns">
      <h2 :id="noisyHeadingId" class="h5">
        {{ CHANGE_LOG_NOISY_COLUMNS_TITLE }}
      </h2>
      <p class="small text-body-secondary">
        {{ CHANGE_LOG_NOISY_COLUMNS_NOTE }}
      </p>
      <p v-if="noisyColumns === null" class="text-body-secondary">—</p>
      <p v-else-if="noisyColumns.length === 0" class="text-body-secondary">
        {{ CHANGE_LOG_NOISY_COLUMNS_EMPTY }}
      </p>
      <template v-else>
        <div class="d-none d-md-block">
          <table
            class="table table-sm align-middle mb-0"
            :aria-labelledby="noisyHeadingId"
          >
            <thead>
              <tr>
                <th scope="col">{{ CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL }}</th>
                <th scope="col">{{ CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="column in noisyColumns"
                :key="`${column.table}.${column.column}`"
              >
                <td class="font-monospace text-break">
                  {{ column.table }}.{{ column.column }}
                </td>
                <td class="small text-break">{{ column.reason }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="d-md-none d-flex flex-column gap-2">
          <div
            v-for="column in noisyColumns"
            :key="`${column.table}.${column.column}`"
            class="border rounded-3 p-3"
          >
            <div class="font-monospace text-break mb-1">
              {{ column.table }}.{{ column.column }}
            </div>
            <p class="small text-body-secondary text-break mb-0">
              {{ column.reason }}
            </p>
          </div>
        </div>
      </template>
    </section>

    <section class="mt-4" data-id="hilos-change-log-column-modes">
      <h2 class="h5">{{ CHANGE_LOG_COLUMN_MODES_TITLE }}</h2>
      <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <div
          v-for="mode in CHANGE_LOG_COLUMN_MODES"
          :key="mode.key"
          class="col"
        >
          <div class="border rounded-3 p-3 h-100">
            <span class="badge text-wrap mb-2" :class="mode.tone">
              <i :class="`bi ${mode.icon} me-1`" aria-hidden="true"></i>
              {{ mode.label }}
            </span>
            <p class="small text-body-secondary mb-0">{{ mode.description }}</p>
          </div>
        </div>
      </div>
    </section>
  </HilosAdminPage>
</template>
