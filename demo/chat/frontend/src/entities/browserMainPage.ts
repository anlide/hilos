import type { BrowserPageRow } from '@hilos/sdk/types'
import { ChatBot, Event } from '@/types'
import {
  eventPayloadToEvent,
  isBotPayload,
  isEventMessagePayload,
  isEventPayload,
  isEventUserRegistrationPayload,
  isEventUserRenamePayload,
  parseEventAttachmentPayloads,
  type EventPayload,
} from '@/entities/parsers'
import {
  isAttachmentDraftPayload,
  parseSelfConnection,
  type AttachmentDraftPayload,
  type SelfConnectionPayload,
} from '@/entities/browserPayloadParsers'
import { type Presence } from '@/types/domain/Presence'
import {
  userListRowsFromBrowserRows,
  type BrowserUserListRow,
} from '@/entities/browserUserDetail'

export const BROWSER_PAGE_MAIN = 'subscription_page_main'
export const BROWSER_TABLE_MAIN_EVENTS = 'mainEvents'
export const BROWSER_TABLE_MAIN_USERS = 'mainUsers'
export const BROWSER_TABLE_MAIN_BOTS = 'mainBots'
export const BROWSER_TABLE_SELF_CONNECTION = 'selfConnection'
export const BROWSER_TABLE_ATTACHMENT_DRAFTS = 'attachmentDrafts'

const BROWSER_SOURCE_EVENTS = 'events'
const BROWSER_SOURCE_EVENT_MESSAGES = 'eventMessages'
const BROWSER_SOURCE_EVENT_USER_REGISTRATIONS = 'eventUserRegistrations'
const BROWSER_SOURCE_EVENT_USER_RENAMES = 'eventUserRenames'
const BROWSER_SOURCE_EVENT_ATTACHMENTS = 'eventAttachments'
const BROWSER_SOURCE_BOTS = 'bots'
const BROWSER_SOURCE_BOT_AGENT_STATUSES = 'botAgentStatuses'
const BROWSER_SOURCE_CONNECTIONS = 'connections'
const BROWSER_SOURCE_USER_STATES = 'userStates'
const BROWSER_SOURCE_ATTACHMENT_DRAFTS = 'attachmentDrafts'

export type BrowserMainUser = BrowserUserListRow
export type BrowserMainBot = ChatBot & { presence: Presence }

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const rows = (rowsByKey: Record<string, BrowserPageRow> | undefined): BrowserPageRow[] => {
  return rowsByKey === undefined ? [] : Object.values(rowsByKey)
}

const mainEventFromBrowserRow = (row: BrowserPageRow): Event | null => {
  const event = row.sources[BROWSER_SOURCE_EVENTS]
  if (!isRecord(event)) {
    return null
  }

  const message = row.sources[BROWSER_SOURCE_EVENT_MESSAGES]
  const registration = row.sources[BROWSER_SOURCE_EVENT_USER_REGISTRATIONS]
  const rename = row.sources[BROWSER_SOURCE_EVENT_USER_RENAMES]
  const attachments = parseEventAttachmentPayloads(row.sources[BROWSER_SOURCE_EVENT_ATTACHMENTS])
  if (attachments === null) {
    return null
  }
  if (message !== undefined && !isEventMessagePayload(message)) {
    return null
  }
  if (registration !== undefined && !isEventUserRegistrationPayload(registration)) {
    return null
  }
  if (rename !== undefined && !isEventUserRenamePayload(rename)) {
    return null
  }

  const payload: EventPayload = {
    id: event.id as number,
    type: event.type as string,
    timestamp: event.timestamp as number | string,
    eventMessage: message === undefined ? null : message,
    eventUserRegistration: registration === undefined ? null : registration,
    eventUserRename: rename === undefined ? null : rename,
    attachments,
  }

  return isEventPayload(payload) ? eventPayloadToEvent(payload) : null
}

export const mainEventsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): Event[] => {
  return rows(rowsByKey)
    .map(mainEventFromBrowserRow)
    .filter((event): event is Event => event !== null)
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
}

export const mainUsersFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): BrowserMainUser[] => {
  return userListRowsFromBrowserRows(rowsByKey)
}

const botPresenceFromStatus = (status: unknown): Presence => {
  return status === 'joined' ? 'online' : 'offline'
}

const mainBotFromBrowserRow = (row: BrowserPageRow): BrowserMainBot | null => {
  const bot = row.sources[BROWSER_SOURCE_BOTS]
  if (!isBotPayload(bot)) {
    return null
  }

  const status = row.sources[BROWSER_SOURCE_BOT_AGENT_STATUSES]
  return Object.assign(ChatBot.fromObject(bot), {
    presence: isRecord(status) ? botPresenceFromStatus(status.status) : 'offline',
  })
}

export const mainBotsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): BrowserMainBot[] => {
  return rows(rowsByKey)
    .map(mainBotFromBrowserRow)
    .filter((bot): bot is BrowserMainBot => bot !== null)
    .sort((a, b) => a.id - b.id)
}

export const selfConnectionFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): SelfConnectionPayload | null => {
  for (const row of rows(rowsByKey)) {
    const connection = row.sources[BROWSER_SOURCE_CONNECTIONS]
    if (!isRecord(connection)) {
      continue
    }

    const userState = row.sources[BROWSER_SOURCE_USER_STATES]
    const payload = parseSelfConnection({
      ...connection,
      ...(isRecord(userState) ? userState : {}),
    })
    if (payload !== null) {
      return payload
    }
  }

  return null
}

export const attachmentDraftsFromBrowserRows = (
  rowsByKey: Record<string, BrowserPageRow> | undefined,
): AttachmentDraftPayload[] => {
  return rows(rowsByKey)
    .map((row) => row.sources[BROWSER_SOURCE_ATTACHMENT_DRAFTS])
    .filter(isAttachmentDraftPayload)
    .sort((a, b) => a.uploadedAt - b.uploadedAt || a.draftId.localeCompare(b.draftId))
}
