/**
 * User presence status.
 * Will be extended with 'suspend' in the future; do not simplify to boolean.
 */
export type Presence = 'online' | 'offline'

/** Allowed values for runtime validation (e.g. payload parsers). */
export const PRESENCE_VALUES: readonly Presence[] = ['online', 'offline']

export function isPresence(value: unknown): value is Presence {
  return typeof value === 'string' && (PRESENCE_VALUES as readonly string[]).includes(value)
}
