<!-- The chat main page (PAGE_MAIN). Shows the session's current user, the live
event stream (messages plus registration/rename/lifecycle notices), the
participant roster, and the active bots — each fed by a main page list, so a new
message, registration, or presence flip appears without a refresh. The message
composer is pinned to the bottom of the page; submitting fires the `message`
action and a re-send lockout timer counts down before the next submit is
allowed. Rendered by HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useConnectionState, useSignal } from '@hilos/vue'

import { connection } from '../connection'
import { currentUserName } from '../session'
import { mainParticipants, mainBots, mainEvents, selfConnection } from '../mainPage'
import {
  MESSAGE_RATE_LIMIT_SECONDS,
  messageError,
  sendChatMessage,
} from '../mainActions'

const selfName = useSignal(currentUserName)
const participants = useSignal(mainParticipants)
const bots = useSignal(mainBots)
const events = useSignal(mainEvents)
const selfConn = useSignal(selfConnection)
const error = useSignal(messageError)

const connectionState = useConnectionState(connection)
const isConnected = computed(() => connectionState.value === 'connected')

// The outbound moderation state of this connection's last submit. While
// `checking` the input is frozen and mirrors the in-flight text; a resolved
// (rejected/unavailable) state restores that text to the draft for editing.
const moderation = computed(() => selfConn.value?.moderation ?? null)
const isModerating = computed(() => moderation.value?.phase === 'checking')

const draft = ref('')
const cooldownSeconds = ref(0)
let cooldownTimer: ReturnType<typeof setInterval> | null = null

// During moderation the input shows the in-flight text (read-only); otherwise
// it shows the editable draft.
const displayMessage = computed(() =>
  isModerating.value ? (moderation.value?.text ?? '') : draft.value,
)

interface ModerationBanner {
  text: string
  className: string
  spinner: boolean
}

const moderationBanner = computed<ModerationBanner | null>(() => {
  const state = moderation.value
  if (state === null) {
    return null
  }
  if (state.phase === 'checking') {
    return { text: 'Moderating message…', className: 'text-primary', spinner: true }
  }
  if (state.phase === 'rejected') {
    return {
      text: state.reason ? `Message rejected: ${state.reason}` : 'Message rejected',
      className: 'text-danger',
      spinner: false,
    }
  }

  return {
    text: state.reason
      ? `Moderation unavailable: ${state.reason}`
      : 'Moderation unavailable',
    className: 'text-warning-emphasis',
    spinner: false,
  }
})

// Send is gated four ways: a live connection, non-blank text, no active re-send
// lockout (the backend rate-limits submits), and no in-flight moderation.
const canSend = computed(
  () =>
    isConnected.value &&
    draft.value.trim() !== '' &&
    cooldownSeconds.value === 0 &&
    !isModerating.value,
)

const startCooldown = (seconds = MESSAGE_RATE_LIMIT_SECONDS): void => {
  cooldownSeconds.value = seconds
  if (cooldownTimer !== null) {
    clearInterval(cooldownTimer)
  }
  cooldownTimer = setInterval(() => {
    cooldownSeconds.value = Math.max(0, cooldownSeconds.value - 1)
    if (cooldownSeconds.value === 0 && cooldownTimer !== null) {
      clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

const handleInput = (event: Event): void => {
  if (isModerating.value) {
    return
  }
  draft.value = (event.target as HTMLInputElement).value
}

const submitMessage = (): void => {
  if (!canSend.value) {
    return
  }
  if (!sendChatMessage(draft.value.trim())) {
    return
  }
  draft.value = ''
  startCooldown()
}

// Reconcile the countdown with the backend-reported remaining seconds: on
// reload or a cross-tab submit the server is the source of truth, so adopt a
// longer remaining than the local one (e.g. resume a lockout after a refresh).
watch(
  () => selfConn.value?.messageRateLimitSecondsRemaining ?? 0,
  (seconds) => {
    if (seconds > cooldownSeconds.value) {
      startCooldown(seconds)
    }
  },
  { immediate: true },
)

// Restore a resolved-but-unsent submission into the draft so the user can edit
// and resend it — including a rejection that was already pending on page load.
watch(
  moderation,
  (state) => {
    if (state !== null && state.phase !== 'checking' && draft.value === '') {
      draft.value = state.text
    }
  },
  { immediate: true },
)

onUnmounted(() => {
  if (cooldownTimer !== null) {
    clearInterval(cooldownTimer)
  }
})
</script>

<template>
  <div class="d-flex flex-column h-100">
    <p>Signed in as <span data-id="self-user">{{ selfName }}</span></p>

    <div class="row g-3 flex-grow-1">
      <div class="col-lg-8">
        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="events-header"
          >
            <strong>Event stream</strong>
            <span class="badge text-bg-secondary" data-id="events-count">{{
              events.length
            }}</span>
          </div>
          <div class="list-group list-group-flush">
            <div
              v-if="events.length === 0"
              class="list-group-item text-muted"
              data-id="events-empty"
            >
              No events yet
            </div>
            <div
              v-for="event in events"
              :key="event.key"
              class="list-group-item"
              data-id="event"
            >
              <div class="d-flex align-items-baseline gap-2">
                <span
                  v-if="event.authorName"
                  class="fw-semibold"
                  data-id="event-author"
                >
                  <span v-if="event.authorIsBot" class="me-1" aria-hidden="true"
                    >🤖</span
                  >{{ event.authorName }}
                </span>
                <span
                  v-if="event.description"
                  class="text-muted"
                  data-id="event-notice"
                  >{{ event.description }}</span
                >
              </div>
              <div v-if="event.text" class="mt-1" data-id="event-text">
                {{ event.text }}
              </div>
              <ul
                v-if="event.attachments.length > 0"
                class="list-inline mt-1 mb-0 small"
              >
                <li
                  v-for="attachment in event.attachments"
                  :key="attachment.key"
                  class="list-inline-item"
                  data-id="event-attachment"
                >
                  📎 {{ attachment.filename }}
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="participants-header"
          >
            <strong>Participants</strong>
            <span class="badge text-bg-secondary" data-id="participants-count">{{
              participants.length
            }}</span>
          </div>
          <div class="list-group list-group-flush">
            <div
              v-if="participants.length === 0"
              class="list-group-item text-muted"
              data-id="participants-empty"
            >
              No participants yet
            </div>
            <div
              v-for="participant in participants"
              :key="participant.key"
              class="list-group-item d-flex align-items-center gap-2"
              data-id="participant"
            >
              <span
                class="rounded-circle flex-shrink-0"
                :class="
                  participant.presence === 'online'
                    ? 'bg-success'
                    : 'bg-secondary'
                "
                style="width: 10px; height: 10px"
              />
              <span data-id="participant-name">{{ participant.name }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="bots-header"
          >
            <strong>Bots</strong>
            <span class="badge text-bg-secondary" data-id="bots-count">{{
              bots.length
            }}</span>
          </div>
          <div class="list-group list-group-flush">
            <div
              v-if="bots.length === 0"
              class="list-group-item text-muted"
              data-id="bots-empty"
            >
              No bots yet
            </div>
            <div
              v-for="bot in bots"
              :key="bot.key"
              class="list-group-item"
              data-id="bot"
            >
              <div class="d-flex justify-content-between align-items-center gap-2">
                <span class="fw-semibold" data-id="bot-name">{{ bot.name }}</span>
                <span
                  v-if="bot.status"
                  class="badge text-bg-light"
                  data-id="bot-status"
                  >{{ bot.status }}</span
                >
              </div>
              <div v-if="bot.description" class="small text-muted">
                {{ bot.description }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <div
        v-if="moderationBanner"
        class="small mb-1 d-flex align-items-center gap-2"
        :class="moderationBanner.className"
        data-id="moderation-banner"
      >
        <span
          v-if="moderationBanner.spinner"
          class="spinner-border spinner-border-sm"
          aria-hidden="true"
        />
        <span data-id="moderation-text">{{ moderationBanner.text }}</span>
      </div>
      <!-- A moderation failure already shows in the banner above and also rides
      back as an action_error; suppress the bare error then to avoid a duplicate.
      Synchronous submit errors (rate-limited, empty) have no banner and show. -->
      <div
        v-if="error && !moderationBanner"
        class="small text-danger mb-1"
        data-id="message-error"
      >
        {{ error }}
      </div>
      <form
        class="d-flex gap-2"
        data-id="message-form"
        @submit.prevent="submitMessage"
      >
        <input
          :value="displayMessage"
          type="text"
          class="form-control"
          placeholder="Type your message..."
          maxlength="500"
          :disabled="!isConnected || isModerating"
          data-id="message-input"
          @input="handleInput"
        />
        <span
          v-if="cooldownSeconds > 0"
          class="align-self-center text-muted small flex-shrink-0"
          data-id="message-cooldown"
          >{{ cooldownSeconds }}s</span
        >
        <button
          type="submit"
          class="btn btn-primary flex-shrink-0"
          :disabled="!canSend"
          data-id="message-send"
        >
          Send
        </button>
      </form>
    </div>
  </div>
</template>
