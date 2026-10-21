<!-- The chat main page (PAGE_MAIN). Shows the session's identity line, the live
event stream (messages plus registration/rename/lifecycle notices), the
participant roster, and the active bots — each fed by a main page list, so a new
message, registration, or presence flip appears without a refresh. A person or a
bot wears a circle (HIL-1300): a message opens a series of one author's messages
with the circle and the name, the messages that continue it carry only their text
under the head's, and their name as hidden text; a notice wears none. A roster
row starts with the person's circle — the merge sign for a merged account, which
has no presence dot — and a bot row with the circle of its initials. A new or
removed photo and a merge redraw the circles without a refresh. The identity
line has two branches (HIL-625): with an account it names the account, without
one it says the visit is anonymous — Chat hands a guest no name, so the
`self-user` marker is absent rather than carrying an empty one. The message
composer is pinned to the bottom: it submits the `message` action naming the
attached files, and runs a re-send lockout timer before the next submit. Its
files are the framework upload field (createHilosUploadField over the chat's
target): HilosUploadDrop wraps the whole composer and takes a drop or a paste,
HilosUploadPicker is the paperclip, and HilosUploadFiles draws each file's chip
and the line of the last refusal. A feed image shows as its thumbnail and opens the original. Rendered by HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { computed, inject, nextTick, onUnmounted, ref, watch } from 'vue'
import { createHilosUploadField } from '@hilos/core/base'
import {
  HilosAvatar,
  HilosFormError,
  HilosUploadDrop,
  HilosUploadFiles,
  HilosUploadPicker,
  hilosAuthGateKey,
  useConnectionState,
  useSignal,
} from '@hilos/vue/base'

import { connection } from '../../bootstrap/connection'
import { currentUserId, currentUserName } from '../../bootstrap/session'
import {
  EVENT_MESSAGE_SENT,
  mainParticipants,
  mainBots,
  mainEvents,
  selfConnection,
} from './mainPage'
import {
  MESSAGE_RATE_LIMIT_SECONDS,
  messageError,
  sendChatMessage,
} from './mainActions'

defineOptions({ name: 'MainPage' })

/** The chat's upload target (PHP `ChatAttachmentUploadTarget::NAME`). */
const CHAT_ATTACHMENT_TARGET = 'chat_attachment'

/** The Bootstrap Icons class a merged account's circle wears in place of a face. */
const MERGED_ICON = 'bi-sign-merge-left'

// The picker offers images, PDFs and text; drag/drop and paste bypass the
// filter, and the server reads the type from the content and refuses the rest,
// so this is advisory UX only.
const FILE_ACCEPT = 'image/*,.pdf,.txt,text/plain,application/pdf'

const selfName = useSignal(currentUserName)
const selfId = useSignal(currentUserId)
const participants = useSignal(mainParticipants)
const bots = useSignal(mainBots)
const events = useSignal(mainEvents)
const selfConn = useSignal(selfConnection)
const error = useSignal(messageError)

const connectionState = useConnectionState(connection)
const isConnected = computed(() => connectionState.value === 'connected')

// Anonymous read, authenticated write (HIL-360): a guest reads the chat but
// cannot send, so the composer is disabled until the session names a user (the
// handshake response turns the current-user id non-null). The banner's CTA opens
// the in-place sign-in surface through the auth gate — no 401 round-trip. The
// identity line branches on the same predicate (HIL-625): what decides both is
// whether the session names a user, never whether a name string came out empty —
// the id and the name are read off one session-scope ref, so an absent ref gives
// a null id and an empty name together.
const authGate = inject(hilosAuthGateKey)
const isAuthenticated = computed(() => selfId.value !== null)

function promptSignIn(): void {
  authGate?.requireAuth()
}

// The event stream owns its own scroll (overflow-auto in the template), so a new
// event would otherwise append below the fold. Keep it pinned to the newest
// event by scrolling to the bottom after the DOM updates.
const eventsScroll = ref<HTMLElement | null>(null)
watch(
  () => events.value.length,
  () => {
    void nextTick(() => {
      const el = eventsScroll.value
      if (el !== null) {
        el.scrollTop = el.scrollHeight
      }
    })
  },
)

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
    return {
      text: 'Moderating message…',
      className: 'text-primary',
      spinner: true,
    }
  }
  if (state.phase === 'rejected') {
    return {
      text: state.reason || 'Message rejected.',
      className: 'text-danger',
      spinner: false,
    }
  }

  return {
    text:
      state.reason ||
      'Moderation is unavailable right now. Your message is still here.',
    className: 'text-warning-emphasis',
    spinner: false,
  }
})

const composerError = computed(() =>
  moderationBanner.value ? null : error.value,
)

// The composer's files — drop, paste, the picker, the chips — are the framework
// upload field; this view keeps only the message composer and the markup. The
// field is one switch for all of it, off whenever the message input is: for a
// guest, without a connection and while the last submit is moderated.
const upload = createHilosUploadField({ target: CHAT_ATTACHMENT_TARGET })
const uploadReady = useSignal(upload.ready)
const uploadBusy = useSignal(upload.busy)
watch(
  () => !isAuthenticated.value || !isConnected.value || isModerating.value,
  (disabled) => {
    upload.setDisabled(disabled)
  },
  { immediate: true },
)

// The composer accepts a message with attachments and no text, so content is
// present when the draft is non-blank OR at least one attachment is ready. A
// refused file is not ready, so it neither counts nor holds Send back.
const hasContent = computed(
  () => draft.value.trim() !== '' || uploadReady.value.length > 0,
)

// Send is gated: an authenticated + live connection, some content (text or an
// attachment), no active re-send lockout, no in-flight moderation, and no
// file waiting or uploading.
const canSend = computed(
  () =>
    isAuthenticated.value &&
    isConnected.value &&
    hasContent.value &&
    cooldownSeconds.value === 0 &&
    !isModerating.value &&
    !uploadBusy.value,
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
  if (!sendChatMessage(draft.value.trim(), uploadReady.value)) {
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
  upload.dispose()
})
</script>

<template>
  <div class="d-flex flex-column h-100">
    <h1 class="visually-hidden">Conversations</h1>
    <p class="flex-shrink-0">
      <template v-if="isAuthenticated">
        Signed in as <span data-id="self-user">{{ selfName }}</span>
        <span data-id="self-user-id" hidden>{{ selfId }}</span>
      </template>
      <template v-else>
        <span data-id="self-anonymous">Browsing anonymously</span>
      </template>
    </p>

    <div class="row g-3 flex-grow-1 min-h-0">
      <div class="col-lg-8 d-flex flex-column min-h-0">
        <div class="card flex-grow-1 min-h-0 d-flex flex-column">
          <div
            class="card-header d-flex justify-content-between align-items-center flex-shrink-0"
            data-id="events-header"
          >
            <h2 class="h6 fw-bold mb-0">Event stream</h2>
            <span class="badge text-bg-secondary" data-id="events-count">{{
              events.length
            }}</span>
          </div>
          <div
            ref="eventsScroll"
            class="list-group list-group-flush flex-grow-1 overflow-auto min-h-0"
            data-id="events-scroll"
          >
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
              <!-- A message: the head of a series wears the circle and the name;
              a message that continues it keeps the same circle invisible, so its
              text lines up under the head's, and its name for screen readers. -->
              <div
                v-if="event.type === EVENT_MESSAGE_SENT"
                class="d-flex align-items-start gap-2"
              >
                <HilosAvatar
                  :name="event.authorName"
                  :photo="event.seriesHead ? event.authorPhoto : null"
                  size="sm"
                  :class="{ invisible: !event.seriesHead }"
                />
                <div class="flex-grow-1 min-w-0">
                  <span
                    :class="
                      event.seriesHead ? 'fw-semibold' : 'visually-hidden'
                    "
                    data-id="event-author"
                    >{{ event.authorName }}</span
                  >
                  <div
                    v-if="event.text"
                    :class="{ 'mt-1': event.seriesHead }"
                    data-id="event-text"
                  >
                    {{ event.text }}
                  </div>
                  <div
                    v-if="event.attachments.length > 0"
                    class="d-flex flex-wrap gap-2 mt-2"
                    data-id="event-attachments"
                  >
                    <template
                      v-for="attachment in event.attachments"
                      :key="attachment.key"
                    >
                      <!-- An image shows as its thumbnail (the chat_thumb copy the
                      files library draws); a click opens the original. -->
                      <a
                        v-if="attachment.mimeType.startsWith('image/')"
                        :href="attachment.url"
                        target="_blank"
                        rel="noopener"
                        data-id="event-attachment"
                      >
                        <img
                          :src="attachment.thumbUrl"
                          :alt="attachment.filename"
                          class="rounded border"
                          style="
                            max-height: 12rem;
                            max-width: 100%;
                            object-fit: contain;
                          "
                          loading="lazy"
                          data-id="event-attachment-image"
                        />
                      </a>
                      <!-- Everything else is a download link (Content-Disposition: attachment). -->
                      <a
                        v-else
                        :href="attachment.url"
                        :download="attachment.filename"
                        class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                        data-id="event-attachment"
                      >
                        <i class="bi bi-paperclip" aria-hidden="true" />
                        <span class="text-truncate" style="max-width: 16rem">{{
                          attachment.filename
                        }}</span>
                      </a>
                    </template>
                  </div>
                </div>
              </div>
              <!-- A notice — registered, renamed, the chat started, stopped or
              cleared — wears no circle and breaks a series. -->
              <div v-else class="d-flex align-items-baseline gap-2">
                <span
                  v-if="event.authorName"
                  class="fw-semibold"
                  data-id="event-author"
                  >{{ event.authorName }}</span
                >
                <span
                  v-if="event.description"
                  class="text-muted"
                  data-id="event-notice"
                  >{{ event.description }}</span
                >
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-3 min-h-0 overflow-auto">
        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="participants-header"
          >
            <h2 class="h6 fw-bold mb-0">Participants</h2>
            <span
              class="badge text-bg-secondary"
              data-id="participants-count"
              >{{ participants.length }}</span
            >
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
              <HilosAvatar
                :name="participant.name"
                :photo="participant.merged ? null : participant.photo"
                :icon="participant.merged ? MERGED_ICON : null"
                size="sm"
              />
              <span data-id="participant-name">{{ participant.name }}</span>
              <!-- A merged account has no presence of its own any more. -->
              <span
                v-if="!participant.merged"
                class="rounded-circle flex-shrink-0"
                :class="
                  participant.presence === 'online'
                    ? 'bg-success'
                    : 'bg-secondary'
                "
                style="width: 10px; height: 10px"
                aria-hidden="true"
              />
              <span class="visually-hidden">{{
                participant.merged ? 'merged' : participant.presence
              }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="bots-header"
          >
            <h2 class="h6 fw-bold mb-0">Bots</h2>
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
              <div class="d-flex align-items-center gap-2">
                <HilosAvatar :name="bot.name" size="sm" />
                <span class="fw-semibold me-auto" data-id="bot-name">{{
                  bot.name
                }}</span>
                <span
                  v-if="bot.status"
                  class="badge bg-body-tertiary text-body-emphasis"
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

    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      aria-atomic="true"
      data-id="message-error-live"
    >
      {{ composerError ?? '' }}
    </div>

    <div class="mt-3 flex-shrink-0" data-id="composer">
      <HilosUploadDrop :field="upload" data-id="composer-upload">
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
        back as an action_error; the banner suppresses both the row and its voice.
        Synchronous refusals such as the rate limit have no banner and show;
        empty messages never leave Send. -->
        <HilosFormError :message="composerError" data-id="message-error" />

        <HilosUploadFiles :field="upload" data-id="attachments" />

        <form
          class="d-flex gap-2"
          data-id="message-form"
          @submit.prevent="submitMessage"
        >
          <HilosUploadPicker
            :field="upload"
            data-id="attach-button"
            :accept="FILE_ACCEPT"
          />
          <input
            :value="displayMessage"
            type="text"
            class="form-control"
            :placeholder="
              isAuthenticated
                ? 'Type your message...'
                : 'Sign in to send a message'
            "
            maxlength="500"
            :disabled="!isAuthenticated || !isConnected || isModerating"
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
            v-if="isAuthenticated"
            type="submit"
            class="btn btn-primary flex-shrink-0"
            :disabled="!canSend"
            data-id="message-send"
          >
            Send
          </button>
          <button
            v-else
            type="button"
            class="btn btn-primary flex-shrink-0"
            data-id="message-signin"
            @click="promptSignIn"
          >
            Sign in to send
          </button>
        </form>
      </HilosUploadDrop>
    </div>
  </div>
</template>
