<!-- HilosNotificationBell — the tier-1 notification-center surface (HIL-195): a
bell button carrying the unread badge and a disclosure dropdown of the recent
notifications, each with its own mark-read control plus a mark-all and a "see
all" placeholder. It renders the framework-owned notification store
(hilosNotifications, fed by the connection through bindNotificationsScope once
`notifications: true` is passed to bootHilos) and marks read by firing the
`notification_mark_read` / `notification_mark_all_read` actions — the store never
updates optimistically; it turns read when the server fans the READ signal back
to every one of the recipient's tabs (core-and-connection.md, the shared-state
signal round-trip). A connection with no signed-in user never joins the group, so
the bell simply shows an empty, zero-badge menu there. Open/close, outside-click,
Escape, and arrow-roving mirror HilosDropdown; the panel is a disclosure (button
+ aria-expanded), not a listbox, because each row hosts an action rather than a
single-select option. Unread is never signalled by color alone — an unread row is
bold and carries a visually-hidden "Unread" marker (styling-rules.md,
accessibility.md). Bootstrap classes only, no CSS of its own. -->
<script setup lang="ts">
import {
  hilosNotifications,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  type HilosConnection,
  type HilosNotification,
  type HilosNotificationStore,
} from '@hilos/core'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The connection the mark-read actions are sent over. */
    connection: HilosConnection
    /** The store the bell renders; defaults to the shared framework store. */
    store?: HilosNotificationStore
  }>(),
  { store: () => hilosNotifications },
)

const notifications = useSignal(props.store.notifications)
const unreadCount = useSignal(props.store.unreadCount)
// The badge caps its label so a runaway count never breaks the bell's layout.
const badgeLabel = computed(() =>
  unreadCount.value > 99 ? '99+' : String(unreadCount.value),
)

const open = ref(false)
const menuId = useId()
const root = ref<HTMLElement>()
const menu = ref<HTMLElement>()
const toggleButton = ref<HTMLButtonElement>()

// The per-row mark-read buttons the arrow keys rove over; the header/footer
// controls (mark-all, see-all) stay Tab-reachable but out of the roving ring.
function itemButtons(): HTMLButtonElement[] {
  return menu.value
    ? Array.from(
        menu.value.querySelectorAll<HTMLButtonElement>(
          'ul button:not(:disabled)',
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

// Mark-read is fire-and-forget: the store turns read only when the server fans
// the READ signal back (no optimistic path), so a failed send simply leaves the
// row unread rather than desyncing the badge.
function markRead(row: HilosNotification): void {
  props.connection.sendAction(NOTIFICATION_ACTION_MARK_READ, { id: row.id })
}

function markAllRead(): void {
  props.connection.sendAction(NOTIFICATION_ACTION_MARK_ALL_READ, {})
}

// A notification's timestamp, formatted for the reader's locale; the wire value
// is an ISO string, absent only on a malformed row.
function formatTime(row: HilosNotification): string {
  return row.createdAt ? new Date(row.createdAt).toLocaleString() : ''
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
  <div ref="root" class="dropdown" data-id="hilos-notification-bell">
    <button
      ref="toggleButton"
      type="button"
      class="btn btn-link nav-link position-relative d-inline-flex align-items-center p-0 fs-5"
      :aria-expanded="open"
      :aria-controls="menuId"
      aria-haspopup="true"
      aria-label="Notifications"
      data-id="hilos-notification-toggle"
      @click="toggle"
      @keydown.down.prevent="openMenu('first')"
      @keydown.up.prevent="openMenu('last')"
      @keydown.esc.prevent="close(true)"
    >
      <i class="bi bi-bell" aria-hidden="true"></i>
      <span
        v-if="unreadCount > 0"
        class="badge rounded-pill bg-danger position-absolute top-0 start-100 translate-middle"
        data-id="hilos-notification-badge"
        >{{ badgeLabel
        }}<span class="visually-hidden"> unread notifications</span></span
      >
    </button>
    <div
      v-show="open"
      :id="menuId"
      ref="menu"
      class="dropdown-menu dropdown-menu-end show p-0"
      data-id="hilos-notification-menu"
      @keydown="onMenuKeydown"
      @keydown.esc.prevent="close(true)"
    >
      <div
        class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom"
      >
        <span class="fw-semibold">Notifications</span>
        <button
          type="button"
          class="btn btn-link btn-sm p-0"
          :disabled="unreadCount === 0"
          data-id="hilos-notification-mark-all"
          @click="markAllRead"
        >
          Mark all read
        </button>
      </div>
      <ul class="list-unstyled mb-0" style="max-width: 22rem">
        <li v-if="notifications.length === 0">
          <p
            class="dropdown-item-text text-body-secondary small mb-0 px-3 py-3"
            data-id="hilos-notification-empty"
          >
            No notifications
          </p>
        </li>
        <li
          v-for="row in notifications"
          :key="row.id"
          class="border-bottom"
          :data-id="`hilos-notification-item-${row.id}`"
        >
          <div class="d-flex align-items-start gap-2 px-3 py-2">
            <div class="flex-grow-1">
              <div :class="{ 'fw-bold': row.readAt == null }">
                {{ row.title }}
                <span v-if="row.readAt == null" class="visually-hidden"
                  >(Unread)</span
                >
              </div>
              <div v-if="row.body" class="small text-body-secondary">
                {{ row.body }}
              </div>
              <div class="small text-body-tertiary">{{ formatTime(row) }}</div>
            </div>
            <button
              v-if="row.readAt == null"
              type="button"
              class="btn btn-link btn-sm p-0 flex-shrink-0"
              :aria-label="`Mark &quot;${row.title}&quot; read`"
              :data-id="`hilos-notification-mark-read-${row.id}`"
              @click="markRead(row)"
            >
              <i class="bi bi-check2" aria-hidden="true"></i>
            </button>
          </div>
        </li>
      </ul>
      <div class="border-top px-3 py-2 text-center">
        <button
          type="button"
          class="btn btn-link btn-sm p-0"
          disabled
          data-id="hilos-notification-see-all"
        >
          See all
        </button>
      </div>
    </div>
  </div>
</template>
