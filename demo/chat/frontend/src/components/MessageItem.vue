<template>
  <!-- All events (messages and service messages) -->
  <div
    class="d-flex mb-2"
    :class="isOwnMessage ? 'justify-content-end' : 'justify-content-start'"
  >
    <div
      class="d-flex flex-column p-2 rounded"
      :class="[
        getBubbleClass(),
        { 'w-75': props.event.type === 'message_sent' }
      ]"
    >
      <div v-if="!isOwnMessage" class="d-flex align-items-baseline gap-2">
        <span v-if="getHeaderIcon()" class="opacity-75" :title="getBotName(props.event.authorBotId)">{{ getHeaderIcon() }}</span>
        <RouterLink
          v-if="getHeaderUserName() && getHeaderUserId() !== null"
          class="fw-bold text-decoration-none"
          :class="isServiceMessage ? 'text-secondary' : 'text-primary'"
          :to="{ name: 'user', params: { id: getHeaderUserId() } }"
        >
          {{ getHeaderUserName() }}
        </RouterLink>
        <RouterLink
          v-else-if="getHeaderUserName() && props.event.authorBotId != null"
          class="fw-bold text-decoration-none text-primary"
          :to="{ name: 'bot', params: { id: props.event.authorBotId } }"
        >
          {{ getHeaderUserName() }}
        </RouterLink>
        <span
          v-else-if="getHeaderUserName()"
          class="fw-bold text-primary"
        >
          {{ getHeaderUserName() }}
        </span>
        <span
          v-if="getServiceTitle()"
          class="fw-bold text-secondary"
        >
          {{ getServiceTitle() }}
        </span>
        <small class="text-muted">{{ formatTime(event.timestamp) }}</small>
      </div>
      <div v-if="getMessageText()" :class="isOwnMessage ? 'mt-1' : 'ms-3 mt-1'">
        <template v-for="(segment, i) in getLinkifiedSegments()" :key="i">
          <a
            v-if="segment.type === 'link'"
            :href="segment.url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-decoration-underline"
            :class="isOwnMessage ? 'link-light' : 'link-primary'"
          >{{ segment.url }}</a>
          <template v-else>{{ segment.text }}</template>
        </template>
      </div>
      <div
        v-if="attachments.length > 0"
        class="d-flex flex-column gap-2"
        :class="isOwnMessage ? 'mt-2' : 'ms-3 mt-2'"
      >
        <div v-for="attachment in attachments" :key="attachment.id">
          <template v-if="isImageAttachment(attachment)">
            <a :href="attachmentDownloadUrl(attachment)" target="_blank" rel="noopener noreferrer" class="d-inline-block">
              <img
                :src="attachmentDownloadUrl(attachment)"
                :alt="attachment.filename"
                :class="[
                  'img-fluid rounded border border-secondary-subtle d-block',
                  attachmentPreviewWideLayout ? 'chat-attachment-preview--lg' : 'chat-attachment-preview--sm',
                ]"
              />
            </a>
          </template>
          <template v-else>
            <a
              :href="attachmentDownloadUrl(attachment)"
              target="_blank"
              rel="noopener noreferrer"
              class="d-inline-flex align-items-center gap-2 text-decoration-none"
              :class="isOwnMessage ? 'link-light' : 'link-primary'"
            >
              <span class="fs-4" aria-hidden="true">{{ fileTypeIcon(attachment) }}</span>
              <span class="text-break">{{ attachment.filename }}</span>
            </a>
          </template>
        </div>
      </div>
      <div v-if="isOwnMessage" class="text-end">
        <small class="opacity-75">{{ formatTime(event.timestamp) }}</small>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { Event, type EventAttachment } from '@/types'
import { useChatStore } from '@/stores'
import { config } from '@/config'
import { localStorageService } from '@/services/LocalStorageService'

interface Props {
  event: Event
}

const props = defineProps<Props>()
const chatStore = useChatStore()

const isServiceMessage = computed(() => {
  return props.event.type !== 'message_sent'
})

/** True if this is a message_sent from the current user (own message, show on right) */
const isOwnMessage = computed(() => {
  if (props.event.authorUserId === null || props.event.authorUserId !== chatStore.currentUserId) {
    return false
  }
  return props.event.type === 'message_sent'
})

const attachments = computed((): EventAttachment[] => {
  if (props.event.type !== 'message_sent') {
    return []
  }

  return props.event.attachments
})

const attachmentDownloadUrl = (attachment: EventAttachment): string => {
  const session = localStorageService.getSessionWithInit()
  const base = `${config.httpStatusProtocol}://${config.httpStatusHost}:${config.httpStatusPort}`
  const q = new URLSearchParams({
    id: String(attachment.id),
    'Hilos-Session-Token': session,
  })
  return `${base}/chat/attachment?${q.toString()}`
}

const isImageAttachment = (attachment: EventAttachment): boolean => {
  return attachment.mimeType.startsWith('image/')
}

/**
 * Bootstrap sm (576px) toggles preview size classes below. Theme (light/dark) uses border utilities on the img.
 */
const attachmentPreviewWideLayout = ref(false)

onMounted(() => {
  const mql = window.matchMedia('(min-width: 576px)')
  const sync = () => {
    attachmentPreviewWideLayout.value = mql.matches
  }
  sync()
  mql.addEventListener('change', sync)
  onUnmounted(() => {
    mql.removeEventListener('change', sync)
  })
})

const fileTypeIcon = (attachment: EventAttachment): string => {
  const m = attachment.mimeType
  if (m === 'application/pdf') {
    return '\u{1F4C4}'
  }
  if (m.startsWith('text/')) {
    return '\u{1F4C3}'
  }
  return '\u{1F4CE}'
}

const formatTime = (timestamp: string): string => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

const getParticipantName = (userId: number | null): string => {
  if (userId === null) {
    return 'Unknown user'
  }
  const user = chatStore.users.find((u) => u.id === userId)
  return user?.name || `User${userId}`
}

const BOT_ICON = '\u{1F916}' // Unicode robot face

const getBotName = (botId: number | null | undefined): string => {
  if (botId == null) {
    return 'Unknown bot'
  }
  const bot = chatStore.bots.find((b) => b.id === botId)
  return bot?.name ?? `Bot${botId}`
}

const getHeaderUserId = (): number | null => {
  if (props.event.type === 'user_renamed' || props.event.type === 'user_renamed_by_admin') {
    return null
  }

  return props.event.type === 'message_sent'
    ? props.event.authorUserId
    : props.event.targetUserId
}

const getHeaderUserName = (): string => {
  if (props.event.authorBotId != null) {
    return getBotName(props.event.authorBotId)
  }
  const userId = getHeaderUserId()
  if (userId === null) {
    return ''
  }

  return getParticipantName(userId)
}

const getHeaderIcon = (): string => {
  if (props.event.authorBotId != null) {
    return BOT_ICON
  }
  return ''
}

const getServiceTitle = (): string => {
  if (!isServiceMessage.value) {
    return ''
  }

  switch (props.event.type) {
    case 'user_registered':
      return 'registered in chat'
    case 'user_renamed': {
      const oldName = props.event.oldName
      const newName = props.event.newName
      if (oldName && newName) {
        return `renamed from ${oldName} to ${newName}`
      }
      return 'renamed'
    }
    case 'user_renamed_by_admin': {
      const oldName = props.event.oldName
      const newName = props.event.newName
      if (oldName && newName) {
        return `renamed by admin from ${oldName} to ${newName}`
      }
      return 'renamed by admin'
    }
    case 'chat_started':
      return 'Chat started'
    case 'chat_stopped':
      return 'Chat stopped'
    case 'chat_cleared':
      return 'Chat history cleared'
    default:
      return `Event: ${props.event.type}`
  }
}

const getMessageText = (): string => {
  // For regular messages, show the message content.
  if (props.event.type === 'message_sent') {
    return props.event.message ?? ''
  }

  // For service messages, only show explicit message payloads.
  return props.event.message ?? ''
}

/** URL regex: matches http(s):// followed by non-whitespace */
const URL_REGEX = /(https?:\/\/[^\s]+)/g

type TextSegment = { type: 'text'; text: string }
type LinkSegment = { type: 'link'; url: string }
type MessageSegment = TextSegment | LinkSegment

const getLinkifiedSegments = (): MessageSegment[] => {
  const text = getMessageText()
  if (!text) return []

  const parts = text.split(URL_REGEX)
  const segments: MessageSegment[] = []

  for (let i = 0; i < parts.length; i++) {
    const part = parts[i]
    if (!part) continue
    // Odd indices are URL matches (regex capture groups).
    if (i % 2 === 1 && part.startsWith('http')) {
      segments.push({ type: 'link', url: part })
    } else {
      segments.push({ type: 'text', text: part })
    }
  }

  return segments
}

const getServiceMessageClass = (): string => {
  if (!isServiceMessage.value) {
    return ''
  }
  switch (props.event.type) {
    case 'user_registered':
      return 'bg-success bg-opacity-25'
    case 'user_renamed':
      return 'bg-info bg-opacity-25'
    case 'user_renamed_by_admin':
      return 'bg-warning bg-opacity-25'
    case 'chat_started':
    case 'chat_stopped':
    case 'chat_cleared':
      return 'bg-primary bg-opacity-25'
    default:
      return 'bg-secondary bg-opacity-25'
  }
}

/** Bubble styles: own=primary, others=body secondary (theme-aware), service=existing */
const getBubbleClass = (): string => {
  if (isOwnMessage.value) {
    return 'bg-primary text-white'
  }
  if (props.event.type === 'message_sent') {
    return 'bg-body-secondary text-body border border-secondary-subtle'
  }
  return getServiceMessageClass()
}
</script>

<style scoped>
/*
 * Cannot achieve responsive max-height (including min(50vh, 220px)) with Bootstrap 5 classes alone.
 * Breakpoint is driven by Vue :class via matchMedia; only the numeric caps stay in CSS.
 */
.chat-attachment-preview--sm {
  max-width: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  max-height: min(50vh, 220px);
}

.chat-attachment-preview--lg {
  max-width: 100%;
  width: auto;
  height: auto;
  object-fit: contain;
  max-height: 240px;
}
</style>
