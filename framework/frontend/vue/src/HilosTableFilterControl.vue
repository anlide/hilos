<!-- HilosTableFilterControl — one declared filter of a table's bar, in whichever
of the three shapes the page declared it: a dropdown, a date range, a toggle. The
set of shapes is closed (tableFrame.ts, HilosTableFilter), so each of them has
exactly one way of being drawn and the branch on `kind` lives in this one place.
Every change is a call into the controller — a filter is never applied on the
client — and a date range sends BOTH of its bounds in one window change, because
two calls would show a window filtered by a start with no end. Internal to the
Vue view layer on purpose: it is not exported from index.ts, for the reason the
bar is not. -->
<script setup lang="ts" generic="R">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import type {
  HilosTableFacetCount,
  HilosTableFilterView,
  TableViewportController,
} from '@hilos/core'

import HilosDropdown from './HilosDropdown.vue'
import type { HilosDropdownOption } from './hilosDropdown.js'

const props = defineProps<{
  /** The declared filter together with the value it currently holds. */
  view: HilosTableFilterView
  /** The headless server-windowed controller every change is written into. */
  controller: TableViewportController<R>
}>()

// A date range is one control over two keys, and the lower one names it: every
// handle of this filter has to be found by one word from outside.
const dataId = computed(() => {
  const filter = props.view.filter

  return `hilos-table-filter-${filter.kind === 'date_range' ? filter.fromKey : filter.key}`
})

// --- select ---------------------------------------------------------------

// The value of an option is declared `unknown`, and the dropdown is typed
// `string | number` — so what travels through the primitive is the option's
// PLACE in the declared list, and the declared value itself is read back out of
// that list. Casting the value to a string would lose its type on the way back.
const NO_CHOICE = -1

const declaredOptions = computed(() =>
  props.view.filter.kind === 'select' ? props.view.filter.options() : [],
)

// Read fresh inside a computed rather than copied into state: options that
// arrive in the page scope later — the channels of a delivery log — redraw the
// control on their own, and a copy is exactly what would freeze them.
const dropdownOptions = computed<HilosDropdownOption<number>[]>(() => {
  const filter = props.view.filter
  const anyLabel = filter.kind === 'select' ? (filter.anyLabel ?? 'Any') : 'Any'

  return [
    { value: NO_CHOICE, label: anyLabel },
    ...declaredOptions.value.map((option, index) => ({
      value: index,
      label: option.label,
    })),
  ]
})

const selectedIndex = computed(() =>
  props.view.active
    ? declaredOptions.value.findIndex(
        (option) => option.value === props.view.value,
      )
    : NO_CHOICE,
)

function onSelect(index: number): void {
  const filter = props.view.filter
  if (filter.kind !== 'select') {
    return
  }
  props.controller.setFilter(
    filter.key,
    index === NO_CHOICE ? undefined : declaredOptions.value[index]?.value,
  )
}

// --- select counts --------------------------------------------------------

// The number beside an option is read out of the counts by the option's place,
// the same place the primitive carries: "no choice" answers with the set the
// filter lifted, a declared option with its own count, found under the text of
// its value — which is how the server keys it.
function facetCount(index: number): HilosTableFacetCount | undefined {
  const facets = props.view.facets
  if (facets === null) {
    return undefined
  }
  if (index === NO_CHOICE) {
    return facets.any
  }
  const option = declaredOptions.value[index]

  return option === undefined
    ? undefined
    : facets.options.get(String(option.value))
}

// Three forms and no more: the number, "500+" where the count stopped at its
// ceiling, and 0 — which is written, because "this leaves nothing" is the point.
// Null where there is no count to write, and nothing is drawn there.
function facetText(index: number): string | null {
  const count = facetCount(index)
  if (count === undefined) {
    return null
  }

  return count.exact ? String(count.count) : `${count.count}+`
}

function facetDataId(index: number): string {
  const filter = props.view.filter
  const option = declaredOptions.value[index]
  const key = filter.kind === 'select' ? filter.key : dataId.value
  const value =
    index === NO_CHOICE || option === undefined ? 'any' : String(option.value)

  return `hilos-table-facet-${key}-${value}`
}

// --- date range -----------------------------------------------------------

const rangeOpen = ref(false)
const rangeRoot = ref<HTMLElement>()
const fromId = useId()
const toId = useId()

const bounds = computed(() => {
  const value = props.view.value

  if (
    props.view.filter.kind !== 'date_range' ||
    value === null ||
    value === undefined
  ) {
    return { from: '', to: '' }
  }
  const { from, to } = value as { from?: unknown; to?: unknown }

  return {
    from: from === undefined ? '' : String(from),
    to: to === undefined ? '' : String(to),
  }
})

// Four forms, one for each way a range can be half-open — a bound that is not
// there is left out of the sentence rather than shown as an empty side.
const rangeLabel = computed(() => {
  const label = props.view.filter.label
  const { from, to } = bounds.value

  if (from !== '' && to !== '') {
    return `${label}: ${from} – ${to}`
  }
  if (from !== '') {
    return `${label}: from ${from}`
  }
  if (to !== '') {
    return `${label}: until ${to}`
  }

  return label
})

function setBounds(from: string, to: string): void {
  const filter = props.view.filter
  if (filter.kind !== 'date_range') {
    return
  }
  props.controller.setFilters({ [filter.fromKey]: from, [filter.toKey]: to })
}

function onFrom(event: Event): void {
  setBounds((event.target as HTMLInputElement).value, bounds.value.to)
}

function onTo(event: Event): void {
  setBounds(bounds.value.from, (event.target as HTMLInputElement).value)
}

function onClearRange(): void {
  setBounds('', '')
}

// The SDK ships Bootstrap's CSS and not its JS, so opening and closing the panel
// is owned here, as it is in HilosDropdown.
function onDocumentClick(event: MouseEvent): void {
  if (rangeRoot.value && !rangeRoot.value.contains(event.target as Node)) {
    rangeOpen.value = false
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

// --- toggle ---------------------------------------------------------------

const toggleId = useId()

function onToggle(event: Event): void {
  const filter = props.view.filter
  if (filter.kind !== 'toggle') {
    return
  }
  props.controller.setFilter(
    filter.key,
    (event.target as HTMLInputElement).checked ? filter.on : undefined,
  )
}
</script>

<template>
  <div v-if="view.filter.kind === 'select'" :data-id="dataId">
    <HilosDropdown
      :model-value="selectedIndex"
      :options="dropdownOptions"
      :menu-aria-label="view.filter.label"
      @update:model-value="onSelect"
    >
      <template #toggle="{ label }">
        <span class="text-truncate">{{ view.filter.label }}: {{ label }}</span>
      </template>
      <!-- Only once counts have arrived: until then, and for a table that does not
      count, the list is the one the primitive draws, not one with blanks in it.
      The item keeps the primitive's own shape, and the number stands at the right,
      muted on every item but the picked one, where it would not read. -->
      <template
        v-if="view.facets !== null"
        #option="{ option, selected, select }"
      >
        <button
          type="button"
          class="dropdown-item d-flex align-items-center justify-content-between gap-2"
          :class="{ active: selected }"
          :disabled="option.disabled"
          role="option"
          :aria-selected="selected"
          :data-id="`hilos-dropdown-option-${option.value}`"
          @click="select"
        >
          <span class="text-truncate">{{ option.label }}</span>
          <span
            v-if="facetText(option.value) !== null"
            class="ms-auto small flex-shrink-0"
            :class="{ 'text-body-secondary': !selected }"
            :data-id="facetDataId(option.value)"
            >{{ facetText(option.value) }}</span
          >
        </button>
      </template>
    </HilosDropdown>
  </div>

  <div
    v-else-if="view.filter.kind === 'date_range'"
    ref="rangeRoot"
    class="dropdown"
  >
    <button
      type="button"
      class="btn btn-sm btn-outline-secondary"
      :aria-expanded="rangeOpen"
      :data-id="dataId"
      @click="rangeOpen = !rangeOpen"
      @keydown.esc.prevent="rangeOpen = false"
    >
      {{ rangeLabel }}
    </button>
    <div v-show="rangeOpen" class="dropdown-menu show p-3">
      <label :for="fromId" class="form-label small mb-1">From</label>
      <input
        :id="fromId"
        type="date"
        class="form-control form-control-sm mb-2"
        :value="bounds.from"
        :data-id="`${dataId}-from`"
        @input="onFrom"
      />
      <label :for="toId" class="form-label small mb-1">To</label>
      <input
        :id="toId"
        type="date"
        class="form-control form-control-sm mb-2"
        :value="bounds.to"
        :data-id="`${dataId}-to`"
        @input="onTo"
      />
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary w-100"
        :data-id="`${dataId}-clear`"
        @click="onClearRange"
      >
        Clear
      </button>
    </div>
  </div>

  <div
    v-else-if="view.filter.kind === 'toggle'"
    class="form-check form-switch mb-0"
  >
    <input
      :id="toggleId"
      class="form-check-input"
      type="checkbox"
      :checked="view.active"
      :data-id="dataId"
      @change="onToggle"
    />
    <label :for="toggleId" class="form-check-label">{{
      view.filter.label
    }}</label>
  </div>
</template>
