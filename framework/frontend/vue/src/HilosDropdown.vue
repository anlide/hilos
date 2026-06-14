<!-- HilosDropdown — a tier-1 single-select dropdown on Bootstrap's `.dropdown`
markup with its own open/close logic (the SDK ships Bootstrap CSS, not its JS, so
toggling and outside-click are owned here, like HilosModal). It is slot-first:
`#toggle` (scoped: label / selected / open) replaces the button face and
`#option` (scoped: option / selected / select) replaces each item, so a project
customizes the look by filling slots, never by re-implementing the behavior —
"template inheritance" via slots (sdk-packaging.md). v-model is the selected
value. Outside-click and Escape close it, arrow keys rove the options, and the
listbox/option ARIA roles ship by default (a11y is v1, styling-rules.md).
Bootstrap classes only — no CSS of its own. -->
<script setup lang="ts" generic="V extends string | number">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import type { HilosDropdownOption } from './hilosDropdown.js'

const props = withDefaults(
  defineProps<{
    /** The selected option's value (v-model); null when nothing is chosen. */
    modelValue: V | null
    /** The selectable options, in display order. */
    options: HilosDropdownOption<V>[]
    /** Toggle label shown when no option is selected. */
    placeholder?: string
    /** Disable the whole control. */
    disabled?: boolean
    /** Accessible name for the options menu. */
    menuAriaLabel?: string
    /** Message shown inside the menu when there are no options. */
    emptyText?: string
  }>(),
  {
    placeholder: 'Select…',
    disabled: false,
    menuAriaLabel: 'Options',
    emptyText: 'No options',
  },
)

const emit = defineEmits<{ 'update:modelValue': [value: V] }>()

const open = ref(false)
const root = ref<HTMLElement>()
const menu = ref<HTMLElement>()
const toggleButton = ref<HTMLButtonElement>()

const selected = computed(
  () =>
    props.options.find((option) => option.value === props.modelValue) ?? null,
)
const label = computed(() => selected.value?.label ?? props.placeholder)

function optionButtons(): HTMLButtonElement[] {
  return menu.value
    ? Array.from(
        menu.value.querySelectorAll<HTMLButtonElement>(
          '.dropdown-item:not(:disabled)',
        ),
      )
    : []
}

function focusOption(which: 'first' | 'last'): void {
  const buttons = optionButtons()
  const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
  target?.focus()
}

function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
  if (props.disabled) {
    return
  }
  open.value = true
  if (focus !== 'none') {
    void nextTick(() => focusOption(focus))
  }
}

function close(returnFocus = false): void {
  if (!open.value) {
    return
  }
  open.value = false
  if (returnFocus) {
    toggleButton.value?.focus()
  }
}

function toggle(): void {
  if (props.disabled) {
    return
  }
  if (open.value) {
    close()
  } else {
    openMenu()
  }
}

function select(option: HilosDropdownOption<V>): void {
  if (option.disabled) {
    return
  }
  emit('update:modelValue', option.value)
  close(true)
}

function moveFocus(delta: 1 | -1): void {
  const buttons = optionButtons()
  if (buttons.length === 0) {
    return
  }
  const index = buttons.indexOf(document.activeElement as HTMLButtonElement)
  const next =
    index === -1 ? 0 : (index + delta + buttons.length) % buttons.length
  buttons[next]?.focus()
}

function onMenuKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      moveFocus(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveFocus(-1)
      break
    case 'Home':
      event.preventDefault()
      focusOption('first')
      break
    case 'End':
      event.preventDefault()
      focusOption('last')
      break
  }
}

function onDocumentClick(event: MouseEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) {
    close()
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div ref="root" class="dropdown" data-id="hilos-dropdown">
    <button
      ref="toggleButton"
      type="button"
      class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center justify-content-between w-100"
      :disabled="disabled"
      :aria-expanded="open"
      aria-haspopup="listbox"
      data-id="hilos-dropdown-toggle"
      @click="toggle"
      @keydown.down.prevent="openMenu('first')"
      @keydown.up.prevent="openMenu('last')"
      @keydown.esc.prevent="close(true)"
    >
      <slot name="toggle" :label="label" :selected="selected" :open="open">
        <span class="text-truncate">{{ label }}</span>
      </slot>
    </button>
    <ul
      v-show="open"
      ref="menu"
      class="dropdown-menu show w-100"
      role="listbox"
      :aria-label="menuAriaLabel"
      data-id="hilos-dropdown-menu"
      @keydown="onMenuKeydown"
      @keydown.esc.prevent="close(true)"
    >
      <li v-for="option in options" :key="String(option.value)">
        <slot
          name="option"
          :option="option"
          :selected="option.value === modelValue"
          :select="() => select(option)"
        >
          <button
            type="button"
            class="dropdown-item d-flex align-items-center justify-content-between gap-2"
            :class="{ active: option.value === modelValue }"
            :disabled="option.disabled"
            role="option"
            :aria-selected="option.value === modelValue"
            :data-id="`hilos-dropdown-option-${option.value}`"
            @click="select(option)"
          >
            <span class="text-truncate">{{ option.label }}</span>
            <i
              v-if="option.value === modelValue"
              class="bi bi-check2 flex-shrink-0"
              aria-hidden="true"
            ></i>
          </button>
        </slot>
      </li>
      <li v-if="options.length === 0">
        <span class="dropdown-item disabled text-body-secondary">{{
          emptyText
        }}</span>
      </li>
    </ul>
  </div>
</template>
