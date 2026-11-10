<script setup lang="ts">
import { computed, nextTick, onMounted, shallowRef } from 'vue'
import {
  revealNarrowPickerPanel,
  revealPickerPanel,
  type DateTimePickerCalendarSnapshot,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'
import {
  dateTimePickerPartInputMode,
  handleDateTimePickerPartInput,
  handleDateTimePickerPartKey,
} from './dateTimePickerPartKeys.js'

const props = defineProps<{
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  id: string
  calendar: DateTimePickerCalendarSnapshot
  state: DateTimePickerSnapshot<DateTimePickerKind, DateTimePickerCount>
  narrow: boolean
  describedBy?: string
  blocked: boolean
}>()

const owner = props.picker
const panel = shallowRef<HTMLElement | null>(null)
const focused = shallowRef<{
  edge: DateTimePickerEdge
  part: DateTimePickerPart
} | null>(null)
const endpoints = computed(() => [
  { edge: 'start' as const, snapshot: props.state.start },
  ...(props.state.end === null
    ? []
    : [{ edge: 'end' as const, snapshot: props.state.end }]),
])
const timeRows = computed(() =>
  props.picker.kind === 'date'
    ? []
    : endpoints.value
        .filter(({ edge, snapshot }) =>
          props.narrow && props.calendar.grid !== null
            ? edge === props.calendar.activeEdge
            : edge === 'start' ||
              props.picker.kind === 'time' ||
              hasDate(snapshot),
        )
        .map(({ edge, snapshot }) => ({
          edge,
          invalid: isInvalid(snapshot),
          label:
            props.picker.count === 'range'
              ? props.picker.labels[edge === 'start' ? 'from' : 'to']
              : props.picker.kind === 'datetime'
                ? props.picker.labels.time
                : null,
          parts: snapshot.parts.filter((part) =>
            ['hour', 'minute', 'second', 'meridiem'].includes(part.part),
          ),
        })),
)

function hasDate(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): boolean {
  return ['year', 'month', 'day'].every(
    (name) =>
      endpoint.parts.find((part) => part.part === name)?.valueNow != null,
  )
}

function isInvalid(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): boolean {
  return ['invalid', 'gap'].includes(endpoint.result.status)
}

function description(edge: DateTimePickerEdge): string {
  return [`${props.id}-${edge}-reason`, props.describedBy]
    .filter(Boolean)
    .join(' ')
}

function partLabel(edge: DateTimePickerEdge, label: string): string {
  if (props.picker.count === 'single') return label
  return `${props.picker.labels[edge === 'start' ? 'from' : 'to']} ${label}`
}

function endpointText(
  snapshot: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): string {
  return snapshot.parts
    .map((part) => part.prefix + (part.text || part.placeholder))
    .join('')
}

function isCurrent(target: EventTarget | null): boolean {
  const element = panel.value
  return (
    element !== null &&
    element.isConnected &&
    element.contains(target as Node | null) &&
    props.picker === owner &&
    owner.calendar.state.get().open
  )
}

function onGesture(event: Event, gesture: () => void): void {
  if (isCurrent(event.currentTarget)) gesture()
}

function onBeforeInput(
  event: InputEvent,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  const target = event.target
  handleDateTimePickerPartInput(
    event,
    props.picker,
    edge,
    part,
    () => panel.value,
    () => isCurrent(target),
  )
}

async function onDayKeydown(event: KeyboardEvent): Promise<void> {
  if (
    !isCurrent(event.currentTarget) ||
    event.ctrlKey ||
    event.metaKey ||
    event.altKey
  )
    return
  const element = panel.value
  if (!props.picker.calendar.pressKey(event.key, event.shiftKey)) return
  event.preventDefault()
  await nextTick()
  if (element !== null && panel.value === element && isCurrent(element))
    revealPickerPanel(element)
}

function onPartKeydown(
  event: KeyboardEvent,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (!isCurrent(event.currentTarget)) return
  const target = event.currentTarget
  if (event.key === 'Enter') {
    event.preventDefault()
    return
  }
  if (event.ctrlKey || event.metaKey || event.altKey) return
  handleDateTimePickerPartKey(
    event,
    props.picker,
    edge,
    part,
    () => panel.value,
    () => isCurrent(target),
  )
}

function leavePart(
  event: FocusEvent,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (!isCurrent(event.currentTarget)) return
  focused.value = null
  props.picker.leavePart(edge, part)
}

function stepPart(
  event: MouseEvent,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
  delta: 1 | -1,
): void {
  if (!isCurrent(event.currentTarget)) return
  const column = (event.currentTarget as HTMLElement).parentElement
  props.picker.stepPart(edge, part, delta)
  column?.querySelector<HTMLInputElement>('input')?.focus()
}

function offsetText(index: number, text: string): string {
  if (index === 0) return `${props.picker.labels.firstOccurrence} · ${text}`
  if (index === 1) return `${props.picker.labels.secondOccurrence} · ${text}`
  return text
}

onMounted(() => {
  const element = panel.value
  if (element !== null && isCurrent(element)) {
    if (props.narrow)
      revealNarrowPickerPanel(
        element,
        element.ownerDocument.getElementById(`${props.id}-label`) ?? element,
      )
    else revealPickerPanel(element)
  }
})
</script>

<template>
  <div
    :id="`${id}-panel`"
    ref="panel"
    role="dialog"
    tabindex="-1"
    :aria-label="
      picker.kind === 'time'
        ? picker.labels.chooseTime
        : picker.labels.chooseDate
    "
    :class="
      narrow
        ? 'mt-2 border rounded-3 bg-body p-2'
        : 'position-absolute top-100 start-0 z-3 mt-1 border rounded-3 shadow bg-body p-2'
    "
  >
    <template v-if="calendar.grid !== null">
      <div v-if="narrow && picker.count === 'range'" class="row g-2 mb-2">
        <div v-for="{ edge, snapshot } in endpoints" :key="edge" class="col-6">
          <button
            type="button"
            class="btn btn-sm w-100 text-start"
            :class="
              calendar.activeEdge === edge
                ? 'btn-outline-primary active'
                : 'btn-outline-secondary'
            "
            :aria-pressed="calendar.activeEdge === edge"
            @click="
              onGesture($event, () => picker.calendar.setActiveEdge(edge))
            "
          >
            <span class="d-block small opacity-75">{{
              picker.labels[edge === 'start' ? 'from' : 'to']
            }}</span>
            <span class="small">{{ endpointText(snapshot) }}</span>
          </button>
        </div>
      </div>
      <div class="d-flex align-items-center gap-1 mb-1">
        <button
          type="button"
          :class="
            narrow
              ? 'btn btn-lg btn-outline-secondary'
              : 'btn btn-sm btn-link text-body-secondary'
          "
          :aria-label="picker.labels.previousMonth"
          :disabled="!calendar.grid.canPreviousMonth"
          @click="onGesture($event, () => picker.calendar.showPreviousMonth())"
        >
          <i class="bi bi-chevron-left" aria-hidden="true" />
        </button>
        <div
          :id="`${id}-month`"
          class="flex-grow-1 text-center fw-semibold small"
          aria-live="polite"
        >
          {{ calendar.grid.title }}
        </div>
        <button
          type="button"
          :class="
            narrow
              ? 'btn btn-lg btn-outline-secondary'
              : 'btn btn-sm btn-link text-body-secondary'
          "
          :aria-label="picker.labels.nextMonth"
          :disabled="!calendar.grid.canNextMonth"
          @click="onGesture($event, () => picker.calendar.showNextMonth())"
        >
          <i class="bi bi-chevron-right" aria-hidden="true" />
        </button>
      </div>
      <table
        class="table table-sm table-borderless text-center mb-1"
        role="grid"
        :aria-labelledby="`${id}-month`"
        @pointerleave="onGesture($event, () => picker.calendar.hoverDay(null))"
      >
        <thead>
          <tr>
            <th
              v-for="weekday in calendar.grid.weekdays"
              :key="weekday.weekday"
              scope="col"
              class="fw-normal small text-body-secondary px-0 py-1"
              :class="{ 'bg-body-tertiary': weekday.weekend }"
              :aria-label="weekday.full"
            >
              {{ weekday.short }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(week, row) in calendar.grid.weeks" :key="row">
            <template v-for="(day, column) in week" :key="column">
              <td
                v-if="day === null"
                class="p-0"
                :class="{
                  'bg-body-tertiary': calendar.grid.weekdays[column]?.weekend,
                }"
              />
              <td
                v-else
                role="presentation"
                class="p-0"
                :class="
                  day.span === 'inside'
                    ? 'bg-primary-subtle'
                    : day.span === 'preview' || day.span === 'preview-end'
                      ? 'bg-secondary-subtle'
                      : day.weekend
                        ? 'bg-body-tertiary'
                        : ''
                "
              >
                <button
                  type="button"
                  role="gridcell"
                  class="btn w-100"
                  :class="[
                    narrow ? 'btn-lg px-0' : 'btn-sm px-2',
                    {
                      'btn-primary': day.selected,
                      'text-body-tertiary': !day.selected && day.disabled,
                      'border-primary':
                        !day.selected &&
                        (day.today || day.span === 'preview-end'),
                    },
                  ]"
                  :tabindex="day.focusTarget ? 0 : -1"
                  :aria-label="day.label"
                  :aria-selected="day.selected || undefined"
                  :aria-disabled="day.disabled || blocked || undefined"
                  :aria-current="day.today ? 'date' : undefined"
                  @click="
                    onGesture($event, () => picker.calendar.selectDay(day.date))
                  "
                  @pointerover="
                    onGesture($event, () => picker.calendar.hoverDay(day.date))
                  "
                  @keydown="onDayKeydown"
                  @keyup.space.prevent
                >
                  {{ day.day }}
                </button>
              </td>
            </template>
          </tr>
        </tbody>
      </table>
    </template>
    <div
      v-for="{ edge, label, parts, invalid } in timeRows"
      :key="edge"
      class="d-flex align-items-center gap-2"
      :class="picker.kind === 'time' ? 'py-1' : 'border-top pt-2 mt-1'"
    >
      <span v-if="label" class="small text-body-secondary flex-grow-1">{{
        label
      }}</span>
      <div
        class="d-flex align-items-center gap-1"
        :class="{ 'mx-auto': !label }"
      >
        <template v-for="part in parts" :key="part.part">
          <span
            v-if="part.part === 'minute' || part.part === 'second'"
            class="text-body-secondary"
            aria-hidden="true"
            >:</span
          >
          <div
            class="d-flex flex-column align-items-center"
            :class="{ 'gap-1': narrow }"
          >
            <button
              type="button"
              tabindex="-1"
              :class="
                narrow
                  ? 'btn btn-lg btn-outline-secondary'
                  : 'btn btn-link btn-sm p-0 lh-1 text-body-secondary'
              "
              :aria-label="`${picker.labels.increase} ${partLabel(edge, part.label)}`"
              :disabled="blocked"
              @click="stepPart($event, edge, part.part, 1)"
            >
              <i class="bi bi-chevron-up" aria-hidden="true" />
            </button>
            <input
              type="text"
              role="spinbutton"
              autocomplete="off"
              :inputmode="dateTimePickerPartInputMode(part.part)"
              :spellcheck="false"
              :size="Math.max(part.text.length, part.placeholder.length)"
              :value="part.text || part.placeholder"
              class="border-0 text-center"
              :class="[
                narrow
                  ? 'form-control form-control-lg w-auto px-2 py-2'
                  : 'p-0',
                focused?.edge === edge && focused.part === part.part
                  ? 'text-bg-primary'
                  : part.text === ''
                    ? 'bg-transparent text-body-tertiary'
                    : 'bg-transparent text-body',
              ]"
              :aria-label="partLabel(edge, part.label)"
              :aria-valuemin="part.valueMin"
              :aria-valuemax="part.valueMax"
              :aria-valuenow="part.valueNow ?? undefined"
              :aria-valuetext="part.valueText"
              :aria-invalid="invalid || undefined"
              :aria-describedby="description(edge)"
              :disabled="blocked"
              @beforeinput="onBeforeInput($event, edge, part.part)"
              @focus="
                onGesture($event, () => (focused = { edge, part: part.part }))
              "
              @blur="leavePart($event, edge, part.part)"
              @keydown="onPartKeydown($event, edge, part.part)"
            />
            <button
              type="button"
              tabindex="-1"
              :class="
                narrow
                  ? 'btn btn-lg btn-outline-secondary'
                  : 'btn btn-link btn-sm p-0 lh-1 text-body-secondary'
              "
              :aria-label="`${picker.labels.decrease} ${partLabel(edge, part.label)}`"
              :disabled="blocked"
              @click="stepPart($event, edge, part.part, -1)"
            >
              <i class="bi bi-chevron-down" aria-hidden="true" />
            </button>
          </div>
        </template>
      </div>
    </div>
    <template v-for="{ edge, snapshot } in endpoints" :key="edge">
      <div
        v-if="snapshot.reason"
        class="small pt-2"
        :class="{ 'text-danger-emphasis': isInvalid(snapshot) }"
      >
        {{ snapshot.reason }}
      </div>
      <div
        v-if="snapshot.offsetChoices.length > 0"
        class="mb-2 btn-group-vertical w-100"
        :class="narrow ? 'btn-group-lg' : 'btn-group-sm'"
        role="radiogroup"
        :aria-label="partLabel(edge, picker.labels.chooseOffset)"
      >
        <template
          v-for="(choice, index) in snapshot.offsetChoices"
          :key="choice.epochMs"
        >
          <input
            :id="`${id}-${edge}-offset-${index}`"
            type="radio"
            class="btn-check"
            :name="`${id}-${edge}-offset`"
            :checked="choice.selected"
            :disabled="blocked"
            @change="
              onGesture($event, () => picker.selectOffset(edge, choice.epochMs))
            "
          />
          <label
            class="btn btn-outline-secondary"
            :for="`${id}-${edge}-offset-${index}`"
          >
            {{ offsetText(index, choice.text) }}
          </label>
        </template>
      </div>
    </template>
    <button
      v-if="narrow"
      type="button"
      class="btn btn-lg btn-outline-secondary w-100 mt-2"
      @click="onGesture($event, () => picker.calendar.close())"
    >
      {{ picker.labels.done }}
    </button>
    <span
      v-if="picker.count === 'range'"
      class="visually-hidden"
      aria-live="polite"
    >
      {{
        `${picker.labels.chooseDate}: ${picker.labels[calendar.activeEdge === 'start' ? 'from' : 'to']}`
      }}
    </span>
  </div>
</template>
