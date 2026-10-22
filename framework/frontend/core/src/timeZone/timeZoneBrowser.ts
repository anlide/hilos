import { usableTimeZone } from './timeZoneRule.js'

/**
 * The IANA zone this browser runs in, or null when it cannot say.
 *
 * The one source is the engine's own resolved zone. A clock offset is not a
 * zone name, and nothing is remembered between reads: the system zone may
 * change while the page lives, so each call asks again. No `Intl`, no name, a
 * name of an unexpected type or an unusable one, or a throw reads as null.
 */
export function readBrowserTimeZone(): string | null {
  try {
    return usableTimeZone(new Intl.DateTimeFormat().resolvedOptions().timeZone)
  } catch {
    return null
  }
}
