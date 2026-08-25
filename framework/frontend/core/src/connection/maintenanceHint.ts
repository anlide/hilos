// What a browser remembers about the freeze it met last time, and why it is
// remembered at all: the maintenance screen is announced by the welcome frame,
// which arrives after the shell has already painted, so a tab reloaded into a
// frozen node flashes the ordinary application for one frame. This hint is what
// lets the connection hold that frame back — it says "this node was doing
// maintenance", never "it is doing maintenance now".
//
// localStorage rather than the sessionStorage the verifier's pass lives in
// (protectedModePass.ts), and for the opposite reason: an admission must die
// with the tab, while a hint is worth nothing unless it outlives it — the flash
// being fixed happens on a fresh load.

/** localStorage key the maintenance hint is kept under. */
export const PROTECTED_MODE_HINT_STORAGE_KEY = 'hilos.protectedMode.hint'

/**
 * Whether this browser last saw maintenance running on this node.
 *
 * Absent or unreadable storage is not an error and reads as no hint: the core
 * also runs where there is no browser at all (prerender), and a hint nobody can
 * read only costs the flash it was meant to prevent.
 */
export function readProtectedModeHint(): boolean {
  const storage = hintStorage()
  if (storage === undefined) {
    return false
  }

  try {
    return storage.getItem(PROTECTED_MODE_HINT_STORAGE_KEY) !== null
  } catch {
    // Storage can be present and still refuse to be read (a locked-down browser
    // profile). Behaving as if there were no hint is the pre-HIL-613 behavior,
    // which is a flash rather than a fault.
    return false
  }
}

/**
 * Record what the last frame said about maintenance on this node.
 *
 * Written from fact on every frame that carries the state, so the hint cannot
 * outlive the freeze by more than the tab that watched it end. It can still go
 * stale the other way — the freeze lifting while no tab was open — and that is
 * deliberate: a stale hint degrades into a short wait on the next load, never
 * into a maintenance screen the server did not ask for.
 *
 * @param suspected Whether maintenance is running on the node, per that frame.
 */
export function writeProtectedModeHint(suspected: boolean): void {
  const storage = hintStorage()
  if (storage === undefined) {
    return
  }

  try {
    if (suspected) {
      storage.setItem(PROTECTED_MODE_HINT_STORAGE_KEY, '1')

      return
    }
    storage.removeItem(PROTECTED_MODE_HINT_STORAGE_KEY)
  } catch {
    // Same reasoning as above: the hint is nowhere a source of truth, so failing
    // to keep it costs one flash and nothing else.
  }
}

/** The browser's local storage, or undefined where there is none to speak of. */
function hintStorage(): Storage | undefined {
  return typeof globalThis.localStorage === 'undefined'
    ? undefined
    : globalThis.localStorage
}
