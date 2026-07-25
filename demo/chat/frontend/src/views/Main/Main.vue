<!-- The chat main page (PAGE_MAIN). Shows the session's current user, the live
event stream (messages plus registration/rename/lifecycle notices), the
participant roster, and the active bots — each fed by a main page list, so a new
message, registration, or presence flip appears without a refresh. The message
composer is pinned to the bottom: it submits the `message` action, attaches
files over the WebSocket `frame_binary` channel (paperclip, drag & drop, or
paste) via the useComposerUpload engine, shows each upload's progress and the
pending attachment chips, and runs a re-send lockout timer before the next
submit. Rendered by HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { computed, inject, nextTick, onUnmounted, ref, watch } from 'vue'
import { useConnectionState, useSignal } from '@hilos/vue'

import { authGateKey } from '../../auth/authGateKey'
import { connection } from '../../bootstrap/connection'
import { currentUserId, currentUserName } from '../../bootstrap/session'
import {
  attachmentDrafts,
  mainParticipants,
  mainBots,
  mainEvents,
  selfConnection,
} from './mainPage'
import {
  MESSAGE_RATE_LIMIT_SECONDS,
  deleteAttachmentDraft,
  messageError,
  sendChatMessage,
} from './mainActions'
import { useComposerUpload } from './useComposerUpload'

defineOptions({ name: 'MainPage' })

const selfName = useSignal(currentUserName)
const selfId = useSignal(currentUserId)
const participants = useSignal(mainParticipants)
const bots = useSignal(mainBots)
const events = useSignal(mainEvents)
const selfConn = useSignal(selfConnection)
const drafts = useSignal(attachmentDrafts)
const error = useSignal(messageError)

const connectionState = useConnectionState(connection)
const isConnected = computed(() => connectionState.value === 'connected')

// Anonymous read, authenticated write (HIL-360): a guest reads the chat but
// cannot send, so the composer is disabled until the session names a user (the
// handshake response turns the current-user id non-null). The banner's CTA opens
// the in-place sign-in surface through the auth gate — no 401 round-trip.
const authGate = inject(authGateKey)
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
      text: state.reason
        ? `Message rejected: ${state.reason}`
        : 'Message rejected',
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

// The composer's file-upload engine — drag/drop, the picker, the sequential
// queue, and the binary-frame streaming — lives in its own composable so this
// view keeps only the message composer and the markup.
const {
  fileAccept,
  fileInputRef,
  isDragging,
  isUploading,
  uploadProgress,
  uploadProgressPercent,
  uploadError,
  openFilePicker,
  onFileInputChange,
  onDragEnter,
  onDragLeave,
  onDrop,
  onPaste,
} = useComposerUpload(isConnected, selfConn)

// The composer accepts a message with attachments and no text, so content is
// present when the draft is non-blank OR at least one attachment is pending.
const hasContent = computed(
  () => draft.value.trim() !== '' || drafts.value.length > 0,
)

const removeDraft = (draftId: string): void => {
  deleteAttachmentDraft(draftId)
}

// Send is gated: an authenticated + live connection, some content (text or an
// attachment), no active re-send lockout, no in-flight moderation, and no
// in-flight upload.
const canSend = computed(
  () =>
    isAuthenticated.value &&
    isConnected.value &&
    hasContent.value &&
    cooldownSeconds.value === 0 &&
    !isModerating.value &&
    !isUploading.value,
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
    <h1 class="visually-hidden">Conversations</h1>
    <p class="flex-shrink-0">
      Signed in as <span data-id="self-user">{{ selfName }}</span>
      <span data-id="self-user-id" hidden>{{ selfId }}</span>
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
              <div
                v-if="event.attachments.length > 0"
                class="d-flex flex-wrap gap-2 mt-2"
                data-id="event-attachments"
              >
                <template
                  v-for="attachment in event.attachments"
                  :key="attachment.key"
                >
                  <!-- Images render inline (the backend serves them with
                  Content-Disposition: inline); a click opens the original. -->
                  <a
                    v-if="attachment.mimeType.startsWith('image/')"
                    :href="attachment.url"
                    target="_blank"
                    rel="noopener"
                    data-id="event-attachment"
                  >
                    <img
                      :src="attachment.url"
                      :alt="attachment.filename"
                      class="rounded border"
                      style="
                        max-height: 12rem;
                        max-width: 100%;
                        object-fit: contain;
                      "
                      loading="lazy"
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
              <span
                class="rounded-circle flex-shrink-0"
                :class="
                  participant.presence === 'online'
                    ? 'bg-success'
                    : 'bg-secondary'
                "
                style="width: 10px; height: 10px"
                aria-hidden="true"
              />
              <span data-id="participant-name">{{ participant.name }}</span>
              <span class="visually-hidden">{{ participant.presence }}</span>
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
              <div
                class="d-flex justify-content-between align-items-center gap-2"
              >
                <span class="fw-semibold" data-id="bot-name">{{
                  bot.name
                }}</span>
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

    <div
      class="mt-3 flex-shrink-0 position-relative"
      data-id="composer"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <div
        v-if="isDragging"
        class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-primary-subtle border border-2 border-primary rounded"
        style="z-index: 5"
        data-id="composer-dropzone"
      >
        <span class="text-primary fw-semibold">
          <i class="bi bi-paperclip me-1" aria-hidden="true" />Drop files to
          upload
        </span>
      </div>

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

      <div
        v-if="uploadError"
        class="small text-danger mb-1"
        data-id="upload-error"
      >
        {{ uploadError }}
      </div>

      <div v-if="uploadProgress" class="mb-2" data-id="upload-progress">
        <div class="small text-muted d-flex justify-content-between gap-2 mb-1">
          <span class="text-truncate"
            >Uploading {{ uploadProgress.filename }}…</span
          >
          <span data-id="upload-progress-percent"
            >{{ uploadProgressPercent }}%</span
          >
        </div>
        <div class="progress" style="height: 4px">
          <div
            class="progress-bar"
            :style="{ width: uploadProgressPercent + '%' }"
          />
        </div>
      </div>

      <div
        v-if="drafts.length > 0"
        class="d-flex flex-wrap gap-2 mb-2"
        data-id="attachment-drafts"
      >
        <span
          v-for="d in drafts"
          :key="d.draftId"
          class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-1"
          data-id="attachment-draft"
        >
          <i class="bi bi-paperclip" aria-hidden="true" />
          <span
            class="text-truncate"
            style="max-width: 12rem"
            :title="d.filename"
            data-id="attachment-draft-name"
            >{{ d.filename }}</span
          >
          <button
            type="button"
            class="btn-close btn-close-white"
            style="font-size: 0.5rem"
            aria-label="Remove attachment"
            :disabled="isModerating"
            data-id="attachment-draft-remove"
            @click="removeDraft(d.draftId)"
          />
        </span>
      </div>

      <form
        class="d-flex gap-2"
        data-id="message-form"
        @submit.prevent="submitMessage"
      >
        <input
          ref="fileInputRef"
          type="file"
          multiple
          class="d-none"
          :accept="fileAccept"
          data-id="file-input"
          @change="onFileInputChange"
        />
        <button
          type="button"
          class="btn btn-outline-secondary flex-shrink-0"
          :disabled="!isAuthenticated || !isConnected || isModerating"
          aria-label="Attach files"
          data-id="attach-button"
          @click="openFilePicker"
        >
          <i class="bi bi-paperclip" aria-hidden="true" />
        </button>
        <input
          :value="displayMessage"
          type="text"
          class="form-control"
          :placeholder="
            isAuthenticated ? 'Type your message...' : 'Sign in to send a message'
          "
          maxlength="500"
          :disabled="!isAuthenticated || !isConnected || isModerating"
          data-id="message-input"
          @input="handleInput"
          @paste="onPaste"
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
    </div>
  </div>
</template>
