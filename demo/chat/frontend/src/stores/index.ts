import { defineStore } from 'pinia'
import { ChatBot, Event, User } from '@/types'
import type { Presence } from '@/types/domain/Presence'
import type { BotPresencePayload, UserConnectionStatsPayload, UserPresencePayload } from '@/entities/frontendStateParsers'

/** Binary upload progress: seeded at 0/total by file_upload_ready, then throttled progress_update + main subscribe. */
export type FileUploadProgressPayload = {
  filename: string
  uploadedBytes: number
  totalBytes: number
}

export type AttachmentDraftPayload = {
  draftId: string
  filename: string
  mimeType: string
  size: number
  uploadedAt: number
}

export type OutboundModerationStatePayload = {
  requestId: string
  phase: 'checking' | 'rejected' | 'unavailable'
  text: string
  attachments: AttachmentDraftPayload[]
  reason: string | null
  updatedAt: number
}

export type SelfConnectionPayload = {
  userId: number
  connectedAt: number
  messageRateLimitSecondsRemaining: number
  outboundModerationState: OutboundModerationStatePayload | null
  attachmentDrafts: AttachmentDraftPayload[]
  fileUploadProgress: FileUploadProgressPayload | null
}

export type UserViewModel = User & {
  presence: Presence
  onlineSessionCount: number
}

export type BotViewModel = ChatBot & {
  presence: Presence
}

/**
 * Chat-specific store — domain entities and user session state.
 * Connection, table, pageCatalog, and guardian state live in framework stores.
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    events: [] as Event[],
    usersById: {} as Record<number, User>,
    userPresenceById: {} as Record<number, UserPresencePayload>,
    userConnectionStatsById: {} as Record<number, UserConnectionStatsPayload>,
    bots: [] as ChatBot[],
    botPresenceById: {} as Record<number, BotPresencePayload>,
    currentUserId: null as number | null,
    currentUsername: null as string | null,
    selfConnection: null as SelfConnectionPayload | null,
    messageError: null as string | null,
  }),

  getters: {
    users(): User[] {
      return Object.values(this.usersById).sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
    },
    userViewModels(): UserViewModel[] {
      return this.users.map((user) => {
        const userId = user.id ?? 0
        return {
          ...user,
          presence: this.userPresenceById[userId]?.presence ?? 'offline',
          onlineSessionCount: this.userConnectionStatsById[userId]?.onlineSessionCount ?? 0,
        }
      })
    },
    onlineUsers(): UserViewModel[] {
      return this.userViewModels.filter((user) => user.presence === 'online')
    },
    botViewModels(): BotViewModel[] {
      return this.bots.map((bot) => ({
        ...bot,
        presence: this.botPresenceById[bot.id]?.presence ?? 'offline',
      }))
    },
    onlineBots(): BotViewModel[] {
      return this.botViewModels.filter((bot) => bot.presence === 'online')
    },
    currentUser(): UserViewModel | null {
      if (this.currentUserId === null) {
        return null
      }
      return this.userViewModels.find((user) => user.id === this.currentUserId) ?? null
    },
    outboundModerationState(): OutboundModerationStatePayload | null {
      return this.selfConnection?.outboundModerationState ?? null
    },
    attachmentDrafts(): AttachmentDraftPayload[] {
      return this.selfConnection?.attachmentDrafts ?? []
    },
    fileUploadProgress(): FileUploadProgressPayload | null {
      return this.selfConnection?.fileUploadProgress ?? null
    },
    messageRateLimitSecondsRemaining(): number {
      return this.selfConnection?.messageRateLimitSecondsRemaining ?? 0
    },
    isModeratingMessage(): boolean {
      return this.outboundModerationState?.phase === 'checking'
    },
  },

  actions: {
    handleSubscriptionResponse(userId: number, username: string) {
      this.currentUserId = userId
      this.currentUsername = username
    },

    setSelfConnection(value: SelfConnectionPayload) {
      this.selfConnection = value
    },

    clearSelfConnection() {
      this.selfConnection = null
    },

    setOutboundModerationState(value: OutboundModerationStatePayload | null) {
      if (this.selfConnection !== null) {
        this.selfConnection.outboundModerationState = value
      }
    },

    setMessageError(value: string | null) {
      this.messageError = value
    },

    setAttachmentDrafts(value: AttachmentDraftPayload[]) {
      if (this.selfConnection !== null) {
        this.selfConnection.attachmentDrafts = value
      }
    },

    addAttachmentDraft(value: AttachmentDraftPayload) {
      if (this.selfConnection === null) {
        return
      }
      const index = this.attachmentDrafts.findIndex((draft) => draft.draftId === value.draftId)
      if (index >= 0) {
        this.selfConnection.attachmentDrafts[index] = value
        return
      }
      this.selfConnection.attachmentDrafts.push(value)
    },

    removeAttachmentDraft(draftId: string) {
      if (this.selfConnection !== null) {
        this.selfConnection.attachmentDrafts = this.attachmentDrafts.filter((draft) => draft.draftId !== draftId)
      }
    },

    clearAttachmentDrafts() {
      if (this.selfConnection !== null) {
        this.selfConnection.attachmentDrafts = []
      }
    },

    setFileUploadProgress(value: FileUploadProgressPayload | null) {
      if (this.selfConnection !== null) {
        this.selfConnection.fileUploadProgress = value
      }
    },

    addEvent(event: Event) {
      this.events.push(event)
      if (this.events.length > 1000) {
        this.events.shift()
      }
    },

    upsertEvents(events: Event[]) {
      for (const event of events) {
        const id = event.id
        if (id === null) {
          this.addEvent(event)
          continue
        }
        const existingIndex = this.events.findIndex((ev) => ev.id === id)
        if (existingIndex >= 0) {
          this.events[existingIndex] = event
        } else {
          this.addEvent(event)
        }
      }
    },

    addUser(user: User) {
      if (user.id === null) {
        return
      }
      this.usersById[user.id] = user
    },

    upsertBots(bots: ChatBot[]) {
      for (const bot of bots) {
        const existingIndex = this.bots.findIndex((b) => b.id === bot.id)
        if (existingIndex >= 0) {
          this.bots[existingIndex] = bot
        } else {
          this.bots.push(bot)
        }
      }
    },

    upsertUsers(users: Array<{ id: number; name: string; lastActivity?: string | null }>, replace = false) {
      if (replace) {
        this.usersById = {}
      }
      for (const user of users) {
        this.addUser(User.fromObject({
          id: user.id,
          name: user.name,
          lastActivity: user.lastActivity ?? null,
        }))
      }
    },

    patchUsers(partials: Array<{ id: number; name?: string; lastActivity?: string | null }>) {
      for (const p of partials) {
        const existing = this.usersById[p.id]
        if (existing !== undefined) {
          this.usersById[p.id] = User.fromObject({
            id: existing.id,
            name: p.name ?? existing.name,
            lastActivity: p.lastActivity ?? existing.lastActivity ?? null,
          })
        } else if (p.name !== undefined) {
          this.addUser(
            User.fromObject({
              id: p.id,
              name: p.name,
              lastActivity: p.lastActivity ?? null,
            })
          )
        }
      }
    },

    removeUsers(userIds: number[]) {
      if (userIds.length === 0) {
        return
      }
      for (const id of userIds) {
        delete this.usersById[id]
        delete this.userPresenceById[id]
        delete this.userConnectionStatsById[id]
      }
    },

    upsertUserPresence(items: UserPresencePayload[], replace = false) {
      if (replace) {
        this.userPresenceById = {}
      }
      for (const item of items) {
        this.userPresenceById[item.userId] = item
      }
    },

    removeUserPresence(userIds: number[]) {
      for (const id of userIds) {
        delete this.userPresenceById[id]
      }
    },

    upsertUserConnectionStats(items: UserConnectionStatsPayload[], replace = false) {
      if (replace) {
        this.userConnectionStatsById = {}
      }
      for (const item of items) {
        this.userConnectionStatsById[item.userId] = item
      }
    },

    removeUserConnectionStats(userIds: number[]) {
      for (const id of userIds) {
        delete this.userConnectionStatsById[id]
      }
    },

    upsertBotPresence(items: BotPresencePayload[], replace = false) {
      if (replace) {
        this.botPresenceById = {}
      }
      for (const item of items) {
        this.botPresenceById[item.botId] = item
      }
    },

    setBotPresence(botId: number, presence: Presence) {
      this.botPresenceById[botId] = { botId, presence }
    },

    removeBotPresence(botIds: number[]) {
      for (const id of botIds) {
        delete this.botPresenceById[id]
      }
    },

    clearEvents() {
      this.events = []
    },

    removeEventsById(eventIds: number[]) {
      if (eventIds.length === 0) {
        return
      }
      const ids = new Set(eventIds)
      this.events = this.events.filter(ev => ev.id === null || !ids.has(ev.id))
    },

  }
})
