// A bot of the main page bot list — the view-model of one `mainBots` list item:
// the referenced bot's resolved name and description plus the inline agent
// runtime status. A view-model, not an entity; the page bot-list selector builds
// it from the list item's slots.

/** One bot of the main page bot list. */
export interface BotItem {
  /** The list item key (the bot id), stable for keyed rendering. */
  readonly key: string
  /** The bot's display name, resolved from the referenced entity. */
  readonly name: string
  /** The bot's description, resolved from the referenced entity. */
  readonly description: string
  /** The bot agent's runtime status, or empty when the agent has not reported. */
  readonly status: string
}
