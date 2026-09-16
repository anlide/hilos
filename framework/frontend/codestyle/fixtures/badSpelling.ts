// Deliberately broken sample: every British form below is one of the six words of
// spelling.md, standing where a TypeScript file writes English — a TSDoc block, a
// line comment, a string literal, a constant name behind an underscore, a variable
// behind a camelCase hump, and two word forms whose tail the matcher takes in.
// SPELLING must report each one at its own line, and a line carrying the word
// twice yields two records.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** The colour of the badge — a TSDoc hit. */
export const BADGE_COLOUR = 'red'

/** Two hits on one line: the constant name and the literal it holds. */
export const PASSKEY_CANCELLED_MESSAGE = 'The passkey request was cancelled.'

/**
 * @param behaviour A parameter named in the British form
 * @returns The licence text, organised for the screen
 */
export function serialiseBehaviour(behaviour: string): string {
  // A behavioural note in a line comment, with the tail taken in.
  const localColour = `${behaviour} serialises to ${BADGE_COLOUR}`

  return localColour
}
