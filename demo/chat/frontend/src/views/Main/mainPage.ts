// The main chat page selectors: the page payload's lists resolved into the
// list-item view-models the page renders. Those view-models live alongside this
// module in `./types/lists` (an `…Item` per list row); this file only builds the
// signals that resolve them, reading the domain entities (`src/types`) by
// reference so a rename or a presence flip updates reactively without the list
// re-streaming. The view reads these signals and never touches a raw store.
import {
  computedSignal,
  readStringOrNull,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core/base'

import { scopes } from '../../bootstrap/session'
import {
  Bots,
  EventAttachments,
  Events,
  Users,
  eventMessageFrom,
  eventRegistrationFrom,
  toPresence,
  userRenameFrom,
  type EventMessage,
  type EventUserRegistration,
  type UserRename,
} from '../../types'
import { toSelfConnection, type SelfConnection } from './types/SelfConnection'
import { type BotItem } from './types/lists/BotItem'
import {
  type EventAttachmentItem,
  type EventItem,
} from './types/lists/EventItem'
import { type ParticipantItem } from './types/lists/ParticipantItem'

// Wire keys of the main page lists and their slots — backend source collection
// names (see pages.ts pageEntityTypes for the slot→type sync rule).
const MAIN_USERS_LIST = 'mainUsers'
const MAIN_BOTS_LIST = 'mainBots'
const MAIN_EVENTS_LIST = 'mainEvents'
// The single-row data slot carrying this connection's own composer state
// (backend ChatBrowserTable::SELF_CONNECTION).
const SELF_CONNECTION_DATA = 'selfConnection'
const USER_SLOT = 'users'
const CONNECTION_SLOT = 'connections'
const BOT_SLOT = 'bots'
const BOT_STATUS_SLOT = 'botAgentStatuses'
const EVENT_SLOT = 'events'
const MESSAGE_SLOT = 'eventMessages'
const REGISTRATION_SLOT = 'eventUserRegistrations'
const RENAME_SLOT = 'userRenames'
const ATTACHMENT_SLOT = 'eventAttachments'
// The person's photo and merge slots of a `mainUsers` item, each present only
// for a person who has one (backend MainUsersBrowserList), and the photo row's
// field carrying the address of its public avatar variant (backend
// Hilos\Database\View\Item\UserPhoto::photo).
const PHOTO_SLOT = 'userPhotos'
const MERGE_SLOT = 'userMerges'
const PHOTO_FIELD = 'photo'

// Event type values mirror the backend ChatEventType enum; the stream renders a
// line per kind. Keep in sync with Demo\Chat\Constants\ChatEventType. The view
// tells a message line from a notice by the first one.
export const EVENT_MESSAGE_SENT = 'message_sent'
const EVENT_USER_REGISTERED = 'user_registered'
const EVENT_USER_RENAMED = 'user_renamed'
const EVENT_USER_RENAMED_BY_ADMIN = 'user_renamed_by_admin'
const EVENT_CHAT_STARTED = 'chat_started'
const EVENT_CHAT_STOPPED = 'chat_stopped'
const EVENT_CHAT_CLEARED = 'chat_cleared'

/** Read a list item's slot as an inline record, or undefined. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

const mainUserItems = scopes.pageListSignal(MAIN_USERS_LIST)

/**
 * Read the address of a `mainUsers` item's photo, or null for a person without one.
 *
 * @param slot The item's photo slot, absent for a person without a photo.
 */
function photoOf(slot: unknown): string | null {
  const photo = recordSlot(slot)

  return photo ? readStringOrNull(photo, PHOTO_FIELD) : null
}

/** The main page roster: one participant per user list item, resolved reactively. */
export const mainParticipants: ReadonlySignal<readonly ParticipantItem[]> =
  computedSignal(() =>
    mainUserItems.get().map((item) => {
      const ref = item.slots[USER_SLOT] as EntityRef | undefined
      const name = ref ? (Users.signal(ref).get()?.name ?? '') : ''
      const connection = recordSlot(item.slots[CONNECTION_SLOT])

      return {
        key: item.itemKey,
        name,
        presence: toPresence(connection?.['presence']),
        photo: photoOf(item.slots[PHOTO_SLOT]),
        merged: recordSlot(item.slots[MERGE_SLOT]) !== undefined,
      }
    }),
  )

/**
 * The photo of every person in the chat by their id (the `mainUsers` item key),
 * for the circles of the event stream. The roster holds everyone a message can
 * name: an erased person's messages are erased with them, and a merged account's
 * messages belong to the survivor.
 */
const userPhotos: ReadonlySignal<ReadonlyMap<string, string | null>> =
  computedSignal(
    () =>
      new Map(
        mainUserItems
          .get()
          .map((item) => [item.itemKey, photoOf(item.slots[PHOTO_SLOT])]),
      ),
  )

const mainBotItems = scopes.pageListSignal(MAIN_BOTS_LIST)

/** The main page bot list: one bot row per item, entity by reference, status inline. */
export const mainBots: ReadonlySignal<readonly BotItem[]> = computedSignal(() =>
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

const mainEventItems = scopes.pageListSignal(MAIN_EVENTS_LIST)

/**
 * Build a service notice line for a non-message event, mirroring the backend
 * event taxonomy; empty for a `message_sent` event, whose body renders instead.
 *
 * @param type The event type value.
 * @param rename The journal row the rename event shows, read for the old/new names.
 */
function serviceDescription(type: string, rename: UserRename | null): string {
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

/** The acting party of an event line, as {@link eventAuthor} resolves it. */
interface EventAuthor {
  /** The user or bot's name, or empty for a chat-lifecycle notice. */
  readonly name: string
  /** The message author's key — `user:<id>` or `bot:<id>` — or null for a notice. */
  readonly key: string | null
  /** The human message author's photo, or null for a bot, a notice or a person without one. */
  readonly photo: string | null
}

/**
 * Resolve the acting party for an event: the message author (user or bot),
 * else the registered user, or the renamed one of a rename notice. Only a
 * message author carries a key, which tells one author's series of messages
 * from the next.
 *
 * @param message The typed message detail, or null.
 * @param registration The typed registration detail, or null.
 * @param rename The journal row the rename event shows, or null.
 * @param photos The photo of every person in the chat by their id.
 */
function eventAuthor(
  message: EventMessage | null,
  registration: EventUserRegistration | null,
  rename: UserRename | null,
  photos: ReadonlyMap<string, string | null>,
): EventAuthor {
  if (message?.authorBotId != null) {
    return {
      name: Bots.signal(message.authorBotId).get()?.name ?? '',
      key: `bot:${message.authorBotId}`,
      photo: null,
    }
  }
  if (message?.authorUserId != null) {
    return {
      name: Users.signal(message.authorUserId).get()?.name ?? '',
      key: `user:${message.authorUserId}`,
      photo: photos.get(String(message.authorUserId)) ?? null,
    }
  }
  const targetUserId = registration?.targetUserId ?? rename?.userId
  if (targetUserId != null) {
    return {
      name: Users.signal(targetUserId).get()?.name ?? '',
      key: null,
      photo: null,
    }
  }

  return { name: '', key: null, photo: null }
}

/**
 * The main page event stream: each list item resolved into a renderable line.
 * The walk keeps the previous line's author key, so a message opens a series
 * when the line before it is another author's or a notice; time never breaks
 * a series.
 */
export const mainEvents: ReadonlySignal<readonly EventItem[]> = computedSignal(
  () => {
    const photos = userPhotos.get()
    let previousAuthorKey: string | null = null

    return mainEventItems.get().map((item) => {
      const eventRef = item.slots[EVENT_SLOT] as EntityRef | undefined
      const event = eventRef ? Events.signal(eventRef).get() : undefined
      const type = event?.type ?? ''
      const message = eventMessageFrom(recordSlot(item.slots[MESSAGE_SLOT]))
      const registration = eventRegistrationFrom(
        recordSlot(item.slots[REGISTRATION_SLOT]),
      )
      const rename = userRenameFrom(recordSlot(item.slots[RENAME_SLOT]))
      const isMessage = type === EVENT_MESSAGE_SENT
      const author = eventAuthor(message, registration, rename, photos)
      const authorKey = isMessage ? author.key : null
      const seriesHead = isMessage && authorKey !== previousAuthorKey
      previousAuthorKey = authorKey
      const attachmentRefs = item.slots[ATTACHMENT_SLOT]
      const attachments: EventAttachmentItem[] = Array.isArray(attachmentRefs)
        ? attachmentRefs.map((ref) => {
            const attachmentRef = ref as EntityRef
            const attachment = EventAttachments.signal(attachmentRef).get()
            const id = attachment?.id ?? attachmentRef.id

            // Both addresses come from the server, which serves the files from
            // /_hilos/file and alone can sign a thumbnail's address (HIL-144).
            return {
              key: String(id),
              filename: attachment?.filename ?? '',
              mimeType: attachment?.mimeType ?? '',
              url: attachment?.url ?? '',
              thumbUrl: attachment?.thumbUrl ?? '',
            }
          })
        : []

      return {
        key: item.itemKey,
        type,
        timestamp: event?.timestamp ?? '',
        authorName: author.name,
        authorPhoto: author.photo,
        seriesHead,
        text: isMessage ? (message?.message ?? '') : '',
        description: serviceDescription(type, rename),
        attachments,
      }
    })
  },
)

const selfConnectionData = scopes.pageDataSignal(SELF_CONNECTION_DATA)

/**
 * This connection's own composer state — the rate-limit countdown and the
 * outbound moderation phase — projected from the page's `selfConnection` data
 * slot; undefined until the first selfConnection payload lands.
 */
export const selfConnection: ReadonlySignal<SelfConnection | undefined> =
  computedSignal(() => toSelfConnection(selfConnectionData.get()))
