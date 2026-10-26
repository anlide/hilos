<script setup lang="ts">
import {
  endableProfileSessionCount,
  formatProfileDateTime,
  hilosTimeZone,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'
import { computed, ref } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const props = defineProps<{
  /** Durable sessions and their current live tabs. */
  sessions: readonly HilosProfileSession[]
  /** Tracked commands that end one session or every other session. */
  actions: HilosProfileSessionActions
}>()

const timeZone = useSignal(hilosTimeZone)
const expanded = ref<ReadonlySet<number>>(new Set())
const endingSessionId = ref<number | null>(null)
const endOthersOpen = ref(false)
const endAction = useTrackedAction()
const endOthersAction = useTrackedAction()
const selectedSession = computed(() =>
  props.sessions.find((session) => session.id === endingSessionId.value),
)
const endError = computed(() =>
  selectedSession.value === undefined
    ? 'This session has already ended'
    : endAction.error.value,
)
const endBusy = computed(() => endAction.busy.value)
const endOthersBusy = computed(() => endOthersAction.busy.value)
const endableCount = computed(() => endableProfileSessionCount(props.sessions))
const endOthersError = computed(() =>
  endableCount.value === 0
    ? 'Your other sessions have already ended'
    : endOthersAction.error.value,
)

/** Format a session moment again when the reader's zone changes. */
function dateTime(moment: number | null): string {
  void timeZone.value
  return formatProfileDateTime(moment)
}

/**
 * Display form of a tab key.
 *
 * Eight characters or fewer stay whole. A longer key keeps four characters
 * at each end. The list identity stays the full accept key.
 */
function tabKeyLabel(acceptKey: string): string {
  if (acceptKey.length <= 8) {
    return acceptKey
  }
  return `${acceptKey.slice(0, 4)}…${acceptKey.slice(-4)}`
}

function toggleTabs(sessionId: number): void {
  const next = new Set(expanded.value)
  if (next.has(sessionId)) {
    next.delete(sessionId)
  } else {
    next.add(sessionId)
  }
  expanded.value = next
}

function openEnd(sessionId: number): void {
  endAction.clearError()
  endingSessionId.value = sessionId
}

async function confirmEnd(): Promise<void> {
  const session = selectedSession.value
  if (session === undefined) {
    return
  }
  if (await endAction.run(props.actions.endSession(session.id))) {
    endingSessionId.value = null
  }
}

function openEndOthers(): void {
  endOthersAction.clearError()
  endOthersOpen.value = true
}

async function confirmEndOthers(): Promise<void> {
  if (endableCount.value === 0) {
    return
  }
  if (await endOthersAction.run(props.actions.endOtherSessions())) {
    endOthersOpen.value = false
  }
}
</script>

<template>
  <section aria-label="Sessions" data-id="profile-sessions">
    <p class="small text-body-secondary">
      A session is a browser that signed in. Its open tabs are listed inside it.
      Ending a session takes effect at once.
    </p>

    <div v-if="sessions.length === 0" class="text-body-secondary">
      No sessions.
    </div>
    <div v-else>
      <article
        v-for="session in sessions"
        :key="session.id"
        class="border-bottom"
        data-id="profile-session-row"
      >
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
              <span
                v-if="session.current"
                class="badge text-bg-success ms-1"
                data-id="profile-session-this"
                >this one</span
              >
              <span
                v-if="session.impersonated"
                class="badge bg-warning-subtle text-warning-emphasis ms-1"
                data-id="profile-session-impersonated"
                >an administrator is working as you</span
              >
            </h3>
            <div data-id="profile-session-device">
              {{ session.deviceName ?? 'Unknown device' }}
              <span
                v-if="session.browserTimeZone !== null"
                class="text-body-secondary"
                >· {{ session.browserTimeZone }}</span
              >
            </div>
            <div class="small text-body-secondary">
              created {{ dateTime(session.createdAt) }} · last seen
              {{
                session.tabs.length > 0 ? 'now' : dateTime(session.lastSeenAt)
              }}
              · expires {{ dateTime(session.expiresAt) }}
            </div>
          </div>
          <button
            type="button"
            class="btn btn-link btn-sm p-0 text-decoration-none"
            :aria-expanded="expanded.has(session.id)"
            data-id="profile-session-tabs"
            @click="toggleTabs(session.id)"
          >
            <i
              class="bi"
              :class="
                expanded.has(session.id)
                  ? 'bi-caret-down-fill'
                  : 'bi-caret-right-fill'
              "
              aria-hidden="true"
            ></i>
            tabs: {{ session.tabs.length }}
          </button>
          <button
            v-if="!session.current && !session.impersonated"
            type="button"
            class="btn btn-outline-danger btn-sm"
            data-id="profile-session-revoke"
            @click="openEnd(session.id)"
          >
            Revoke
          </button>
        </div>
        <div
          v-if="expanded.has(session.id)"
          class="bg-body-tertiary rounded-2 mb-2 px-2 py-1"
        >
          <p
            v-if="session.tabs.length === 0"
            class="text-body-secondary small mb-0"
          >
            No open tabs: the sign-in stays valid, but the browser is closed
            right now.
          </p>
          <ul v-else class="list-unstyled mb-0">
            <li
              v-for="tab in session.tabs"
              :key="tab.acceptKey"
              class="d-flex flex-wrap align-items-center gap-2 py-1"
              data-id="profile-session-tab"
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
                  data-id="profile-session-tab-this"
                  >this tab</span
                ></template
              >
            </li>
          </ul>
        </div>
      </article>
    </div>

    <button
      type="button"
      class="btn btn-outline-danger btn-sm mt-3"
      :disabled="endableCount === 0"
      data-id="profile-sessions-end-others"
      @click="openEndOthers"
    >
      Sign out everywhere else
    </button>

    <HilosModal
      :model-value="endingSessionId !== null"
      :title="`End session #${endingSessionId ?? ''}`"
      :busy="endBusy"
      initial-focus="dialog"
      @update:model-value="endingSessionId = $event ? endingSessionId : null"
    >
      <p>
        Tabs of this session lose the account at once: where an account is
        needed, the sign-in form takes the place of the content.
      </p>
      <p>Your other sessions are not touched.</p>
      <HilosFormError
        :message="endError"
        data-id="profile-session-end-error"
        announce
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="endBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="endBusy"
          :disabled="selectedSession === undefined"
          data-id="profile-session-end-confirm"
          @click="confirmEnd"
        >
          End
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      :model-value="endOthersOpen"
      title="Sign out everywhere else"
      :busy="endOthersBusy"
      initial-focus="dialog"
      @update:model-value="endOthersOpen = $event"
    >
      <p>
        Other sessions that will end:
        <strong data-id="profile-sessions-end-others-count">{{
          endableCount
        }}</strong
        >.
      </p>
      <p>
        Their tabs lose the account at once: where an account is needed, the
        sign-in form takes the place of the content.
      </p>
      <p>This session stays signed in.</p>
      <HilosFormError
        :message="endOthersError"
        data-id="profile-sessions-end-others-error"
        announce
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="endOthersBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="endOthersBusy"
          :disabled="endableCount === 0"
          data-id="profile-sessions-end-others-confirm"
          @click="confirmEndOthers"
        >
          Sign out
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
