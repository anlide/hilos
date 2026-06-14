// The bot detail page view-model: the referenced bot's profile resolved for
// display plus the agent's derived online state. A view-model, not an entity;
// the bot detail selector builds it from the profile entity and the status slot.

/** The chat bot detail shown on the bot page. */
export interface BotDetail {
  /** The bot's display name. */
  readonly name: string
  /** The bot's description, empty when unset. */
  readonly description: string
  /** The bot's conversational style, empty when unset. */
  readonly style: string
  /** The bot's topics, empty when unset. */
  readonly topics: string
  /** The bot's personality, empty when unset. */
  readonly personality: string
  /** Whether the bot is active (configured to take part in the chat). */
  readonly active: boolean
  /** Whether the bot agent is currently joined (online). */
  readonly online: boolean
}
