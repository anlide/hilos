// The chat bot entity and its collection. A bot is a domain entity reused
// across pages — the main page bot list, the bot detail page, and a message
// author in the event stream all resolve the same `bot` entity by reference.
import {
  entityCollection,
  readBoolean,
  readNumber,
  readString,
  readStringOrNull,
  type Entity,
  type EntityCollection,
} from '@hilos/core'

import { scopes } from '../session'

/** The canonical entity type — keep in sync with the backend `bots` source. */
export const BOT_TYPE = 'bot'

/** A chat bot, projected from the backend bot source. */
export interface Bot extends Entity {
  readonly name: string
  readonly description: string | null
  readonly style: string | null
  readonly topics: string | null
  readonly personality: string | null
  readonly active: boolean
}

/**
 * Project a committed bot's raw fields into the typed entity.
 *
 * @param fields The bot entity's committed fields.
 */
export function botFromFields(fields: Readonly<Record<string, unknown>>): Bot {
  return {
    id: readNumber(fields, 'id'),
    name: readString(fields, 'name'),
    description: readStringOrNull(fields, 'description'),
    style: readStringOrNull(fields, 'style'),
    topics: readStringOrNull(fields, 'topics'),
    personality: readStringOrNull(fields, 'personality'),
    active: readBoolean(fields, 'active'),
  }
}

/** The bot collection: typed reference resolution for the `bot` entity type. */
export const Bots: EntityCollection<Bot> = entityCollection(
  scopes,
  BOT_TYPE,
  botFromFields,
)
