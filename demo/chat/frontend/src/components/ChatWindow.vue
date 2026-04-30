<template>
  <div
    class="card h-100 d-flex flex-column"
    @dragenter.prevent="onDragEnter"
    @dragleave.prevent="onDragLeave"
    @drop.prevent="onDropFiles"
    :class="{ 'border-primary': dragDepth > 0 }"
  >
    <div class="card-body p-0 overflow-auto flex-grow-1 bg-body position-relative" ref="messagesContainer">
      <div v-if="!connectionStore.isConnected" class="list-group list-group-flush p-3 placeholder-glow">
        <div
          v-for="i in 6"
          :key="i"
          class="d-flex flex-column gap-2 mb-3"
          :class="{ 'align-items-end': i % 3 === 0 }"
        >
          <span class="placeholder" :class="i % 3 === 0 ? 'col-4' : 'col-7'"></span>
          <span class="placeholder col-5" v-if="i % 2 === 1"></span>
        </div>
      </div>
      <template v-else>
        <div class="list-group list-group-flush">
          <div
            v-for="event in chatStore.events"
            :key="event.id || `event-${event.timestamp}-${event.userId ?? event.botId ?? 'sys'}`"
            class="list-group-item border-0 bg-transparent"
          >
            <MessageItem :event="event" />
          </div>
        </div>
        <div v-if="chatStore.events.length === 0" class="text-center text-muted p-5">
          <p class="mb-0">No events yet. Start chatting!</p>
        </div>
      </template>
      <div
        v-if="dragDepth > 0 && connectionStore.isConnected"
        class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-primary bg-opacity-25 z-1 pointer-events-none"
      >
        <span class="fw-semibold text-body">Drop file to upload</span>
      </div>
    </div>

    <div
      v-if="fileBanner"
      class="chat-file-banner px-3 py-2 border-top border-secondary-subtle small d-flex align-items-center gap-2 flex-wrap"
    >
      <span class="text-muted chat-file-banner-label">Uploading {{ fileBanner.filename }}...</span>
      <div class="progress flex-grow-1 chat-upload-progress-track" role="presentation">
        <div
          class="progress-bar chat-upload-progress-bar"
          role="progressbar"
          :aria-valuenow="fileBanner.pct"
          aria-valuemin="0"
          aria-valuemax="100"
          :style="{ width: `${fileBanner.pct}%` }"
        />
      </div>
    </div>

    <div
      v-if="moderationBanner"
      class="px-3 py-2 border-top small d-flex align-items-center gap-2"
      :class="moderationBanner.className"
    >
      <span v-if="moderationBanner.phase === 'checking'" class="spinner-border spinner-border-sm" aria-hidden="true" />
      <span>{{ moderationBanner.text }}</span>
    </div>

    <div v-if="uploadClientError || chatStore.messageError" class="px-3 py-1 small text-danger border-top">
      {{ uploadClientError || chatStore.messageError }}
    </div>

    <div class="card-footer flex-shrink-0">
      <div v-if="attachmentDrafts.length > 0" class="chat-attachment-drafts d-flex flex-wrap gap-2 mb-2">
        <span
          v-for="draft in attachmentDrafts"
          :key="draft.draftId"
          class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-1 chat-attachment-draft"
          :title="draft.filename"
        >
          <i class="bi bi-paperclip" aria-hidden="true" />
          <span class="text-truncate">{{ draft.filename }}</span>
          <button
            type="button"
            class="btn-close btn-close-white chat-attachment-draft-remove"
            :disabled="chatStore.isModeratingMessage"
            aria-label="Remove attachment"
            @click="deleteAttachmentDraft(draft.draftId)"
          />
        </span>
      </div>
      <form @submit.prevent="handleSubmit" class="d-flex gap-2 align-items-center">
        <input
          ref="fileInputRef"
          type="file"
          class="d-none"
          accept="image/*,.pdf,.txt,text/plain,application/pdf"
          @change="onFileInputChange"
        />
        <button
          type="button"
          class="btn btn-outline-secondary flex-shrink-0"
          :disabled="!connectionStore.isConnected || isBinaryUploading || chatStore.isModeratingMessage"
          title="Attach file"
          aria-label="Attach file"
          @click="openFilePicker"
        >
          <i class="bi bi-paperclip" aria-hidden="true" />
        </button>
        <input
          :value="displayMessage"
          @input="handleInput"
          @paste="onPaste"
          type="text"
          class="form-control"
          placeholder="Type your message..."
          data-id="chat-input"
          :readonly="chatStore.isModeratingMessage"
          :disabled="!connectionStore.isConnected || chatStore.isModeratingMessage"
          maxlength="500"
        />
        <span
          v-if="isRateLimited"
          class="text-muted user-select-none chat-rate-limit-counter flex-shrink-0"
        >
          {{ rateLimitSecondsLeft }}s
        </span>
        <LoadingButton
          type="submit"
          variant="btn-primary"
          :loading="chatStore.isModeratingMessage"
          :disabled="!canSubmit"
          :loading-delay="300"
          data-id="chat-send"
        >
          Send
        </LoadingButton>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useConnectionStore } from '@hilos/sdk/stores'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { useChatStore } from '@/stores'
import { ATTACHMENT_DRAFT_DELETE, FILE_UPLOAD_INIT, MESSAGE_RATE_LIMIT_SECONDS } from '@/constants'
import MessageItem from './MessageItem.vue'
import { LoadingButton } from '@hilos/sdk/components'
import { sendAction } from '@/services/websocketActions'
import { registerFileUploadPending } from '@/services/chatFileUpload'

const connectionStore = useConnectionStore()
const chatStore = useChatStore()
const websocket = useWebSocket()
const messagesContainer = ref<HTMLElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const draftMessage = ref('')
const rateLimitSecondsLeft = ref(0)
const dragDepth = ref(0)
const isBinaryUploading = ref(false)
const uploadClientError = ref<string | null>(null)
let rateLimitInterval: ReturnType<typeof setInterval> | null = null

const CHUNK_SIZE = 65536

const emit = defineEmits<{
  send: [payload: { content: string; attachmentDraftIds: string[] }]
}>()

const attachmentDrafts = computed(() => chatStore.attachmentDrafts)
const outboundModerationState = computed(() => chatStore.outboundModerationState)
const displayMessage = computed(() => {
  if (outboundModerationState.value?.phase === 'checking') {
    return outboundModerationState.value.text
  }
  return draftMessage.value
})
const isRateLimited = computed(() => rateLimitSecondsLeft.value > 0)
const hasDraftContent = computed(() => {
  return draftMessage.value.trim().length > 0 || attachmentDrafts.value.length > 0
})
const canSubmit = computed(() => {
  return connectionStore.isConnected
    && !chatStore.isModeratingMessage
    && !isRateLimited.value
    && hasDraftContent.value
})

type FileBanner = { filename: string; pct: number }

const fileBanner = computed((): FileBanner | null => {
  const prog = chatStore.fileUploadProgress
  if (prog !== null) {
    const tot = prog.totalBytes > 0 ? prog.totalBytes : 1
    const pct = Math.min(100, Math.round((prog.uploadedBytes / tot) * 100))
    return { filename: prog.filename, pct }
  }
  return null
})

type ModerationBanner = {
  phase: 'checking' | 'rejected' | 'unavailable'
  text: string
  className: string
}

const moderationBanner = computed((): ModerationBanner | null => {
  const state = outboundModerationState.value
  if (!state) {
    return null
  }
  if (state.phase === 'checking') {
    return {
      phase: 'checking',
      text: 'Moderating message...',
      className: 'text-primary bg-primary bg-opacity-10',
    }
  }
  if (state.phase === 'rejected') {
    return {
      phase: 'rejected',
      text: state.reason ? `Message rejected: ${state.reason}` : 'Message rejected',
      className: 'text-danger bg-danger bg-opacity-10',
    }
  }
  if (state.phase === 'unavailable') {
    return {
      phase: 'unavailable',
      text: state.reason ? `Moderation unavailable: ${state.reason}` : 'Moderation unavailable',
      className: 'text-warning-emphasis bg-warning bg-opacity-10',
    }
  }
  return null
})

if (outboundModerationState.value !== null && outboundModerationState.value.phase !== 'checking') {
  draftMessage.value = outboundModerationState.value.text
}

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const startRateLimitCountdown = () => {
  rateLimitSecondsLeft.value = MESSAGE_RATE_LIMIT_SECONDS
  if (rateLimitInterval) clearInterval(rateLimitInterval)
  rateLimitInterval = setInterval(() => {
    rateLimitSecondsLeft.value--
    if (rateLimitSecondsLeft.value <= 0 && rateLimitInterval) {
      clearInterval(rateLimitInterval)
      rateLimitInterval = null
    }
  }, 1000)
}

const handleSubmit = () => {
  if (!canSubmit.value) return
  const content = draftMessage.value.trim()
  const attachmentDraftIds = attachmentDrafts.value.map((draft) => draft.draftId)
  chatStore.setMessageError(null)
  emit('send', { content, attachmentDraftIds })
  startRateLimitCountdown()
}

onUnmounted(() => {
  if (rateLimitInterval) clearInterval(rateLimitInterval)
})

const handleInput = (event: Event) => {
  if (chatStore.isModeratingMessage) {
    return
  }
  const target = event.target as HTMLInputElement
  draftMessage.value = target.value
}

watch(() => chatStore.events.length, scrollToBottom)
watch(
  () => chatStore.outboundModerationState,
  (state, previous) => {
    if (previous?.phase === 'checking' && state === null) {
      draftMessage.value = ''
      chatStore.clearAttachmentDrafts()
    }
    if (state !== null && state.phase !== 'checking' && draftMessage.value === '') {
      draftMessage.value = state.text
    }
  },
)
onMounted(scrollToBottom)

const openFilePicker = () => {
  fileInputRef.value?.click()
}

const onDragEnter = () => {
  dragDepth.value++
}

const onDragLeave = () => {
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

const uploadSingleFile = async (file: File) => {
  uploadClientError.value = null
  if (!connectionStore.isConnected || !websocket.sendBinary) {
    uploadClientError.value = 'Not connected or binary send unavailable'
    return
  }
  isBinaryUploading.value = true
  try {
    const clientUploadId = crypto.randomUUID()
    const waitReady = registerFileUploadPending()
    sendAction(websocket, FILE_UPLOAD_INIT, {
      filename: file.name,
      mimeType: file.type || 'application/octet-stream',
      size: file.size,
      clientUploadId,
    })
    const ready = await waitReady
    if (!ready.ok) {
      uploadClientError.value = ready.message ?? ready.code
      return
    }
    let offset = 0
    while (offset < file.size) {
      const slice = file.slice(offset, offset + CHUNK_SIZE)
      const buf = await slice.arrayBuffer()
      websocket.sendBinary(buf)
      offset += buf.byteLength
    }
  } finally {
    isBinaryUploading.value = false
  }
}

const onFileInputChange = (ev: Event) => {
  const input = ev.target as HTMLInputElement
  const f = input.files?.[0]
  input.value = ''
  if (f) {
    void uploadSingleFile(f)
  }
}

const onDropFiles = (ev: DragEvent) => {
  dragDepth.value = 0
  const f = ev.dataTransfer?.files?.[0]
  if (f) {
    void uploadSingleFile(f)
  }
}

const onPaste = (ev: ClipboardEvent) => {
  const f = ev.clipboardData?.files?.[0]
  if (f) {
    ev.preventDefault()
    void uploadSingleFile(f)
  }
}

const deleteAttachmentDraft = (draftId: string) => {
  if (chatStore.isModeratingMessage) {
    return
  }
  sendAction(websocket, ATTACHMENT_DRAFT_DELETE, { draftId })
}
</script>

<style scoped>
.chat-file-banner {
  background-color: rgba(var(--bs-tertiary-bg-rgb), 0.45);
}

.chat-upload-progress-track {
  min-width: 120px;
  height: 6px;
}

.chat-attachment-draft {
  max-width: min(100%, 260px);
}

.chat-attachment-draft .text-truncate {
  max-width: 210px;
}

.chat-attachment-draft-remove {
  width: 0.65rem;
  height: 0.65rem;
  padding: 0;
}

@media (max-width: 575.98px) {
  .chat-file-banner {
    flex-direction: column;
    align-items: stretch !important;
  }

  .chat-file-banner-label {
    width: 100%;
  }

  .chat-upload-progress-track {
    min-width: 0;
    width: 100%;
  }
}

@media (min-width: 576px) {
  .chat-upload-progress-track {
    max-width: 320px;
  }
}
</style>
