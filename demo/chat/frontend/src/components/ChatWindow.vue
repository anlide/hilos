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
            v-for="event in visibleEvents"
            :key="event.id || `event-${event.timestamp}-${event.userId ?? event.botId ?? 'sys'}`"
            class="list-group-item border-0 bg-transparent"
          >
            <MessageItem :event="event" />
          </div>
        </div>
        <div v-if="visibleEvents.length === 0" class="text-center text-muted p-5">
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
      <template v-if="fileBanner.kind === 'uploading'">
        <span class="text-muted chat-file-banner-label">Uploading {{ fileBanner.filename }}…</span>
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
      </template>
      <template v-else-if="fileBanner.kind === 'moderating'">
        <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true" />
        <span>Moderating file: {{ fileBanner.filename }}</span>
      </template>
      <template v-else-if="fileBanner.kind === 'rejected'">
        <span class="text-danger">File rejected{{ fileBanner.reason ? `: ${fileBanner.reason}` : '' }}</span>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary ms-auto"
          aria-label="Dismiss"
          @click="dismissFileRejection"
        >
          <i class="bi bi-x-lg" aria-hidden="true" />
        </button>
      </template>
    </div>

    <div v-if="uploadClientError || chatStore.messageError" class="px-3 py-1 small text-danger border-top">
      {{ uploadClientError || chatStore.messageError }}
    </div>

    <div class="card-footer flex-shrink-0">
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
          required
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
          :disabled="!connectionStore.isConnected || chatStore.isModeratingMessage || isRateLimited || !draftMessage.trim()"
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
import { FILE_MODERATION_DISMISS, FILE_UPLOAD_INIT, MESSAGE_RATE_LIMIT_SECONDS } from '@/constants'
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
  send: [message: string]
}>()

const hiddenEventTypes = new Set(['user_online', 'user_offline'])
const visibleEvents = computed(() => chatStore.events.filter((event) => !hiddenEventTypes.has(event.type)))
const currentUserModerationState = computed(() => chatStore.currentUserModerationState ?? null)
const displayMessage = computed(() => {
  if (currentUserModerationState.value !== null) {
    return currentUserModerationState.value
  }
  return draftMessage.value
})
const isRateLimited = computed(() => rateLimitSecondsLeft.value > 0)

type FileBanner =
  | { kind: 'uploading'; filename: string; pct: number }
  | { kind: 'moderating'; filename: string }
  | { kind: 'rejected'; reason: string }

const fileBanner = computed((): FileBanner | null => {
  const prog = chatStore.fileUploadProgress
  if (prog !== null) {
    const tot = prog.totalBytes > 0 ? prog.totalBytes : 1
    const pct = Math.min(100, Math.round((prog.uploadedBytes / tot) * 100))
    return { kind: 'uploading', filename: prog.filename, pct }
  }
  const s = chatStore.fileModerationState
  if (!s || typeof s.phase !== 'string') {
    return null
  }
  const phase = s.phase
  const fn = typeof s.filename === 'string' ? s.filename : 'file'
  if (phase === 'moderating') {
    return { kind: 'moderating', filename: fn }
  }
  if (phase === 'rejected') {
    const reason = typeof s.reason === 'string' ? s.reason : ''
    return { kind: 'rejected', reason }
  }
  return null
})

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
  if (!draftMessage.value.trim() || !connectionStore.isConnected || chatStore.isModeratingMessage || isRateLimited.value) return
  const text = draftMessage.value.trim()
  draftMessage.value = ''
  chatStore.setMessageError(null)
  emit('send', text)
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

watch(() => visibleEvents.value.length, scrollToBottom)
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

const dismissFileRejection = () => {
  sendAction(websocket, FILE_MODERATION_DISMISS, {})
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
