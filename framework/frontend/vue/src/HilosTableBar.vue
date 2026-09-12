<!-- HilosTableBar — the strip above a table, drawn from what the page DECLARED
(HilosTableFrame) and never from props of its own: the title and subtitle, the
search box, the declared filters, and the one main action pinned right. It holds
NO table logic — the controller owns the descriptor, and every control here is a
call into it (multiframework-core.md). Internal to the Vue view layer on purpose:
it is not exported from index.ts, because a bar has no meaning away from the
table it sits on (mockups/components/table section 7). -->
<script setup lang="ts" generic="R">
import { computed, ref } from 'vue'
import { HILOS_TABLE_OPENING_ORDER_KEY, TABLE_ORDER_COPY } from '@hilos/core'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

import HilosDropdown from './HilosDropdown.vue'
import HilosModal from './HilosModal.vue'
import HilosTableFilterControl from './HilosTableFilterControl.vue'
import type { HilosDropdownOption } from './hilosDropdown.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The headless server-windowed controller the bar reads and drives. */
  controller: TableViewportController<R>
  /**
   * The id the title carries, so the table below can name itself with
   * aria-labelledby: the accessible name of the table is the very heading a
   * sighted person reads, and the id has to be minted where both of them can
   * see it.
   */
  titleId: string
}>()

// The declaration does not change over the life of a table, so its parts are
// read once rather than wrapped in signals (tableFrame.ts, HilosTableFrameState).
const declaration = props.controller.frame.declaration
const title = declaration?.title ?? ''
const subtitle = declaration?.subtitle
const searchBox = declaration?.search
const mainAction = declaration?.mainAction

// The search placeholder doubles as the field's accessible name: the field
// carries no visible label, and two different strings would name it twice.
const searchPlaceholder = searchBox?.placeholder ?? 'Search…'

const search = useSignal(props.controller.search)
const filters = useSignal(props.controller.frame.filters)
const activeFilterCount = useSignal(props.controller.frame.activeFilterCount)
const orders = useSignal(props.controller.frame.orders)
const orderLabel = useSignal(props.controller.frame.orderLabel)

// The badge counts the declared filters holding a value; the search box is not
// one of them and has its own field (tableFrame.ts, activeFilterCount).
const filterCountLabel = computed(() =>
  activeFilterCount.value === 1
    ? '1 filter'
    : `${activeFilterCount.value} filters`,
)

const orderOptions = computed<HilosDropdownOption<string>[]>(() =>
  orders.value.map(({ key, label }) => ({ value: key, label })),
)

// Null while the window runs in an order the menu does not offer — one that came
// from a click on a header. Saying so is the truth about how the rows lie;
// lighting up the nearest item instead would not be (tableFrame.ts, orderLabel).
const activeOrderKey = computed(
  () => orders.value.find((view) => view.active)?.key ?? null,
)

function onOrder(key: string): void {
  if (key === activeOrderKey.value) {
    // The window already runs in it, and asking for it again would cost a frame
    // from the server for a pick that changes nothing.
    return
  }
  if (key === HILOS_TABLE_OPENING_ORDER_KEY) {
    props.controller.resetOrder()

    return
  }
  const declared = props.controller.orders.find((order) => order.key === key)
  if (declared) {
    props.controller.setOrder(declared.components)
  }
}

// A date range is one control over two keys, and it is listed under the lower
// one — the same word the control answers to from outside.
function filterKey(view: HilosTableFilterView): string {
  return view.filter.kind === 'date_range'
    ? view.filter.fromKey
    : view.filter.key
}

// Narrow screens put the filters in a modal rather than an offcanvas of the
// SDK's own: the SDK ships Bootstrap's CSS and not its JS, so an offcanvas would
// be a second way of showing a surface over the screen — one every other view
// layer would then have to repeat (Design D8).
const filtersOpen = ref(false)

function onSearchInput(event: Event): void {
  props.controller.setSearch((event.target as HTMLInputElement).value)
}
</script>

<template>
  <div>
    <div class="mb-2">
      <h2 :id="titleId" class="h6 mb-0" data-id="hilos-table-title">
        {{ title }}
      </h2>
      <p
        v-if="subtitle"
        class="small text-body-secondary mb-0"
        data-id="hilos-table-subtitle"
      >
        {{ subtitle }}
      </p>
    </div>

    <div
      v-if="searchBox || filters.length > 0 || mainAction || orders.length > 0"
      class="d-flex flex-wrap align-items-center gap-2 mb-3"
    >
      <div
        v-if="searchBox"
        class="input-group input-group-sm w-auto flex-grow-1 flex-md-grow-0"
      >
        <span class="input-group-text">
          <i class="bi bi-search" aria-hidden="true"></i>
        </span>
        <input
          type="search"
          class="form-control"
          :placeholder="searchPlaceholder"
          :aria-label="searchPlaceholder"
          :value="search"
          data-id="hilos-table-search"
          @input="onSearchInput"
        />
        <button
          v-if="search !== ''"
          type="button"
          class="btn btn-outline-secondary"
          aria-label="Clear search"
          data-id="hilos-table-search-clear"
          @click="controller.setSearch('')"
        >
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Below md the controls leave the bar for the modal below, and the
      button that opens it takes their place. The search does not go with them:
      it is the main way to narrow a set, and hiding it behind a button would
      hide exactly what the table was opened for. -->
      <div
        v-if="filters.length > 0"
        class="d-none d-md-flex flex-wrap align-items-center gap-2"
      >
        <HilosTableFilterControl
          v-for="view in filters"
          :key="filterKey(view)"
          :view="view"
          :controller="controller"
        />
      </div>

      <span
        v-if="activeFilterCount > 0"
        class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
        data-id="hilos-table-filter-badge"
      >
        {{ filterCountLabel }}
        <button
          type="button"
          class="btn-close ms-1"
          aria-label="Reset filters"
          data-id="hilos-table-filter-reset"
          @click="controller.resetFilters()"
        ></button>
      </span>

      <!-- Outside the row that leaves the bar below md: the menu is the only way
      to change the order on a narrow screen, where the header of a column is out
      of reach, so hiding it behind the Filters button would remove it. -->
      <div v-if="orders.length > 0" class="w-auto" data-id="hilos-table-order">
        <HilosDropdown
          :model-value="activeOrderKey"
          :options="orderOptions"
          :menu-aria-label="TABLE_ORDER_COPY.menu"
          @update:model-value="onOrder"
        >
          <template #toggle>
            <span class="text-truncate"
              >{{ TABLE_ORDER_COPY.menu }}: {{ orderLabel }}</span
            >
          </template>
          <template #option="{ option, selected, select }">
            <button
              type="button"
              class="dropdown-item d-flex align-items-center justify-content-between gap-2"
              :class="{ active: selected }"
              role="option"
              :aria-selected="selected"
              :data-id="`hilos-table-order-${option.value}`"
              @click="select()"
            >
              <span class="text-truncate">{{ option.label }}</span>
              <i
                v-if="selected"
                class="bi bi-check2 flex-shrink-0"
                aria-hidden="true"
              ></i>
            </button>
          </template>
        </HilosDropdown>
      </div>

      <button
        v-if="filters.length > 0"
        type="button"
        class="btn btn-sm btn-outline-secondary d-md-none"
        data-id="hilos-table-filters-open"
        @click="filtersOpen = true"
      >
        Filters
        <span v-if="activeFilterCount > 0" class="badge text-bg-secondary ms-1">
          {{ activeFilterCount }}
        </span>
      </button>

      <button
        v-if="mainAction"
        type="button"
        class="btn btn-sm btn-primary ms-auto"
        data-id="hilos-table-main-action"
        @click="mainAction.press()"
      >
        {{ mainAction.label }}
      </button>
    </div>

    <!-- Under the same condition as the button that opens it: a table with no
    filters has nothing to show here, and a closed HilosModal is not free —
    mounting one releases the background scroll lock, which is not this table's
    to release. -->
    <HilosModal v-if="filters.length > 0" v-model="filtersOpen" title="Filters">
      <div class="d-flex flex-column gap-3">
        <HilosTableFilterControl
          v-for="view in filters"
          :key="filterKey(view)"
          :view="view"
          :controller="controller"
        />
      </div>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-primary"
          data-id="hilos-table-filters-done"
          @click="requestClose()"
        >
          Done
        </button>
      </template>
    </HilosModal>
  </div>
</template>
