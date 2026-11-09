<script setup lang="ts">
import { computed, nextTick, onUpdated, shallowRef, watch } from 'vue'
import {
  subscribeSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
} from '@hilos/core/base'

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
const group = shallowRef<HTMLElement | null>(null)
const owner = shallowRef({ picker: props.picker })
const focused = shallowRef<{
  edge: DateTimePickerEdge
  part: DateTimePickerPart
  input: HTMLInputElement
} | null>(null)

watch(
  () => props.picker,
  (picker, _, onCleanup) => {
    owner.value = { picker }
    state.value = picker.state.get()
    disabled.value = picker.disabled.get()
    busy.value = picker.busy.get()
    const stops = [
      subscribeSignal(picker.state, (value) => (state.value = value)),
      subscribeSignal(picker.disabled, (value) => (disabled.value = value)),
      subscribeSignal(picker.busy, (value) => (busy.value = value)),
    ]
    onCleanup(() => stops.forEach((stop) => stop()))
  },
  { immediate: true },
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
  if (focused.value === null) return null
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

function focusPart(
  event: FocusEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (source !== owner.value) return
  focused.value = { edge, part, input: event.currentTarget as HTMLInputElement }
}

function leavePart(
  event: FocusEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (source !== owner.value) return
  if (focused.value?.input === event.currentTarget) focused.value = null
  source.picker.leavePart(edge, part)
}

function inputs(): HTMLInputElement[] {
  return Array.from(
    group.value?.querySelectorAll<HTMLInputElement>(
      'input[role="spinbutton"]',
    ) ?? [],
  )
}

async function moveFocus(
  index: number,
  source: typeof owner.value,
  input: HTMLInputElement,
): Promise<void> {
  await nextTick()
  if (
    source !== owner.value ||
    !group.value?.contains(input) ||
    input.ownerDocument.activeElement !== input
  )
    return
  inputs()[index]?.focus()
}

function onKeydown(
  event: KeyboardEvent,
  source: typeof owner.value,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
): void {
  if (source !== owner.value || event.ctrlKey || event.metaKey || event.altKey)
    return
  const input = event.currentTarget as HTMLInputElement
  const index = inputs().indexOf(input)
  switch (event.key) {
    case 'ArrowLeft':
    case 'ArrowRight':
      event.preventDefault()
      void moveFocus(
        index + (event.key === 'ArrowLeft' ? -1 : 1),
        source,
        input,
      )
      break
    case 'ArrowUp':
    case 'ArrowDown':
      event.preventDefault()
      source.picker.stepPart(edge, part, event.key === 'ArrowUp' ? 1 : -1)
      break
    case 'Backspace':
    case 'Delete':
      event.preventDefault()
      source.picker.clearPart(edge, part)
      break
    default:
      if (
        /^[0-9]$/.test(event.key) &&
        part !== 'meridiem' &&
        part !== 'offset'
      ) {
        event.preventDefault()
        if (source.picker.inputDigit(edge, part, event.key))
          void moveFocus(index + 1, source, input)
      } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
        event.preventDefault()
        source.picker.setMeridiem(
          edge,
          event.key.toLowerCase() === 'a' ? 'am' : 'pm',
        )
      }
  }
}
</script>

<template>
  <span :id="`${id}-label`" class="form-label d-block small mb-1">{{
    label
  }}</span>
  <div class="position-relative">
    <div class="input-group input-group-sm flex-nowrap">
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
      >
        <template
          v-for="{ edge, snapshot, owner: source } in endpoints"
          :key="edge"
        >
          <span
            v-if="edge === 'end'"
            class="mx-1 text-body-secondary"
            aria-hidden="true"
            >–</span
          >
          <span
            class="d-inline-flex flex-nowrap align-items-center text-nowrap"
          >
            <template v-for="part in snapshot.parts" :key="part.part">
              <span aria-hidden="true">{{ part.prefix }}</span>
              <input
                type="text"
                role="spinbutton"
                autocomplete="off"
                :spellcheck="false"
                :size="Math.max(part.text.length, part.placeholder.length)"
                :value="part.text || part.placeholder"
                class="border-0 p-0 text-center"
                :class="
                  focused?.edge === edge && focused.part === part.part
                    ? 'text-bg-primary'
                    : part.text === ''
                      ? 'bg-transparent text-body-tertiary'
                      : 'bg-transparent text-body'
                "
                :aria-label="partLabel(edge, part.label)"
                :aria-valuemin="part.valueMin"
                :aria-valuemax="part.valueMax"
                :aria-valuenow="part.valueNow ?? undefined"
                :aria-valuetext="part.valueText"
                :aria-invalid="isInvalid(snapshot) || undefined"
                :aria-describedby="description(edge)"
                :disabled="disabled || busy"
                @keydown="onKeydown($event, source, edge, part.part)"
                @beforeinput.prevent
                @focus="focusPart($event, source, edge, part.part)"
                @blur="leavePart($event, source, edge, part.part)"
              />
            </template>
          </span>
        </template>
      </div>
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
  </div>
</template>
