<!-- HilosTable — the thin Vue view over the core TableController. It renders the
header (with per-column sort controls), the search box, the rows, and the
pagination; it holds NO table logic — search, sort, and paging are the
controller's (multiframework-core.md). Body cells come from the `#row` slot so
the consumer keeps full control of each cell (links, badges, actions); the
header, sorting, and paging stay framework-owned. An empty result renders the
`#empty` slot or a default message. -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { TableController } from '@hilos/core'

import { useSignal } from './useSignal.js'
import type { HilosTableColumn } from './hilosTable.js'

const props = withDefaults(
  defineProps<{
    /** The headless controller driving rows, search, sort, and paging. */
    controller: TableController<R>
    /** Column declarations for the header (labels and sort controls). */
    columns: HilosTableColumn[]
    /** Show the search box above the table. */
    searchable?: boolean
    /** Placeholder for the search box. */
    searchPlaceholder?: string
    /** Message shown when there are no rows. */
    emptyText?: string
  }>(),
  { searchable: false, searchPlaceholder: 'Search…', emptyText: 'No rows.' },
)

const rows = useSignal(props.controller.pageRows)
const search = useSignal(props.controller.search)
const sort = useSignal(props.controller.sort)
const page = useSignal(props.controller.page)
const pageCount = useSignal(props.controller.pageCount)
const totalCount = useSignal(props.controller.totalCount)
const pageSize = useSignal(props.controller.pageSize)

const paginated = computed(() => pageSize.value > 0 && pageCount.value > 1)

function sortIcon(key: string): string {
  if (sort.value?.field !== key) {
    return 'bi-arrow-down-up text-muted'
  }

  return sort.value.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
}

function onSearchInput(event: Event): void {
  props.controller.setSearch((event.target as HTMLInputElement).value)
}
</script>

<template>
  <div data-id="hilos-table">
    <div v-if="searchable" class="mb-3">
      <input
        type="search"
        class="form-control"
        :placeholder="searchPlaceholder"
        :value="search"
        data-id="hilos-table-search"
        @input="onSearchInput"
      />
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :class="column.headerClass"
            >
              <button
                v-if="column.sortable"
                type="button"
                class="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                :data-id="`hilos-table-sort-${column.key}`"
                @click="controller.setSort(column.key)"
              >
                {{ column.label }}
                <i :class="['bi', sortIcon(column.key)]" aria-hidden="true"></i>
              </button>
              <template v-else>{{ column.label }}</template>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="view in rows"
            :key="view.rowKey"
            :data-id="`hilos-table-row-${view.rowKey}`"
          >
            <slot name="row" :row="view.row" :row-key="view.rowKey" />
          </tr>
          <tr v-if="rows.length === 0">
            <td :colspan="columns.length" class="text-center text-muted py-4">
              <slot name="empty">{{ emptyText }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="paginated"
      class="d-flex justify-content-between align-items-center mt-3"
    >
      <span class="text-muted small" data-id="hilos-table-count">
        {{ totalCount }} total
      </span>
      <div class="btn-group" role="group" aria-label="Pagination">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="page === 0"
          data-id="hilos-table-prev"
          @click="controller.setPage(page - 1)"
        >
          Previous
        </button>
        <span class="btn btn-sm disabled" data-id="hilos-table-page">
          {{ page + 1 }} / {{ pageCount }}
        </span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="page >= pageCount - 1"
          data-id="hilos-table-next"
          @click="controller.setPage(page + 1)"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>
