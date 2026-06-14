<!-- The chat main page (PAGE_MAIN). Shows the session's current user, the live
event stream (messages plus registration/rename/lifecycle notices), the
participant roster, and the active bots — each fed by a main page list, so a new
message, registration, or presence flip appears without a refresh. The message
composer is pinned to the bottom: it submits the `message` action, attaches
files over the WebSocket `frame_binary` channel (paperclip, drag & drop, or
paste), shows each upload's progress and the pending attachment chips, and runs
a re-send lockout timer before the next submit. Rendered by HilosView when the
navigator's route is the main page. -->
<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useConnectionState, useSignal } from '@hilos/vue'

import { connection } from '../../bootstrap/connection'
import { currentUserName } from '../../bootstrap/session'
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
  initFileUpload,
  messageError,
  sendChatMessage,
  sendUploadChunk,
} from './mainActions'

defineOptions({ name: 'MainPage' })

const selfName = useSignal(currentUserName)
const participants = useSignal(mainParticipants)
const bots = useSignal(mainBots)
const events = useSignal(mainEvents)
const selfConn = useSignal(selfConnection)
const drafts = useSignal(attachmentDrafts)
const error = useSignal(messageError)

const connectionState = useConnectionState(connection)
const isConnected = computed(() => connectionState.value === 'connected')

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

// The picker accepts images, PDFs and text; drag/drop and paste bypass the
// filter and the backend is the authority on size and type, so this is advisory
// UX only. Files stream in 64 KiB binary frames once the backend says `ready`.
const FILE_ACCEPT = 'image/*,.pdf,.txt,text/plain,application/pdf'
const UPLOAD_CHUNK_SIZE = 65536

const fileInputRef = ref<HTMLInputElement | null>(null)
const dragDepth = ref(0)
const uploadQueue = ref<File[]>([])
const isUploading = ref(false)
const uploadClientError = ref<string | null>(null)

const isDragging = computed(() => dragDepth.value > 0 && isConnected.value)
const uploadProgress = computed(() => selfConn.value?.fileProgress ?? null)

const uploadProgressPercent = computed(() => {
  const progress = uploadProgress.value
  if (progress === null || progress.totalBytes <= 0) {
    return 0
  }

  return Math.min(
    100,
    Math.round((progress.uploadedBytes / progress.totalBytes) * 100),
  )
})

// A failed upload state pushed by the backend, else any local (not-connected)
// error from this composer.
const uploadError = computed(() => {
  const upload = selfConn.value?.fileUpload
  if (upload?.phase === 'failed') {
    return upload.errorMessage ?? 'Upload failed'
  }

  return uploadClientError.value
})

// The composer accepts a message with attachments and no text, so content is
// present when the draft is non-blank OR at least one attachment is pending.
const hasContent = computed(
  () => draft.value.trim() !== '' || drafts.value.length > 0,
)

// Bridge the backend's per-upload `ready` / `failed` state (pushed on
// selfConnection.fileUpload, correlated by clientUploadId) to the awaiting
// upload routine. The backend reserves a single upload slot, so one pending
// entry at a time is enough and files upload sequentially.
interface UploadOutcome {
  ok: boolean
  message: string | null
}

let pendingUpload: {
  clientUploadId: string
  resolve: (outcome: UploadOutcome) => void
} | null = null

watch(
  () => selfConn.value?.fileUpload ?? null,
  (upload) => {
    if (pendingUpload === null || upload === null) {
      return
    }
    if (upload.clientUploadId !== pendingUpload.clientUploadId) {
      return
    }
    if (upload.phase === 'ready') {
      const pending = pendingUpload
      pendingUpload = null
      pending.resolve({ ok: true, message: null })
    } else if (upload.phase === 'failed') {
      const pending = pendingUpload
      pendingUpload = null
      pending.resolve({ ok: false, message: upload.errorMessage })
    }
  },
)

const awaitUploadReady = (clientUploadId: string): Promise<UploadOutcome> =>
  new Promise((resolve) => {
    pendingUpload = { clientUploadId, resolve }
  })

const streamFile = async (file: File): Promise<boolean> => {
  let offset = 0
  while (offset < file.size) {
    const buffer = await file
      .slice(offset, offset + UPLOAD_CHUNK_SIZE)
      .arrayBuffer()
    if (!sendUploadChunk(buffer)) {
      return false
    }
    offset += buffer.byteLength
  }

  return true
}

// Announce one file, wait for `ready` (or a failure), then stream its bytes.
const uploadOne = async (file: File): Promise<void> => {
  const clientUploadId = crypto.randomUUID()
  const ready = awaitUploadReady(clientUploadId)
  const announced = initFileUpload({
    filename: file.name,
    mimeType: file.type || 'application/octet-stream',
    size: file.size,
    clientUploadId,
  })
  if (!announced) {
    pendingUpload = null
    uploadClientError.value = 'Not connected'

    return
  }
  const outcome = await ready
  if (!outcome.ok) {
    uploadClientError.value = outcome.message ?? 'Upload failed'

    return
  }
  if (!(await streamFile(file))) {
    uploadClientError.value = 'Upload interrupted'
  }
}

// Drain the queue one file at a time; a single pass holds the lock so files
// added mid-upload join the same drain.
const processQueue = async (): Promise<void> => {
  if (isUploading.value) {
    return
  }
  isUploading.value = true
  try {
    while (uploadQueue.value.length > 0) {
      const [next, ...rest] = uploadQueue.value
      uploadQueue.value = rest
      if (next !== undefined) {
        await uploadOne(next)
      }
    }
  } finally {
    isUploading.value = false
  }
}

const enqueueFiles = (files: FileList | null): void => {
  if (files === null) {
    return
  }
  uploadClientError.value = null
  const accepted = Array.from(files).filter((file) => file.size > 0)
  if (accepted.length === 0) {
    return
  }
  uploadQueue.value = [...uploadQueue.value, ...accepted]
  void processQueue()
}

const openFilePicker = (): void => {
  fileInputRef.value?.click()
}

const onFileInputChange = (event: Event): void => {
  const input = event.target as HTMLInputElement
  enqueueFiles(input.files)
  // Reset so re-picking the same file fires `change` again.
  input.value = ''
}

const onDragEnter = (): void => {
  dragDepth.value += 1
}

const onDragLeave = (): void => {
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

const onDrop = (event: DragEvent): void => {
  dragDepth.value = 0
  enqueueFiles(event.dataTransfer?.files ?? null)
}

const onPaste = (event: ClipboardEvent): void => {
  const files = event.clipboardData?.files
  if (files && files.length > 0) {
    event.preventDefault()
    enqueueFiles(files)
  }
}

const removeDraft = (draftId: string): void => {
  deleteAttachmentDraft(draftId)
}

// A dropped connection abandons the active upload and clears the queue so the
// composer never wedges on `uploading` waiting for a reply that cannot arrive.
watch(isConnected, (connected) => {
  if (connected) {
    return
  }
  if (pendingUpload !== null) {
    const pending = pendingUpload
    pendingUpload = null
    pending.resolve({ ok: false, message: 'Connection lost' })
  }
  uploadQueue.value = []
})

// Send is gated: a live connection, some content (text or an attachment), no
// active re-send lockout, no in-flight moderation, and no in-flight upload.
const canSend = computed(
  () =>
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
    <p class="flex-shrink-0">
      Signed in as <span data-id="self-user">{{ selfName }}</span>
    </p>

    <div class="row g-3 flex-grow-1 min-h-0">
      <div class="col-lg-8 d-flex flex-column min-h-0">
        <div class="card flex-grow-1 min-h-0 d-flex flex-column">
          <div
            class="card-header d-flex justify-content-between align-items-center flex-shrink-0"
            data-id="events-header"
          >
            <strong>Event stream</strong>
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
            <strong>Participants</strong>
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
          :accept="FILE_ACCEPT"
          data-id="file-input"
          @change="onFileInputChange"
        />
        <button
          type="button"
          class="btn btn-outline-secondary flex-shrink-0"
          :disabled="!isConnected || isModerating"
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
          placeholder="Type your message..."
          maxlength="500"
          :disabled="!isConnected || isModerating"
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
