<!-- HilosThemeMenu — the shell's theme icon (HIL-1433). It reads hilosThemeChoice
and draws nothing when that signal is null (switching is off). The icon is the
current position — sun, moon-stars or circle-half — named "Theme: Light" and so
on; the menu is Light · Dark · System, the current row checked, and a "default"
badge only when the person never picked. Open/close, outside-click, Escape and
arrow-roving mirror HilosNotificationBell. The panel is a menu of menuitemradio,
not a listbox: HilosDropdown is a full-width select with a caret, and this
control is a bare icon. A click on a row closes the menu and does not change
the pick — that lands in HIL-1436 (guest) and HIL-1437 (signed-in). The shell
mounts it; a project passes nothing. Bootstrap classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  formatHilosThemeLabel,
  hilosThemeChoice,
  THEME_COPY,
  THEME_POSITIONS,
} from '@hilos/core'
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  useId,
  watch,
} from 'vue'

import { useSignal } from './useSignal.js'

const choice = useSignal(hilosThemeChoice)
const view = computed(() => {
  const current = choice.value
  if (current === null) {
    return null
  }
  const icon =
    THEME_POSITIONS.find((row) => row.value === current.position)?.icon ?? ''

  return {
    position: current.position,
    isDefault: current.isDefault,
    icon,
    label: formatHilosThemeLabel(current.position),
  }
})

const open = ref(false)
const menuId = useId()
const root = ref<HTMLElement>()
const menu = ref<HTMLElement>()
const toggleButton = ref<HTMLButtonElement>()

function itemButtons(): HTMLButtonElement[] {
  return menu.value
    ? Array.from(
        menu.value.querySelectorAll<HTMLButtonElement>(
          '[role="menuitemradio"]',
        ),
      )
    : []
}

function focusItem(which: 'first' | 'last'): void {
  const buttons = itemButtons()
  const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
  target?.focus()
}

function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
  open.value = true
  if (focus !== 'none') {
    void nextTick(() => focusItem(focus))
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
  if (open.value) {
    close()
  } else {
    openMenu()
  }
}

function moveFocus(delta: 1 | -1): void {
  const buttons = itemButtons()
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
      focusItem('first')
      break
    case 'End':
      event.preventDefault()
      focusItem('last')
      break
  }
}

function choose(): void {
  close(true)
  // SCAFFOLD: not wired yet — the pick itself lands in HIL-1436 (guest) and HIL-1437 (signed-in)
}

function onDocumentClick(event: MouseEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) {
    close()
  }
}

// The control leaves the header when switching turns off; an open menu leaves
// with it and must not still be open if the icon comes back.
watch(choice, (next) => {
  if (next === null) {
    open.value = false
  }
})

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div v-if="view !== null" ref="root" class="dropdown">
    <button
      ref="toggleButton"
      type="button"
      class="btn btn-link nav-link d-inline-flex align-items-center p-0 fs-5"
      :aria-expanded="open"
      :aria-controls="menuId"
      aria-haspopup="menu"
      :aria-label="view.label"
      :title="view.label"
      data-id="nav-theme"
      :data-position="view.position"
      @click="toggle"
      @keydown.down.prevent="openMenu('first')"
      @keydown.up.prevent="openMenu('last')"
      @keydown.esc.prevent="close(true)"
    >
      <i class="bi" :class="view.icon" aria-hidden="true"></i>
    </button>
    <div
      v-show="open"
      :id="menuId"
      ref="menu"
      class="dropdown-menu dropdown-menu-end show"
      role="menu"
      :aria-label="THEME_COPY.menu"
      data-id="nav-theme-menu"
      @keydown="onMenuKeydown"
      @keydown.esc.prevent="close(true)"
    >
      <button
        v-for="row in THEME_POSITIONS"
        :key="row.value"
        type="button"
        class="dropdown-item d-flex align-items-center gap-2"
        :class="{ active: row.value === view.position }"
        role="menuitemradio"
        :aria-checked="row.value === view.position"
        :data-id="`nav-theme-option-${row.value}`"
        @click="choose"
      >
        <i class="bi" :class="row.icon" aria-hidden="true"></i>
        <span class="flex-grow-1">{{ row.label }}</span>
        <span
          v-if="row.value === view.position && view.isDefault"
          class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
          >{{ THEME_COPY.default }}</span
        >
        <i
          v-if="row.value === view.position"
          class="bi bi-check2"
          aria-hidden="true"
        ></i>
      </button>
    </div>
  </div>
</template>
