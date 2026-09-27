// One rule for the letters in every person's avatar, independent of the view
// framework. Normalize first so a decomposed letter keeps its accent, and match
// Unicode code points so names outside the BMP are not split into surrogates.
// Never throws; a name without letters or numbers leaves the icon to the view.

/**
 * Return the uppercase initials of the first and last words that have a letter
 * or number, or an empty string when no word has one.
 *
 * @param name The person's name, with any whitespace or punctuation it carries.
 */
export function formatInitials(name: string): string {
  const letters = name
    .normalize('NFC')
    .trim()
    .split(/\s+/u)
    .map((word) => word.match(/[\p{L}\p{N}]/u)?.[0] ?? '')
    .filter((letter) => letter !== '')

  if (letters.length === 0) return ''

  return (
    letters[0] + (letters.length > 1 ? letters[letters.length - 1] : '')
  ).toLocaleUpperCase()
}
