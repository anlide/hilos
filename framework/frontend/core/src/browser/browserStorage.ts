// The one safe way to reach the browser's session and local storage. Kept in a
// file of its own because both sides need it: the modules that declare a stored
// value read and write through it, and browser/browserValues.ts erases through
// it while importing those very modules — living in either would be a cycle.

/**
 * The named store, or undefined where there is none this code may use.
 *
 * The global read itself sits inside the guard, not only the calls on the
 * store: Chrome with every cookie blocked, and a profile that blocks them by
 * policy, throw a SecurityError from the `localStorage` / `sessionStorage`
 * getter, and `typeof` does not catch it — the getter runs before `typeof`
 * sees a value. A store that refuses to be reached reads as no store at all,
 * the same as the prerender, where there is no browser to speak of.
 *
 * @param name Which global store to reach for.
 * @returns The store, or undefined when this runtime has none or refuses it.
 */
export function browserStorage(
  name: 'sessionStorage' | 'localStorage',
): Storage | undefined {
  try {
    return globalThis[name]
  } catch {
    return undefined
  }
}
