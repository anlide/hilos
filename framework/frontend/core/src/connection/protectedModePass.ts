// Where a verifier's protected-mode pass is kept between sockets, and why it is
// kept there at all. The admission itself is no longer this tab's: the server
// records the browser session behind the connection, so a second tab and a
// reloaded one are let in on the cookie alone. What the stored key is for is the
// moment that cookie changes — signing in under the verification window rotates
// the session token, drops every connection of the browser and voids the
// admission with the hash it was written under. A tab that still remembers the
// key presents it on the reconnect and buys the admission back for the whole
// browser, because the admission is a browser's now.
//
// sessionStorage rather than a cookie for the lifetime: one tab, gone when it
// closes, whereas a cookie is domain-wide and would outlive the freeze it
// belongs to.

/** sessionStorage key the presented pass is mirrored under. */
export const PROTECTED_MODE_PASS_STORAGE_KEY = 'hilos.protectedMode.pass'

/**
 * Read the pass this tab has presented, if any.
 *
 * Absent storage is not an error: the core also runs where there is no browser at
 * all (prerender), and a connection with no memory of a pass simply has none.
 */
export function readStoredProtectedModePass(): string | undefined {
  const storage = passStorage()
  if (storage === undefined) {
    return undefined
  }

  try {
    return storage.getItem(PROTECTED_MODE_PASS_STORAGE_KEY) ?? undefined
  } catch {
    // Storage can be present and still refuse to be read (a locked-down browser
    // profile). A verifier who has to retype the key after a reload is a smaller
    // loss than a connection that cannot open.
    return undefined
  }
}

/**
 * Remember the pass this tab has presented, or forget it when given none.
 *
 * @param pass The pass to keep, or undefined to drop the stored one.
 */
export function writeStoredProtectedModePass(pass: string | undefined): void {
  const storage = passStorage()
  if (storage === undefined) {
    return
  }

  try {
    if (pass === undefined) {
      storage.removeItem(PROTECTED_MODE_PASS_STORAGE_KEY)

      return
    }
    storage.setItem(PROTECTED_MODE_PASS_STORAGE_KEY, pass)
  } catch {
    // Same reasoning as above: the key still works on this socket, it just will
    // not survive a reload.
  }
}

/** The tab's session storage, or undefined where there is none to speak of. */
function passStorage(): Storage | undefined {
  return typeof globalThis.sessionStorage === 'undefined'
    ? undefined
    : globalThis.sessionStorage
}
