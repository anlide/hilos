// The framework's collected browser declarations and the sweep that erases them —
// the headless half of the erase block on /privacy. No DOM framework and no view,
// so the three SDKs draw the same list rather than each assembling its own
// (multiframework-core.md).
//
// This file imports the declaring modules; they import only browserValue.ts. That
// is the whole reason the declaring form lives in a file of its own: the other
// direction would be a cycle.
//
// NOTHING HERE READS THE BROWSER AT MODULE LOAD. /privacy is prerendered, so this
// module is evaluated at build time with no browser present; a declaration is data
// and the sweep is a click handler (protectedModePass.ts says the same constraint
// in its own words).
import { OAUTH_PROVIDER_BROWSER_VALUE } from '../auth/oauthLogin.js'
import { SESSION_ROTATE_BROWSER_VALUE } from '../connection/createHilosConnection.js'
import { PROTECTED_MODE_HINT_BROWSER_VALUE } from '../connection/maintenanceHint.js'
import { PROTECTED_MODE_PASS_BROWSER_VALUE } from '../connection/protectedModePass.js'
import {
  type HilosBrowserValue,
  type HilosBrowserValueContext,
} from './browserValue.js'

/**
 * Every value the framework itself puts in the browser, in declaration order.
 *
 * A project's own entries are not here and are not collected: the project hands
 * them to the page as an input, and the sweep runs over both lists together.
 */
export const HILOS_BROWSER_VALUES: readonly HilosBrowserValue[] = [
  OAUTH_PROVIDER_BROWSER_VALUE,
  PROTECTED_MODE_PASS_BROWSER_VALUE,
  PROTECTED_MODE_HINT_BROWSER_VALUE,
  SESSION_ROTATE_BROWSER_VALUE,
]

/**
 * Client-to-server action ending this browser's session and minting a replacement
 * (PHP `HilosSignalConstants::HILOS_BROWSER_ERASE`).
 *
 * The server half of the erase, tracked and payload-free: the session it ends is
 * read from the acting connection, so a client can only ever erase its own. It is
 * dispatched after the sweep below has run, never before — the rotation ticket the
 * reply brings rides a cookie that is itself a declared value here.
 */
export const BROWSER_ERASE_ACTION = 'hilos_browser_erase'

/** `Path=/`, the path every cookie this framework writes uses. */
const COOKIE_ERASE_ATTRIBUTES = '; Max-Age=0; Path=/'

/**
 * Erase every declared value this browser can be made to let go of.
 *
 * Synchronous and unable to fail: a storage that is absent or refuses to be
 * written is not an error, exactly as the two readers of these keys already treat
 * it — a locked-down profile must not turn an erase into a failure. It is simply
 * not reported as swept, because it was not.
 *
 * @param values The framework's declarations plus whatever the project handed in.
 * @param context What a key the deployment names is built from.
 * @returns The labels actually swept, in declaration order.
 */
export function eraseBrowserValues(
  values: readonly HilosBrowserValue[],
  context: HilosBrowserValueContext,
): string[] {
  const swept: string[] = []
  for (const value of values) {
    const key = typeof value.key === 'function' ? value.key(context) : value.key
    // A key the deployment has not named yet names nothing to erase.
    if (key === undefined) {
      continue
    }
    if (eraseBrowserValue(value.store, key)) {
      swept.push(value.label)
    }
  }

  return swept
}

/**
 * Remove one key from the store that holds it.
 *
 * @param store Which of the browser's three stores the value lives in.
 * @param key The key to remove.
 * @returns Whether the removal reached the store.
 */
function eraseBrowserValue(
  store: HilosBrowserValue['store'],
  key: string,
): boolean {
  if (store === 'cookie') {
    return eraseCookie(key)
  }

  const storage =
    store === 'session'
      ? browserStorage('sessionStorage')
      : browserStorage('localStorage')
  if (storage === undefined) {
    return false
  }

  try {
    storage.removeItem(key)

    return true
  } catch {
    // Storage can be present and still refuse (a locked-down browser profile).
    return false
  }
}

/**
 * Expire one cookie of this document.
 *
 * A cookie is deleted by being written back with no lifetime left, so this is a
 * write to `document.cookie` like any other — and the reason this file is named in
 * the BROWSER-VALUE-DECLARED guard's exclusions: it erases declarations, it does
 * not make any.
 *
 * @param name The cookie name to expire.
 * @returns Whether the write reached the document.
 */
function eraseCookie(name: string): boolean {
  if (typeof globalThis.document === 'undefined') {
    return false
  }

  try {
    globalThis.document.cookie = `${name}=${COOKIE_ERASE_ATTRIBUTES}`

    return true
  } catch {
    // Same reasoning as storage above: an erase that cannot reach one value is
    // still an erase of the rest.
    return false
  }
}

/**
 * The named store, or undefined where there is no browser to speak of.
 *
 * @param name Which global store to reach for.
 * @returns The store, or undefined when this runtime has none.
 */
function browserStorage(
  name: 'sessionStorage' | 'localStorage',
): Storage | undefined {
  return typeof globalThis[name] === 'undefined' ? undefined : globalThis[name]
}
