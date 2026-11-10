<script setup lang="ts">
import {
  computed,
  nextTick,
  onScopeDispose,
  onUpdated,
  shallowRef,
  watch,
} from 'vue'
import {
  bindNarrowScreen,
  bindPopupDismiss,
  revealPickerPanel,
  subscribeSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
} from '@hilos/core/base'
import HilosDateTimePickerPanel from './HilosDateTimePickerPanel.vue'
import {
  dateTimePickerPartInputMode,
  handleDateTimePickerPartInput,
  handleDateTimePickerPartKey,
} from './dateTimePickerPartKeys.js'

const props = defineProps<{
  /** The parent's controller; the parent also owns its disposal. */
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  /** A stable id for the named group and its descriptions. */
  id: string
  /** The visible group name, including the configured zone for datetime. */
  label: string
  /** Additional description ids supplied by the mounting form. */
  describedBy?: string
  /** The mounting form's stable selector for the group. */
  dataId?: string
}>()

const state = shallowRef(props.picker.state.get())
const disabled = shallowRef(props.picker.disabled.get())
const busy = shallowRef(props.picker.busy.get())
const calendar = shallowRef(props.picker.calendar.state.get())
const narrow = shallowRef(false)
onScopeDispose(bindNarrowScreen((value) => (narrow.value = value)))
const root = shallowRef<HTMLElement | null>(null)
const opener = shallowRef<HTMLButtonElement | null>(null)
const group = shallowRef<HTMLElement | null>(null)
const owner = shallowRef({ picker: props.picker, active: true })
let returnFocus = true
let fieldPress: {
  source: typeof owner.value
  opens: boolean
} | null = null
const focused = shallowRef<{
  edge: DateTimePickerEdge
  part: DateTimePickerPart
  input: HTMLInputElement
} | null>(null)

watch(
  () => props.picker,
  (picker, previousPicker, onCleanup) => {
    if (previousPicker !== undefined) picker.calendar.close()
    const source = { picker, active: true }
    owner.value = source
    state.value = picker.state.get()
    disabled.value = picker.disabled.get()
    busy.value = picker.busy.get()
    calendar.value = picker.calendar.state.get()
    const stops = [
      subscribeSignal(picker.state, (value) => (state.value = value)),
      subscribeSignal(picker.disabled, (value) => (disabled.value = value)),
      subscribeSignal(picker.busy, (value) => (busy.value = value)),
      subscribeSignal(
        picker.calendar.state,
        (value) => (calendar.value = value),
      ),
    ]
    onCleanup(() => {
      source.active = false
      stops.forEach((stop) => stop())
      returnFocus = false
      picker.calendar.close()
    })
  },
  { immediate: true },
)

watch(
  [() => calendar.value.open, root, narrow],
  ([open, element, isNarrow], previous, onCleanup) => {
    if (open) {
      returnFocus = true
      if (element && !isNarrow)
        onCleanup(
          bindPopupDismiss(element, () => {
            returnFocus = false
            props.picker.calendar.close()
          }),
        )
    } else if (previous?.[0] && returnFocus) opener.value?.focus()
  },
  { flush: 'post', immediate: true },
)

// Capture focus before a width change removes a narrow-only control.
watch(narrow, async () => {
  if (!calendar.value.open) return
  const element = root.value
  const source = owner.value
  const target = element?.ownerDocument.activeElement
  if (
    element === null ||
    !(target instanceof HTMLElement) ||
    !element.contains(target)
  )
    return
  await nextTick()
  if (
    root.value === element &&
    owner.value === source &&
    calendar.value.open &&
    !target.isConnected &&
    element.ownerDocument.activeElement === element.ownerDocument.body
  )
    element
      .querySelector<HTMLElement>('[role="dialog"]')
      ?.focus({ preventScroll: true })
})

// Capture the focused control before native disabled can move focus to body.
watch(
  () => disabled.value || busy.value,
  async (blocked) => {
    if (!blocked || !calendar.value.open) return
    const element = root.value
    const source = owner.value
    const target = element?.ownerDocument.activeElement
    if (
      element === null ||
      !(
        target instanceof HTMLInputElement ||
        target instanceof HTMLButtonElement
      ) ||
      !element.contains(target)
    )
      return
    await nextTick()
    const active = element.ownerDocument.activeElement
    if (
      root.value === element &&
      owner.value === source &&
      calendar.value.open &&
      element.contains(target) &&
      target.disabled &&
      (active === target || active === element.ownerDocument.body)
    )
      element
        .querySelector<HTMLElement>('[role="dialog"]')
        ?.focus({ preventScroll: true })
  },
)

const endpoints = computed(() => [
  { edge: 'start' as const, snapshot: state.value.start, owner: owner.value },
  ...(state.value.end === null
    ? []
    : [
        {
          edge: 'end' as const,
          snapshot: state.value.end,
          owner: owner.value,
        },
      ]),
])

// Removing a focused part does not emit blur in every DOM implementation.
onUpdated(() => {
  const current = focused.value
  if (
    current !== null &&
    (!group.value?.contains(current.input) ||
      current.input.ownerDocument.activeElement !== current.input)
  )
    focused.value = null
})

const invalid = computed(() =>
  endpoints.value.some(({ snapshot }) => isInvalid(snapshot)),
)
const visibleReason = computed(() => {
  if (focused.value === null || calendar.value.open) return null
  const current = state.value[focused.value.edge]
  if (current?.reason) return current
  return (
    endpoints.value.find(({ snapshot }) => snapshot.reason)?.snapshot ?? null
  )
})

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

function isCurrent(target: EventTarget | null, source = owner.value): boolean {
  const element = root.value
  return (
    source.active &&
    source === owner.value &&
    source.picker === props.picker &&
    element !== null &&
    element.isConnected &&
    element.contains(target as Node | null)
  )
}

function focusPart(
  event: FocusEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (!isCurrent(event.target, source)) return
  focused.value = { edge, part, input: event.currentTarget as HTMLInputElement }
}

function leavePart(
  event: FocusEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (!isCurrent(event.target, source)) return
  if (focused.value?.input === event.currentTarget) focused.value = null
  source.picker.leavePart(edge, part)
}

function toggleCalendar(event: MouseEvent): void {
  if (!isCurrent(event.target)) return
  if (calendar.value.open) props.picker.calendar.close()
  else props.picker.calendar.open()
}

function onEscape(event: KeyboardEvent): void {
  if (!isCurrent(event.target) || !props.picker.calendar.state.get().open)
    return
  event.preventDefault()
  event.stopPropagation()
  props.picker.calendar.close()
}

function openFromField(): void {
  if (!calendar.value.open) props.picker.calendar.open()
  else {
    const panel = root.value?.querySelector<HTMLElement>('[role="dialog"]')
    if (panel) revealPickerPanel(panel)
  }
}

function onFieldMousedown(event: MouseEvent): void {
  const source = owner.value
  fieldPress = {
    source,
    opens:
      narrow.value &&
      !calendar.value.open &&
      focused.value === null &&
      isCurrent(event.target, source),
  }
  if (fieldPress.opens) event.preventDefault()
}

function onFieldClick(event: MouseEvent): void {
  const press = fieldPress
  fieldPress = null
  if (press === null || !narrow.value || !isCurrent(event.target, press.source))
    return
  if (press.opens) press.source.picker.calendar.open()
  else if (calendar.value.open) {
    returnFocus = !(event.target instanceof HTMLInputElement)
    press.source.picker.calendar.close()
  }
}

function onBeforeInput(
  event: InputEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  const target = event.target
  handleDateTimePickerPartInput(
    event,
    source.picker,
    edge,
    part,
    () => group.value,
    () => isCurrent(target, source),
  )
}

function onKeydown(
  event: KeyboardEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  const target = event.target
  if (!isCurrent(target, source)) return
  if (
    event.altKey &&
    !event.ctrlKey &&
    !event.metaKey &&
    event.key === 'ArrowDown'
  ) {
    event.preventDefault()
    openFromField()
    return
  }
  if (event.ctrlKey || event.metaKey || event.altKey) return
  handleDateTimePickerPartKey(
    event,
    source.picker,
    edge,
    part,
    () => group.value,
    () => isCurrent(target, source),
  )
}
</script>

<template>
  <span :id="`${id}-label`" class="form-label d-block small mb-1">{{
    label
  }}</span>
  <div ref="root" class="position-relative" @keydown.esc="onEscape">
    <div
      class="input-group flex-nowrap"
      :class="[
        narrow ? 'input-group-lg' : 'input-group-sm',
        { 'has-validation': invalid },
      ]"
    >
      <div
        :id="id"
        ref="group"
        class="form-control d-flex flex-wrap align-items-center"
        :class="{
          'is-invalid': invalid,
          'border-primary': focused !== null && !invalid,
          'bg-body-secondary': disabled || busy,
        }"
        role="group"
        :aria-labelledby="`${id}-label`"
        :data-id="dataId"
        @mousedown="onFieldMousedown"
        @click="onFieldClick"
      >
        <template
          v-for="{ edge, snapshot, owner: source } in endpoints"
          :key="edge"
        >
          <div
            class="d-inline-flex align-items-center"
            :class="
              narrow ? 'w-100 mw-100 flex-wrap' : 'flex-nowrap text-nowrap'
            "
          >
            <div
              v-for="(part, index) in snapshot.parts"
              :key="part.part"
              class="d-inline-flex align-items-center flex-shrink-0"
            >
              <span
                v-if="edge === 'end' && index === 0"
                class="mx-1 text-body-secondary"
                aria-hidden="true"
                >–</span
              >
              <span aria-hidden="true">{{ part.prefix }}</span>
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
                :aria-invalid="isInvalid(snapshot) || undefined"
                :aria-describedby="description(edge)"
                :disabled="disabled || busy"
                @keydown="onKeydown($event, source, edge, part.part)"
                @beforeinput="onBeforeInput($event, source, edge, part.part)"
                @focus="focusPart($event, source, edge, part.part)"
                @blur="leavePart($event, source, edge, part.part)"
              />
            </div>
          </div>
        </template>
      </div>
      <button
        ref="opener"
        type="button"
        class="btn btn-outline-secondary"
        :class="{ active: calendar.open }"
        :disabled="disabled || busy"
        :aria-label="
          picker.kind === 'time'
            ? picker.labels.chooseTime
            : picker.labels.openCalendar
        "
        :aria-expanded="calendar.open"
        aria-haspopup="dialog"
        :aria-controls="calendar.open ? `${id}-panel` : undefined"
        @click="toggleCalendar"
      >
        <i
          class="bi"
          :class="picker.kind === 'time' ? 'bi-clock' : 'bi-calendar3'"
          aria-hidden="true"
        />
      </button>
    </div>
    <span
      v-for="{ edge, snapshot } in endpoints"
      :id="`${id}-${edge}-reason`"
      :key="edge"
      class="visually-hidden"
      aria-live="polite"
      >{{ snapshot.reason ?? '' }}</span
    >
    <div
      v-if="visibleReason"
      :class="
        isInvalid(visibleReason)
          ? 'invalid-tooltip d-block'
          : 'position-absolute top-100 start-0 z-3 mw-100 mt-1 px-2 py-1 small rounded text-bg-secondary'
      "
      aria-hidden="true"
    >
      {{ visibleReason.reason }}
    </div>
    <HilosDateTimePickerPanel
      v-if="calendar.open"
      :id="id"
      :picker="picker"
      :calendar="calendar"
      :state="state"
      :narrow="narrow"
      :described-by="describedBy"
      :blocked="disabled || busy"
    />
  </div>
</template>
