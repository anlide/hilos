// The main chat page selectors: the page payload's lists resolved into the
// view models the page renders. Like the session's current-user selector, the
// view reads these through useSignal and never touches a raw store — the
// reference resolution and the presence read live here, reactively, so a user
// rename or a presence flip updates without the list re-streaming.
import {
  computedSignal,
  type EntityId,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from './session'

// Wire keys of the main page lists and their slots — backend source collection
// names (see pages.ts pageEntityTypes for the sync rule).
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

// Canonical entity types built by hand to resolve an author against the entity
// the users/bots lists delivered (pageEntityTypes maps the slots to the same).
const USER_TYPE = 'user'
const BOT_TYPE = 'bot'

// Event type values mirror the backend ChatEventType enum; the stream renders a
// line per kind. Keep in sync with Demo\Chat\Constants\ChatEventType.
const EVENT_MESSAGE_SENT = 'message_sent'
const EVENT_USER_REGISTERED = 'user_registered'
const EVENT_USER_RENAMED = 'user_renamed'
const EVENT_USER_RENAMED_BY_ADMIN = 'user_renamed_by_admin'
const EVENT_CHAT_STARTED = 'chat_started'
const EVENT_CHAT_STOPPED = 'chat_stopped'
const EVENT_CHAT_CLEARED = 'chat_cleared'

/**
 * Resolve an entity reference to its display name in the current page scope,
 * reactively; empty while the entity is absent or has no string name.
 *
 * @param ref The entity reference to read.
 */
function entityName(ref: EntityRef): string {
  const name = scopes.entitySignal(ref).get()?.fields.name

  return typeof name === 'string' ? name : ''
}

/** Coerce a slot to a string field, or empty when absent or non-string. */
function stringField(slot: Record<string, unknown> | undefined, key: string): string {
  const value = slot?.[key]

  return typeof value === 'string' ? value : ''
}

/** A participant row of the main page roster. */
export interface Participant {
  /** The list item key (the user id), stable for keyed rendering. */
  readonly key: string
  /** The user's display name, resolved from the referenced entity. */
  readonly name: string
  /** `online` while the user holds a live connection, otherwise `offline`. */
  readonly presence: string
}

const mainUsers = scopes.pageListSignal(MAIN_USERS_LIST)

/** The main page roster: one participant per user list item, resolved reactively. */
export const mainParticipants: ReadonlySignal<readonly Participant[]> =
  computedSignal(() =>
    mainUsers.get().map((item) => {
      const ref = item.slots[USER_SLOT] as EntityRef | undefined
      const name = ref ? entityName(ref) : ''
      const connection = item.slots[CONNECTION_SLOT] as
        | { presence?: unknown }
        | undefined
      const presence = connection?.presence

      return {
        key: item.itemKey,
        name,
        presence: typeof presence === 'string' ? presence : 'offline',
      }
    }),
  )

/** A bot row of the main page bot list. */
export interface Bot {
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

/** The main page bot list: one bot per item, name from the entity, status inline. */
export const mainBots: ReadonlySignal<readonly Bot[]> = computedSignal(() =>
  mainBotItems.get().map((item) => {
    const ref = item.slots[BOT_SLOT] as EntityRef | undefined
    const fields = ref ? scopes.entitySignal(ref).get()?.fields : undefined
    const status = item.slots[BOT_STATUS_SLOT] as
      | Record<string, unknown>
      | undefined

    return {
      key: item.itemKey,
      name: stringField(fields, 'name'),
      description: stringField(fields, 'description'),
      status: stringField(status, 'status'),
    }
  }),
)

/** One published attachment shown under a message event. */
export interface EventAttachment {
  /** The attachment id, stable for keyed rendering. */
  readonly key: string
  /** The original file name, resolved from the referenced entity. */
  readonly filename: string
}

/** One event of the main page stream — a message or a service notice. */
export interface ChatEvent {
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
  readonly attachments: readonly EventAttachment[]
}

const mainEventItems = scopes.pageListSignal(MAIN_EVENTS_LIST)

/**
 * Build a service notice line for a non-message event, mirroring the backend
 * event taxonomy; empty for a `message_sent` event, whose body renders instead.
 *
 * @param type The event type value.
 * @param rename The inline rename slot, read for the old/new names.
 */
function serviceDescription(
  type: string,
  rename: Record<string, unknown> | undefined,
): string {
  switch (type) {
    case EVENT_USER_REGISTERED:
      return 'registered in chat'
    case EVENT_USER_RENAMED:
    case EVENT_USER_RENAMED_BY_ADMIN: {
      const oldName = stringField(rename, 'oldName')
      const newName = stringField(rename, 'newName')
      const byAdmin = type === EVENT_USER_RENAMED_BY_ADMIN ? ' by admin' : ''
      if (oldName !== '' && newName !== '') {
        return `renamed${byAdmin} from ${oldName} to ${newName}`
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
 * @param message The inline message slot, or undefined.
 * @param registration The inline registration slot, or undefined.
 * @param rename The inline rename slot, or undefined.
 */
function eventAuthor(
  message: Record<string, unknown> | undefined,
  registration: Record<string, unknown> | undefined,
  rename: Record<string, unknown> | undefined,
): { name: string; isBot: boolean } {
  const botId = message?.authorBotId
  if (botId != null) {
    return { name: entityName({ type: BOT_TYPE, id: botId as EntityId }), isBot: true }
  }
  const authorUserId = message?.authorUserId
  if (authorUserId != null) {
    return { name: entityName({ type: USER_TYPE, id: authorUserId as EntityId }), isBot: false }
  }
  const targetUserId = registration?.targetUserId ?? rename?.targetUserId
  if (targetUserId != null) {
    return { name: entityName({ type: USER_TYPE, id: targetUserId as EntityId }), isBot: false }
  }

  return { name: '', isBot: false }
}

/** The main page event stream: each list item resolved into a renderable event. */
export const mainEvents: ReadonlySignal<readonly ChatEvent[]> = computedSignal(
  () =>
    mainEventItems.get().map((item) => {
      const eventRef = item.slots[EVENT_SLOT] as EntityRef | undefined
      const fields = eventRef
        ? scopes.entitySignal(eventRef).get()?.fields
        : undefined
      const type = stringField(fields, 'type')
      const message = item.slots[MESSAGE_SLOT] as
        | Record<string, unknown>
        | undefined
      const registration = item.slots[REGISTRATION_SLOT] as
        | Record<string, unknown>
        | undefined
      const rename = item.slots[RENAME_SLOT] as
        | Record<string, unknown>
        | undefined
      const author = eventAuthor(message, registration, rename)
      const attachmentRefs = item.slots[ATTACHMENT_SLOT]
      const attachments: EventAttachment[] = Array.isArray(attachmentRefs)
        ? attachmentRefs.map((ref) => {
            const attachmentRef = ref as EntityRef
            const attachmentFields = scopes
              .entitySignal(attachmentRef)
              .get()?.fields

            return {
              key: String(attachmentRef.id),
              filename: stringField(attachmentFields, 'filename'),
            }
          })
        : []

      return {
        key: item.itemKey,
        type,
        timestamp: stringField(fields, 'timestamp'),
        authorName: author.name,
        authorIsBot: author.isBot,
        text: type === EVENT_MESSAGE_SENT ? stringField(message, 'message') : '',
        description: serviceDescription(type, rename),
        attachments,
      }
    }),
)
