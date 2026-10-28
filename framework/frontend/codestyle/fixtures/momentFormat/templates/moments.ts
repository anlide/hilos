// What the component beside imports: a moment under a name of its own, and the
// typed helper a template calls to print a count. Only the program built with
// this folder's tsconfig resolves them.

/** A moment under a name of its own. */
export type Stamp = Date

/**
 * @param count A number of things
 * @returns It as text: typed here, so its toLocaleString() is proven numeric
 */
export function formatCount(count: number): string {
  return count.toLocaleString()
}
