<script setup lang="ts">
// One browser session drawn as a row: the device and the zone its browser
// reported, when it was created, last seen and expires, and the toggle that
// opens its live tabs read-only. The profile's Sessions and an administrator's
// Sessions tab on a person's card draw the same row; each brings its own marks
// beside the title and its own control for the session through the slots.
//
// The row holds no state of its own: whether its tabs are open is the drawing
// surface's, kept by session id so a server update or a new reader zone does
// not close it, and the toggle only asks that surface to flip it.
import {
  formatProfileDateTime,
  hilosTimeZone,
  type HilosProfileSession,
} from '@hilos/core/base'

import { useSignal } from '../useSignal.js'

defineProps<{
  /** The durable session and its current live tabs. */
  session: HilosProfileSession
  /** Whether the session's tab list is open. */
  expanded: boolean
  /**
   * The first part of every data-id the row carries — `profile-session` in the
   * profile, so the row is `profile-session-row` and its toggle
   * `profile-session-tabs`.
   */
  idPrefix: string
}>()

const emit = defineEmits<{ toggle: [] }>()

defineSlots<{
  /** Marks beside the session title, such as which browser is this one. */
  badge?(): unknown
  /** The surface's own control for the session, after the tabs toggle. */
  actions?(): unknown
}>()

const timeZone = useSignal(hilosTimeZone)

/**
 * Format a session moment again when the reader's zone changes.
 *
 * @param moment UTC epoch milliseconds, or null for a moment that never comes.
 */
function dateTime(moment: number | null): string {
  void timeZone.value
  return formatProfileDateTime(moment)
}

/**
 * Display form of a tab key.
 *
 * Eight characters or fewer stay whole. A longer key keeps four characters
 * at each end. The list identity stays the full accept key.
 *
 * @param acceptKey The tab's full connection key.
 */
function tabKeyLabel(acceptKey: string): string {
  if (acceptKey.length <= 8) {
    return acceptKey
  }
  return `${acceptKey.slice(0, 4)}…${acceptKey.slice(-4)}`
}
</script>

<template>
  <article class="border-bottom" :data-id="`${idPrefix}-row`">
    <div class="d-flex flex-wrap align-items-center gap-2 py-3">
      <i
        v-if="session.impersonated"
        class="bi bi-people-fill fs-5 text-warning-emphasis"
        aria-hidden="true"
      ></i>
      <i
        v-else
        class="bi bi-window fs-5 text-body-secondary"
        aria-hidden="true"
      ></i>
      <div class="flex-grow-1 text-break">
        <h3 class="h6 mb-1">
          Session #{{ session.id }}
          <slot name="badge" />
        </h3>
        <div :data-id="`${idPrefix}-device`">
          {{ session.deviceName ?? 'Unknown device' }}
          <span
            v-if="session.browserTimeZone !== null"
            class="text-body-secondary"
            >· {{ session.browserTimeZone }}</span
          >
        </div>
        <div class="small text-body-secondary">
          created {{ dateTime(session.createdAt) }} · last seen
          {{ session.tabs.length > 0 ? 'now' : dateTime(session.lastSeenAt) }}
          · expires {{ dateTime(session.expiresAt) }}
        </div>
      </div>
      <button
        type="button"
        class="btn btn-link btn-sm p-0 text-decoration-none"
        :aria-expanded="expanded"
        :data-id="`${idPrefix}-tabs`"
        @click="emit('toggle')"
      >
        <i
          class="bi"
          :class="expanded ? 'bi-caret-down-fill' : 'bi-caret-right-fill'"
          aria-hidden="true"
        ></i>
        tabs: {{ session.tabs.length }}
      </button>
      <slot name="actions" />
    </div>
    <div v-if="expanded" class="bg-body-tertiary rounded-2 mb-2 px-2 py-1">
      <p
        v-if="session.tabs.length === 0"
        class="text-body-secondary small mb-0"
      >
        No open tabs: the sign-in stays valid, but the browser is closed right
        now.
      </p>
      <ul v-else class="list-unstyled mb-0">
        <li
          v-for="tab in session.tabs"
          :key="tab.acceptKey"
          class="d-flex flex-wrap align-items-center gap-2 py-1"
          :data-id="`${idPrefix}-tab`"
        >
          <i class="bi bi-plug text-body-secondary" aria-hidden="true"></i>
          <code>{{ tabKeyLabel(tab.acceptKey) }}</code>
          <span class="small text-body-secondary">
            opened {{ dateTime(tab.connectedAt) }}
          </span>
          <!-- A newline between the tags is dropped, so the boundary is
               its own text node, and only while this tab is shown. -->
          <template v-if="tab.current"
            >{{ ' '
            }}<span
              class="small text-body-secondary"
              :data-id="`${idPrefix}-tab-this`"
              >this tab</span
            ></template
          >
        </li>
      </ul>
    </div>
  </article>
</template>
