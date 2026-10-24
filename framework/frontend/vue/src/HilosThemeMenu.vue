<!-- HilosThemeMenu — the shell's theme icon (HIL-1433). It reads hilosThemeChoice
and draws nothing when that signal is null (switching is off). The icon is the
current position — sun, moon-stars or circle-half — named "Theme: Light" and so
on; the menu is Light · Dark · System, then Use site default. The current
position is checked, with a "default" badge when there is no personal pick.
Open/close, outside-click, Escape and arrow-roving mirror HilosNotificationBell.
The positions are menuitemradio and the reset is menuitem,
not a listbox: HilosDropdown is a full-width select with a caret, and this
control is a bare icon. A guest's choice is applied and remembered immediately;
account actions are disabled while the shell connection is unavailable and wait
for the server's handshake when sent. Both close and return focus. The
shell mounts it; a project passes nothing. Bootstrap classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  formatHilosThemeLabel,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosImpersonation,
  hilosSignedIn,
  hilosThemeChoice,
  sendHilosThemePick,
  setHilosThemePick,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePick,
} from '@hilos/core/base'
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
import { useConnectionInteraction } from './hilosConnectionInteraction.js'
import { useTrackedAction } from './useTrackedAction.js'

const choice = useSignal(hilosThemeChoice)
const signedIn = useSignal(hilosSignedIn)
const impersonation = useSignal(hilosImpersonation)
const interaction = useConnectionInteraction()
const unavailable = useSignal(interaction.unavailable)
const action = useTrackedAction()
const busy = computed(() => action.busy.value)
const loading = computed(() => action.loading.value)
const accountConnectionUnavailable = computed(
  () => signedIn.value && unavailable.value,
)
const toggleDescribedBy = computed(() => {
  const descriptions = []
  if (impersonation.value !== null) {
    descriptions.push(HILOS_IMPERSONATION_STRIP_TEXT_ID)
  }
  if (accountConnectionUnavailable.value) {
    descriptions.push(HILOS_CONNECTION_STATUS_TEXT_ID)
  }

  return descriptions.length > 0 ? descriptions.join(' ') : undefined
})
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
          '[role="menuitemradio"], [role="menuitem"]',
        ),
      ).filter((button) => !button.disabled)
    : []
}

function focusItem(which: 'first' | 'last'): void {
  const buttons = itemButtons()
  const target = which === 'first' ? buttons[0] : buttons[buttons.length - 1]
  target?.focus()
}

function openMenu(focus: 'first' | 'last' | 'none' = 'none'): void {
  if (
    action.busy.value ||
    hilosThemeChoice.get() === null ||
    hilosImpersonation.get() !== null ||
    (hilosSignedIn.get() && interaction.unavailable.get())
  ) {
    return
  }
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

function choose(pick: ThemePick): void {
  if (hilosSignedIn.get() && interaction.unavailable.get()) {
    return
  }
  if (
    action.busy.value ||
    hilosThemeChoice.get() === null ||
    hilosImpersonation.get() !== null
  ) {
    close(true)

    return
  }
  close(true)
  if (hilosSignedIn.get()) {
    void action.run(sendHilosThemePick(pick))
  } else {
    setHilosThemePick(pick)
  }
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
watch([signedIn, impersonation], () => close(true))

// Native disabled removes focus during the action. Return it only after Vue
// has re-enabled the button; this watcher stops with the component's scope.
watch(
  busy,
  (next, previous) => {
    if (
      next ||
      !previous ||
      !hilosSignedIn.get() ||
      hilosThemeChoice.get() === null ||
      hilosImpersonation.get() !== null
    ) {
      return
    }
    const button = toggleButton.value
    if (button?.isConnected && !button.disabled) {
      button.focus()
    }
  },
  { flush: 'post' },
)

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div v-if="view !== null" ref="root" class="dropdown">
    <button
      ref="toggleButton"
      type="button"
      class="btn btn-link nav-link d-inline-flex align-items-center p-0 fs-5 position-relative"
      :disabled="busy || impersonation !== null || accountConnectionUnavailable"
      :aria-describedby="toggleDescribedBy"
      :aria-busy="busy || undefined"
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
      <i
        class="bi"
        :class="[view.icon, { invisible: loading }]"
        aria-hidden="true"
      ></i>
      <span
        v-if="loading"
        class="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
        role="status"
        data-id="nav-theme-loading"
        ><span class="visually-hidden">Loading…</span></span
      >
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
        :disabled="busy || accountConnectionUnavailable"
        :aria-describedby="
          accountConnectionUnavailable
            ? HILOS_CONNECTION_STATUS_TEXT_ID
            : undefined
        "
        :data-id="`nav-theme-option-${row.value}`"
        @click="choose(row.value)"
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
      <hr class="dropdown-divider" role="separator" />
      <button
        type="button"
        class="dropdown-item"
        role="menuitem"
        :disabled="busy || accountConnectionUnavailable"
        :aria-describedby="
          accountConnectionUnavailable
            ? HILOS_CONNECTION_STATUS_TEXT_ID
            : undefined
        "
        data-id="nav-theme-default"
        @click="choose(null)"
      >
        {{ THEME_COPY.siteDefault }}
      </button>
    </div>
  </div>
</template>
