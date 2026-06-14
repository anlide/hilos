// The main chat page selectors: the page payload's lists resolved into the
// list-item view-models the page renders. Those view-models live alongside this
// module in `./types/lists` (an `…Item` per list row); this file only builds the
// signals that resolve them, reading the domain entities (`src/types`) by
// reference so a rename or a presence flip updates reactively without the list
// re-streaming. The view reads these signals and never touches a raw store.
import {
  computedSignal,
  readNumber,
  readString,
  type EntityRef,
  type ReadonlySignal,
} from '@hilos/core'

import { scopes } from '../../bootstrap/session'
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
} from '../../types'
import { toSelfConnection, type SelfConnection } from './types/SelfConnection'
import { type AttachmentDraftItem } from './types/lists/AttachmentDraftItem'
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
// The composer's pending uploads list (backend ChatBrowserTable::ATTACHMENT_DRAFTS).
const ATTACHMENT_DRAFTS_LIST = 'attachmentDrafts'
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
const RENAME_SLOT = 'eventUserRenames'
const ATTACHMENT_SLOT = 'eventAttachments'
// The per-row field slot of a draft (backend source ChatRtContext::attachmentDrafts);
// its value coincides with the list key, but a list item nests its fields under
// the source-collection slot, never at the item root.
const ATTACHMENT_DRAFT_SLOT = 'attachmentDrafts'

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
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

// The same-origin attachment endpoint: the session cookie authorizes each GET,
// so the URL carries only the attachment id. nginx (test/prod) and the Vite dev
// proxy both forward it to the daemon's HTTP router.
const ATTACHMENT_DOWNLOAD_PATH = '/chat/attachment'

/**
 * Build the same-origin download URL for a published attachment.
 *
 * @param id The attachment id.
 */
function attachmentUrl(id: number | string): string {
  return `${ATTACHMENT_DOWNLOAD_PATH}?id=${encodeURIComponent(String(id))}`
}

const mainUserItems = scopes.pageListSignal(MAIN_USERS_LIST)

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
      }
    }),
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
    return {
      name: Bots.signal(message.authorBotId).get()?.name ?? '',
      isBot: true,
    }
  }
  if (message?.authorUserId != null) {
    return {
      name: Users.signal(message.authorUserId).get()?.name ?? '',
      isBot: false,
    }
  }
  const targetUserId = registration?.targetUserId ?? rename?.targetUserId
  if (targetUserId != null) {
    return { name: Users.signal(targetUserId).get()?.name ?? '', isBot: false }
  }

  return { name: '', isBot: false }
}

/** The main page event stream: each list item resolved into a renderable line. */
export const mainEvents: ReadonlySignal<readonly EventItem[]> = computedSignal(
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
      const attachments: EventAttachmentItem[] = Array.isArray(attachmentRefs)
        ? attachmentRefs.map((ref) => {
            const attachmentRef = ref as EntityRef
            const attachment = EventAttachments.signal(attachmentRef).get()
            const id = attachment?.id ?? attachmentRef.id

            return {
              key: String(id),
              filename: attachment?.filename ?? '',
              mimeType: attachment?.mimeType ?? '',
              url: attachmentUrl(id),
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

const selfConnectionData = scopes.pageDataSignal(SELF_CONNECTION_DATA)

/**
 * This connection's own composer state — the rate-limit countdown and the
 * outbound moderation phase — projected from the page's `selfConnection` data
 * slot; undefined until the first selfConnection payload lands.
 */
export const selfConnection: ReadonlySignal<SelfConnection | undefined> =
  computedSignal(() => toSelfConnection(selfConnectionData.get()))

const attachmentDraftItems = scopes.pageListSignal(ATTACHMENT_DRAFTS_LIST)

/** The composer's pending attachment drafts — one removable chip per uploaded-but-unsent file. */
export const attachmentDrafts: ReadonlySignal<readonly AttachmentDraftItem[]> =
  computedSignal(() =>
    attachmentDraftItems.get().map((item) => {
      const draft = recordSlot(item.slots[ATTACHMENT_DRAFT_SLOT])

      return {
        draftId: item.itemKey,
        filename: draft ? readString(draft, 'filename') : '',
        mimeType: draft ? readString(draft, 'mimeType') : '',
        size: draft ? readNumber(draft, 'size') : 0,
      }
    }),
  )
