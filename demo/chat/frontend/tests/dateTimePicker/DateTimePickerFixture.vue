<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue'
import {
  createDateTimePickerController,
  createSignal,
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  subscribeSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerFactors,
  type DateTimePickerKind,
} from '@hilos/core/base'
import { HilosDateTimePicker, HilosModal, useSignal } from '@hilos/vue/base'

// Explicit examples of presentation factors; the browser locale supplies none.
const FACTORS = {
  'en-GB': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
  },
  'en-US': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [6, 0],
    dateOrder: ['month', 'day', 'year'],
    hourCycle: 'h12',
  },
  'pl-PL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
    dateSeparator: '.',
    placeholders: {
      ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
      year: 'rrrr',
    },
    monthNames: [
      { short: 'sty', full: 'styczeń' },
      { short: 'lut', full: 'luty' },
      { short: 'mar', full: 'marzec' },
      { short: 'kwi', full: 'kwiecień' },
      { short: 'maj', full: 'maj' },
      { short: 'cze', full: 'czerwiec' },
      { short: 'lip', full: 'lipiec' },
      { short: 'sie', full: 'sierpień' },
      { short: 'wrz', full: 'wrzesień' },
      { short: 'paź', full: 'październik' },
      { short: 'lis', full: 'listopad' },
      { short: 'gru', full: 'grudzień' },
    ],
    weekdayNames: [
      { short: 'niedz', full: 'niedziela' },
      { short: 'pon', full: 'poniedziałek' },
      { short: 'wt', full: 'wtorek' },
      { short: 'śr', full: 'środa' },
      { short: 'czw', full: 'czwartek' },
      { short: 'pt', full: 'piątek' },
      { short: 'sob', full: 'sobota' },
    ],
  },
  'en-IL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [5, 6],
  },
} satisfies Record<string, DateTimePickerFactors>

type FixturePicker = DateTimePickerController<
  DateTimePickerKind,
  DateTimePickerCount
>

const kind = ref<DateTimePickerKind>('datetime')
const count = ref<DateTimePickerCount>('single')
const factors = ref<keyof typeof FACTORS>('en-GB')
const zone = ref('Europe/Warsaw')
const today = ref('2026-10-13')
const minDate = ref('')
const seconds = ref(false)
const busySignal = createSignal(false)
const disabledSignal = createSignal(false)
const busy = useSignal(busySignal)
const disabled = useSignal(disabledSignal)
const changes = ref(0)
const mountId = ref(0)
const inModal = ref(false)
const dark = ref(false)

function createPicker(): FixturePicker {
  const currentToday = today.value
  const currentMin = minDate.value
  const options = {
    count: count.value,
    value: null,
    factors: FACTORS[factors.value],
    showSeconds: seconds.value,
    busy: () => busySignal.get(),
    disabled: () => disabledSignal.get(),
    onChange: () => changes.value++,
  }
  switch (kind.value) {
    case 'date':
      return createDateTimePickerController({
        ...options,
        kind: 'date',
        today: () => currentToday,
        ...(currentMin ? { minDate: currentMin } : {}),
      })
    case 'time':
      return createDateTimePickerController({ ...options, kind: 'time' })
    case 'datetime':
      return createDateTimePickerController({
        ...options,
        kind: 'datetime',
        timeZone: zone.value,
        today: () => currentToday,
        ...(currentMin ? { minDate: currentMin } : {}),
      })
  }
}

const picker = shallowRef(createPicker())
const result = shallowRef(picker.value.state.get().result)
const label = computed(() =>
  kind.value === 'datetime' ? `Starts, ${zone.value}` : 'Starts',
)
const canLoad = computed(
  () => kind.value === 'datetime' && zone.value !== 'Nowhere/Zone',
)

watch(
  picker,
  (current, _, onCleanup) => {
    result.value = current.state.get().result
    onCleanup(
      subscribeSignal(current.state, (snapshot) => {
        result.value = snapshot.result
      }),
    )
  },
  { immediate: true, flush: 'sync' },
)
watch([kind, count, zone, today, minDate], () => {
  picker.value.dispose()
  picker.value = createPicker()
})
watch(factors, (value) => picker.value.setFactors(FACTORS[value]))
watch(seconds, (value) => picker.value.setShowSeconds(value))
watch(dark, (value) =>
  document.documentElement.setAttribute(
    'data-bs-theme',
    value ? 'dark' : 'light',
  ),
)
onScopeDispose(() => picker.value.dispose())

function loadValue(): void {
  if (canLoad.value) picker.value.setEndpointValue('start', 1792209600000)
}

/** Prepare initial-open time controls with their lock set before mounting. */
function mountOpenTime(): void {
  if (kind.value !== 'time' || count.value !== 'single') return
  const previous = picker.value.state.get().result
  const wasBusy = busySignal.get()
  const wasDisabled = disabledSignal.get()
  picker.value.dispose()
  busySignal.set(false)
  disabledSignal.set(false)
  const current = createPicker()
  if (previous.status === 'valid') current.setValue(previous.value)
  current.calendar.open()
  busySignal.set(wasBusy)
  disabledSignal.set(wasDisabled)
  picker.value = current
  mountId.value++
}
</script>

<template>
  <main class="container py-4" data-id="date-time-picker-fixture">
    <h1 class="h4 mb-4">DateTimePicker fixture</h1>
    <div class="row g-3 mb-4">
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-kind">Kind</label>
        <select
          id="fixture-kind"
          v-model="kind"
          class="form-select"
          data-id="fixture-kind"
        >
          <option value="date">date</option>
          <option value="time">time</option>
          <option value="datetime">datetime</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-count">Count</label>
        <select
          id="fixture-count"
          v-model="count"
          class="form-select"
          data-id="fixture-count"
        >
          <option value="single">single</option>
          <option value="range">range</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-factors">Display factors</label>
        <select
          id="fixture-factors"
          v-model="factors"
          class="form-select"
          data-id="fixture-factors"
        >
          <option value="en-GB" lang="en-GB">English (United Kingdom)</option>
          <option value="en-US" lang="en-US">English (United States)</option>
          <option value="pl-PL" lang="pl-PL">Polski (Polska)</option>
          <option value="en-IL" lang="en-IL">English (Israel)</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-zone">Time zone</label>
        <select
          id="fixture-zone"
          v-model="zone"
          class="form-select"
          data-id="fixture-zone"
        >
          <option value="Europe/Warsaw">Europe/Warsaw</option>
          <option value="UTC">UTC</option>
          <option value="Pacific/Apia">Pacific/Apia</option>
          <option value="Nowhere/Zone">Nowhere/Zone</option>
        </select>
      </div>
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-today">Today</label>
        <input
          id="fixture-today"
          v-model="today"
          class="form-control"
          type="text"
          data-id="fixture-today"
        />
      </div>
      <div class="col-sm-6 col-lg-4">
        <label class="form-label" for="fixture-min">Minimum day</label>
        <select
          id="fixture-min"
          v-model="minDate"
          class="form-select"
          data-id="fixture-min"
        >
          <option value="">none</option>
          <option value="2026-10-13">2026-10-13</option>
        </select>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-3 mb-4">
      <div class="form-check">
        <input
          id="fixture-seconds"
          v-model="seconds"
          type="checkbox"
          class="form-check-input"
          data-id="fixture-seconds"
        />
        <label class="form-check-label" for="fixture-seconds">Seconds</label>
      </div>
      <div class="form-check">
        <input
          id="fixture-busy"
          type="checkbox"
          class="form-check-input"
          data-id="fixture-busy"
          :checked="busy"
          @change="busySignal.set(($event.target as HTMLInputElement).checked)"
        />
        <label class="form-check-label" for="fixture-busy">Busy</label>
      </div>
      <div class="form-check">
        <input
          id="fixture-disabled"
          type="checkbox"
          class="form-check-input"
          data-id="fixture-disabled"
          :checked="disabled"
          @change="
            disabledSignal.set(($event.target as HTMLInputElement).checked)
          "
        />
        <label class="form-check-label" for="fixture-disabled">Disabled</label>
      </div>
      <div>
        <input
          id="fixture-dark"
          v-model="dark"
          type="checkbox"
          class="btn-check"
          data-id="fixture-dark"
        />
        <label class="btn btn-lg btn-outline-secondary" for="fixture-dark"
          >Dark theme</label
        >
      </div>
    </div>
    <div class="mb-4">
      <HilosDateTimePicker
        v-if="!inModal"
        id="fixture-picker"
        :key="mountId"
        :picker="picker"
        :label="label"
        data-id="fixture-picker"
      />
    </div>
    <button
      type="button"
      class="btn btn-lg btn-outline-primary"
      data-id="fixture-open-modal"
      @click="inModal = true"
    >
      Open in modal
    </button>
    <button
      type="button"
      class="btn btn-outline-primary"
      data-id="fixture-mount-open-time"
      :disabled="kind !== 'time' || count !== 'single'"
      @click="mountOpenTime"
    >
      Mount open time
    </button>
    <button
      type="button"
      class="btn btn-outline-primary"
      data-id="fixture-load"
      :disabled="!canLoad"
      aria-describedby="fixture-load-description"
      @click="loadValue"
    >
      Load 1792209600000
    </button>
    <p id="fixture-load-description" class="form-text">
      Load the start of a datetime field in a usable time zone.
    </p>
    <div class="mb-2">
      <label class="form-label me-2" for="fixture-result">Result</label>
      <output
        id="fixture-result"
        class="font-monospace text-break"
        data-id="fixture-result"
        >{{ JSON.stringify(result) }}</output
      >
    </div>
    <div>
      <label class="form-label me-2" for="fixture-changes">Changes</label>
      <output id="fixture-changes" data-id="fixture-changes">{{
        changes
      }}</output>
    </div>
    <HilosModal v-model="inModal" title="Schedule maintenance">
      <div class="mb-3">
        <label class="form-label" for="fixture-modal-title">Title</label>
        <input
          id="fixture-modal-title"
          type="text"
          class="form-control form-control-lg"
          value="Database upgrade"
          data-id="fixture-modal-title"
          data-autofocus
        />
      </div>
      <HilosDateTimePicker
        id="fixture-modal-picker"
        :key="mountId"
        :picker="picker"
        :label="label"
        data-id="fixture-modal-picker"
      />
      <p class="form-text mt-2 mb-0">Choose when maintenance starts.</p>
      <div class="mt-3">
        <label class="form-label" for="fixture-modal-description"
          >What visitors read</label
        >
        <textarea
          id="fixture-modal-description"
          class="form-control form-control-lg"
          rows="3"
          data-id="fixture-modal-description"
        ></textarea>
      </div>
      <p class="small text-body-secondary mt-2 mb-0">
        This is an advance announcement. It does not close the service; the
        freeze in Maintenance starts the actual work.
      </p>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-lg btn-secondary"
          @click="requestClose"
        >
          Cancel
        </button>
        <button
          type="button"
          class="btn btn-lg btn-primary"
          @click="requestClose"
        >
          Schedule
        </button>
      </template>
    </HilosModal>
  </main>
</template>
