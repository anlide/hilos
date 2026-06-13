// The chat page payloads' entity-slot types: which payload slots (here, inside
// list items) are entities and under what canonical type. Frontend config, not
// emitted on the wire — keep it in sync with the backend browser sources
// (rules-and-violations.md). A wire slot key is its backend collection name
// (`ChatDbContext::*`); the canonical type makes a row dedupe against the same
// entity wherever it appears — the `bot` type lets the event stream resolve a
// message author against the bot the `mainBots` list delivered. The binder
// (`bindPageScope`) applies this to every page payload's slots.
import {
  BOT_TYPE,
  EVENT_ATTACHMENT_TYPE,
  EVENT_TYPE,
  USER_TYPE,
} from '../types'

/** Per-slot canonical entity types for the chat's page payloads. */
export const pageEntityTypes: Record<string, string> = {
  users: USER_TYPE,
  bots: BOT_TYPE,
  events: EVENT_TYPE,
  eventAttachments: EVENT_ATTACHMENT_TYPE,
}
