// User presence — a small value type, not an entity. It rides the main page's
// user list inline `connections` slot; kept a string union (not a boolean) so a
// future third state (e.g. away) extends it without a migration.

/** A user's connection presence. */
export type Presence = 'online' | 'offline'

const PRESENCE_VALUES: readonly Presence[] = ['online', 'offline']

/**
 * Narrow an unknown value to a Presence, defaulting to `offline`.
 *
 * @param value The raw presence value from a payload slot.
 */
export function toPresence(value: unknown): Presence {
  return PRESENCE_VALUES.includes(value as Presence)
    ? (value as Presence)
    : 'offline'
}
