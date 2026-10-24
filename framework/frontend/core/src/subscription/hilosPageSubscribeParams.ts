import { HilosPages } from '../routing/hilosPages.js'
import { offsetMs } from '../session/serverClock.js'
import { calendarPeriodStarts } from '../timeZone/calendarPeriods.js'
import { hilosTimeZone } from '../timeZone/timeZoneState.js'

/**
 * Prepare framework page context after the session's zone and clock have landed.
 * The subscription owns this opening's context; no observer refreshes it.
 *
 * @param pageKey The page being opened.
 * @param params The navigation's original params.
 */
export function prepareHilosPageSubscribeParams(
  pageKey: string,
  params: Readonly<Record<string, unknown>>,
): Record<string, unknown> {
  if (pageKey !== HilosPages.ANALYTICS) return params
  const timeZone = hilosTimeZone.get()
  const starts = calendarPeriodStarts(Date.now() + offsetMs(), timeZone)

  return {
    ...params,
    timeZone,
    weekStartTs: String(starts.weekStartTs),
    dayStartTs: String(starts.dayStartTs),
    hourStartTs: String(starts.hourStartTs),
  }
}
