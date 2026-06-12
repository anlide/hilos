// The main chat page selectors: the page payload's lists resolved into the row
// view models the page renders. These are page-specific presentations (a roster
// row, a bot row, an event line) — not entities; they reference the domain
// entities (src/types) through their collections, so a rename or a presence flip
// updates reactively without the list re-streaming. The view reads these signals
// and never touches a raw store.
import {
  computedSignal,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from './session'
import {
  Bots,
  EventAttachments,
  Events,
  Users,
  eventMessageFrom,
  eventRegistrationFrom,
  eventRenameFrom,
  toPresence,
  type EventMessage,
  type EventUserRegistration,
  type EventUserRename,
  type Presence,
} from './types'

// Wire keys of the main page lists and their slots — backend source collection
// names (see pages.ts pageEntityTypes for the slot→type sync rule).
const MAIN_USERS_LIST = 'mainUsers'
const MAIN_BOTS_LIST = 'mainBots'
const MAIN_EVENTS_LIST = 'mainEvents'
const USER_SLOT = 'users'
const CONNECTION_SLOT = 'connections'
const BOT_SLOT = 'bots'
const BOT_STATUS_SLOT = 'botAgentStatuses'
const EVENT_SLOT = 'events'
const MESSAGE_SLOT = 'eventMessages'
const REGISTRATION_SLOT = 'eventUserRegistrations'
const RENAME_SLOT = 'eventUserRenames'
const ATTACHMENT_SLOT = 'eventAttachments'

// Event type values mirror the backend ChatEventType enum; the stream renders a
// line per kind. Keep in sync with Demo\Chat\Constants\ChatEventType.
const EVENT_MESSAGE_SENT = 'message_sent'
const EVENT_USER_REGISTERED = 'user_registered'
const EVENT_USER_RENAMED = 'user_renamed'
const EVENT_USER_RENAMED_BY_ADMIN = 'user_renamed_by_admin'
const EVENT_CHAT_STARTED = 'chat_started'
const EVENT_CHAT_STOPPED = 'chat_stopped'
const EVENT_CHAT_CLEARED = 'chat_cleared'

/** Read a list item's slot as an inline record, or undefined. */
function recordSlot(
  slot: unknown,
): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** A participant row of the main page roster. */
export interface Participant {
  /** The list item key (the user id), stable for keyed rendering. */
  readonly key: string
  /** The user's display name, resolved from the referenced entity. */
  readonly name: string
  /** The user's connection presence. */
  readonly presence: Presence
}

const mainUserItems = scopes.pageListSignal(MAIN_USERS_LIST)

/** The main page roster: one participant per user list item, resolved reactively. */
export const mainParticipants: ReadonlySignal<readonly Participant[]> =
  computedSignal(() =>
    mainUserItems.get().map((item) => {
      const ref = item.slots[USER_SLOT] as EntityRef | undefined
      const name = ref ? (Users.signal(ref).get()?.name ?? '') : ''
      const connection = recordSlot(item.slots[CONNECTION_SLOT])

      return {
        key: item.itemKey,
        name,
        presence: toPresence(connection?.['presence']),
      }
    }),
  )

/** A bot row of the main page bot list. */
export interface BotRow {
  /** The list item key (the bot id), stable for keyed rendering. */
  readonly key: string
  /** The bot's display name, resolved from the referenced entity. */
  readonly name: string
  /** The bot's description, resolved from the referenced entity. */
  readonly description: string
  /** The bot agent's runtime status, or empty when the agent has not reported. */
  readonly status: string
}

const mainBotItems = scopes.pageListSignal(MAIN_BOTS_LIST)

/** The main page bot list: one bot row per item, entity by reference, status inline. */
export const mainBots: ReadonlySignal<readonly BotRow[]> = computedSignal(() =>
  mainBotItems.get().map((item) => {
    const ref = item.slots[BOT_SLOT] as EntityRef | undefined
    const bot = ref ? Bots.signal(ref).get() : undefined
    const status = recordSlot(item.slots[BOT_STATUS_SLOT])

    return {
      key: item.itemKey,
      name: bot?.name ?? '',
      description: bot?.description ?? '',
      status: typeof status?.['status'] === 'string' ? status['status'] : '',
    }
  }),
)

/** One published attachment shown under a message event line. */
export interface EventAttachmentLine {
  /** The attachment id, stable for keyed rendering. */
  readonly key: string
  /** The original file name, resolved from the referenced entity. */
  readonly filename: string
}

/** One event line of the main page stream — a message or a service notice. */
export interface EventLine {
  /** The list item key (the event id), stable for keyed rendering. */
  readonly key: string
  /** The event type value (see ChatEventType). */
  readonly type: string
  /** The event timestamp string, as the backend stored it. */
  readonly timestamp: string
  /** The acting user or bot's name, or empty for a chat-lifecycle notice. */
  readonly authorName: string
  /** True when the author is a bot, so the view can mark it. */
  readonly authorIsBot: boolean
  /** The message body for a `message_sent` event, otherwise empty. */
  readonly text: string
  /** A service notice line for a non-message event, otherwise empty. */
  readonly description: string
  /** The published attachments of a message event, resolved reactively. */
  readonly attachments: readonly EventAttachmentLine[]
}

const mainEventItems = scopes.pageListSignal(MAIN_EVENTS_LIST)

/**
 * Build a service notice line for a non-message event, mirroring the backend
 * event taxonomy; empty for a `message_sent` event, whose body renders instead.
 *
 * @param type The event type value.
 * @param rename The typed rename detail, read for the old/new names.
 */
function serviceDescription(
  type: string,
  rename: EventUserRename | null,
): string {
  switch (type) {
    case EVENT_USER_REGISTERED:
      return 'registered in chat'
    case EVENT_USER_RENAMED:
    case EVENT_USER_RENAMED_BY_ADMIN: {
      const byAdmin = type === EVENT_USER_RENAMED_BY_ADMIN ? ' by admin' : ''
      if (rename && rename.oldName !== '' && rename.newName !== '') {
        return `renamed${byAdmin} from ${rename.oldName} to ${rename.newName}`
      }

      return `renamed${byAdmin}`
    }
    case EVENT_CHAT_STARTED:
      return 'Chat started'
    case EVENT_CHAT_STOPPED:
      return 'Chat stopped'
    case EVENT_CHAT_CLEARED:
      return 'Chat history cleared'
    default:
      return ''
  }
}

/**
 * Resolve the acting party's name for an event: the message author (user or
 * bot), else the targeted user of a registration or rename notice.
 *
 * @param message The typed message detail, or null.
 * @param registration The typed registration detail, or null.
 * @param rename The typed rename detail, or null.
 */
function eventAuthor(
  message: EventMessage | null,
  registration: EventUserRegistration | null,
  rename: EventUserRename | null,
): { name: string; isBot: boolean } {
  if (message?.authorBotId != null) {
    return { name: Bots.signal(message.authorBotId).get()?.name ?? '', isBot: true }
  }
  if (message?.authorUserId != null) {
    return { name: Users.signal(message.authorUserId).get()?.name ?? '', isBot: false }
  }
  const targetUserId = registration?.targetUserId ?? rename?.targetUserId
  if (targetUserId != null) {
    return { name: Users.signal(targetUserId).get()?.name ?? '', isBot: false }
  }

  return { name: '', isBot: false }
}

/** The main page event stream: each list item resolved into a renderable line. */
export const mainEvents: ReadonlySignal<readonly EventLine[]> = computedSignal(
  () =>
    mainEventItems.get().map((item) => {
      const eventRef = item.slots[EVENT_SLOT] as EntityRef | undefined
      const event = eventRef ? Events.signal(eventRef).get() : undefined
      const type = event?.type ?? ''
      const message = eventMessageFrom(recordSlot(item.slots[MESSAGE_SLOT]))
      const registration = eventRegistrationFrom(
        recordSlot(item.slots[REGISTRATION_SLOT]),
      )
      const rename = eventRenameFrom(recordSlot(item.slots[RENAME_SLOT]))
      const author = eventAuthor(message, registration, rename)
      const attachmentRefs = item.slots[ATTACHMENT_SLOT]
      const attachments: EventAttachmentLine[] = Array.isArray(attachmentRefs)
        ? attachmentRefs.map((ref) => {
            const attachmentRef = ref as EntityRef
            const attachment = EventAttachments.signal(attachmentRef).get()

            return {
              key: String(attachment?.id ?? attachmentRef.id),
              filename: attachment?.filename ?? '',
            }
          })
        : []

      return {
        key: item.itemKey,
        type,
        timestamp: event?.timestamp ?? '',
        authorName: author.name,
        authorIsBot: author.isBot,
        text: type === EVENT_MESSAGE_SENT ? (message?.message ?? '') : '',
        description: serviceDescription(type, rename),
        attachments,
      }
    }),
)
