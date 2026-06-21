<!-- HilosViewportTable — the thin Vue view over the SERVER-WINDOWED
TableViewportController. Search, sort, and paging change the viewport descriptor
and are sent to the backend (NO local filtering); live changes arrive as pending
and are resolved with the Apply button. A removed row renders as a placeholder in
its slot — the layout never collapses. It holds NO table logic
(multiframework-core.md): the controller owns the descriptor, pending, and Apply.
Body cells come from the `#row` slot; the placeholder, header, paging, and the
pending bar stay framework-owned. (Distinct from HilosTable, the client-side
view.) -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { HilosTableColumn, TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The headless server-windowed controller driving rows, descriptor, and pending. */
    controller: TableViewportController<R>
    /** Column declarations for the header (labels and sort controls). */
    columns: HilosTableColumn[]
    /** Show the search box above the table. */
    searchable?: boolean
    /** Placeholder for the search box. */
    searchPlaceholder?: string
    /** Message shown when there are no rows. */
    emptyText?: string
    /** Message shown while the first window is still loading. */
    loadingText?: string
    /** Label shown in a removed row's placeholder slot. */
    placeholderText?: string
  }>(),
  {
    searchable: false,
    searchPlaceholder: 'Search…',
    emptyText: 'No rows.',
    loadingText: 'Loading…',
    placeholderText: 'Removed',
  },
)

const rows = useSignal(props.controller.rows)
const search = useSignal(props.controller.search)
const sort = useSignal(props.controller.sort)
const page = useSignal(props.controller.page)
const pageCount = useSignal(props.controller.pageCount)
const totalCount = useSignal(props.controller.totalCount)
const pendingCount = useSignal(props.controller.pendingCount)
const listChanged = useSignal(props.controller.listChanged)
const loaded = useSignal(props.controller.loaded)

const paginated = computed(() => pageCount.value > 1)

// A row with an unapplied pending change gets a subtle, theme-aware tint that
// stands out from the zebra striping: amber for a waiting update, red for a
// waiting removal. Bootstrap's contextual row classes carry their own dark-mode
// variants, so they adapt to the active theme with no custom style layer.
const PENDING_ROW_CLASS: Record<'update' | 'remove', string> = {
  update: 'table-warning',
  remove: 'table-danger',
}

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
  <div data-id="hilos-viewport-table">
    <div
      v-if="searchable || pendingCount > 0"
      class="d-flex justify-content-between align-items-center gap-2 mb-3"
    >
      <input
        v-if="searchable"
        type="search"
        class="form-control"
        :placeholder="searchPlaceholder"
        :value="search"
        data-id="hilos-table-search"
        @input="onSearchInput"
      />
      <button
        v-if="pendingCount > 0"
        type="button"
        class="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center gap-2 ms-auto"
        data-id="hilos-table-apply"
        @click="controller.apply()"
      >
        Apply changes
        <span class="badge text-bg-light" data-id="hilos-table-pending">
          {{ pendingCount }}
        </span>
      </button>
    </div>

    <div
      v-if="listChanged"
      class="alert alert-warning py-2"
      role="status"
      data-id="hilos-table-list-changed"
    >
      The list changed — apply to refresh.
    </div>

    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
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
            :class="view.pending ? PENDING_ROW_CLASS[view.pending] : null"
          >
            <td
              v-if="view.placeholder || view.row === null"
              :colspan="columns.length"
              class="text-center text-muted fst-italic"
              data-id="hilos-table-placeholder"
            >
              {{ placeholderText }}
            </td>
            <slot v-else name="row" :row="view.row" :row-key="view.rowKey" />
          </tr>
          <tr v-if="rows.length === 0">
            <td :colspan="columns.length" class="text-center text-muted py-4">
              <span
                v-if="!loaded"
                class="d-inline-flex align-items-center gap-2"
                data-id="hilos-table-loading"
              >
                <span
                  class="spinner-border spinner-border-sm"
                  aria-hidden="true"
                ></span>
                {{ loadingText }}
              </span>
              <slot v-else name="empty">{{ emptyText }}</slot>
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
