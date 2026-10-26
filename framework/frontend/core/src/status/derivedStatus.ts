import { formatDurationShort } from '../format/duration.js'
import { formatMoment } from '../format/moment.js'
import { type CalendarDay } from '../timeZone/calendarPeriods.js'
import {
  type PublicStatusComponent,
  type PublicStatusFile,
  type PublicStatusMaintenancePeriod,
  type PublicStatusOutage,
  type PublicStatusState,
} from './statusFiles.js'

/** Published facts can color a day; an unpublished candidate cannot. */
export type PublicStatusDayState = Exclude<
  PublicStatusState,
  'checking' | 'unknown'
>

/** One calendar day with its worst published fact, independent of freshness. */
export interface PublicStatusDay extends CalendarDay {
  readonly state: PublicStatusDayState
}

/** A component's full strip and its exact narrow-screen suffix. */
export interface PublicStatusDayStrip {
  readonly componentKey: string
  readonly days: readonly PublicStatusDay[]
  readonly narrowDays: readonly PublicStatusDay[]
}

/** A published outage with reader-zone labels and detection-based duration. */
export interface PublicStatusOutageView extends PublicStatusOutage {
  readonly detectedAtLabel: string | null
  readonly resolvedAtLabel: string | null
  readonly durationSeconds: number
  readonly durationLabel: string
}

/** Completed actual maintenance, without treating an announced schedule as a fact. */
export interface PublicStatusMaintenanceView extends PublicStatusMaintenancePeriod {
  readonly startedAtLabel: string | null
  readonly endedAtLabel: string | null
  readonly durationSeconds: number
  readonly durationLabel: string
}

/** Published, completed facts only; candidates never enter the archive. */
export type PublicStatusHistoryEntry =
  | (PublicStatusOutageView & { readonly kind: 'outage' })
  | (PublicStatusMaintenanceView & { readonly kind: 'maintenance' })

/** Primary banner; the socket explanation is a separate, independent signal. */
export interface PublicStatusBanner {
  readonly kind: PublicStatusState | 'not_responding'
  readonly title: string
  readonly since: number | null
}

const DAY_PRIORITY: Readonly<Record<PublicStatusDayState, number>> = {
  operational: 0,
  maintenance: 1,
  partial_outage: 2,
  major_outage: 3,
}

// TODO(i18n): public file-reader copy, independent of server refusal sentences.
const BANNER_TITLES: Readonly<Record<PublicStatusState, string>> = {
  operational: 'All systems operational',
  checking: 'Checking a possible problem',
  partial_outage: 'Partial outage',
  major_outage: 'Major outage',
  maintenance: 'Maintenance in progress',
  unknown: 'Status unknown',
}

/** Current facts take priority; Operational requires every component to confirm it. */
export function publicStatusOverall(
  components: readonly PublicStatusComponent[],
  status: PublicStatusFile | null,
): PublicStatusState {
  const states = new Set(components.map((component) => component.state))
  for (const outage of status?.outages ?? []) {
    if (outage.resolvedAt === null) states.add(outage.severity)
  }
  if (status?.maintenancePeriods.some((period) => period.endedAt === null)) {
    states.add('maintenance')
  }
  for (const state of [
    'maintenance',
    'major_outage',
    'partial_outage',
    'checking',
  ] as const) {
    if (states.has(state)) return state
  }

  return components.length > 0 &&
    components.every((component) => component.state === 'operational')
    ? 'operational'
    : 'unknown'
}

/** Preserve since as an absolute moment, using the shared reader-zone formatter. */
export function publicStatusBanner(
  overall: PublicStatusState,
  staleSince: number | null,
): PublicStatusBanner {
  if (staleSince !== null) {
    const label = formatMoment(staleSince)

    return {
      kind: 'not_responding',
      title:
        label === null
          ? 'The system is not responding'
          : `The system is not responding since ${label}`,
      since: staleSince,
    }
  }

  return { kind: overall, title: BANNER_TITLES[overall], since: null }
}

/** Stable descending detection order; opaque ids are compared only as strings. */
export function publicStatusOutageViews(
  outages: readonly PublicStatusOutage[],
  now: number,
): readonly PublicStatusOutageView[] {
  return [...outages]
    .sort(
      (left, right) =>
        right.detectedAt - left.detectedAt ||
        (left.id < right.id ? -1 : left.id > right.id ? 1 : 0),
    )
    .map((outage) => {
      const durationSeconds = Math.max(
        0,
        Math.floor(((outage.resolvedAt ?? now) - outage.detectedAt) / 1000),
      )

      return {
        ...outage,
        // Stable sort retains the publisher's tie-break after its private id
        // has been removed. Team text stays plain text, without HTML parsing.
        updates: [...outage.updates].sort((left, right) => right.at - left.at),
        detectedAtLabel: formatMoment(outage.detectedAt),
        resolvedAtLabel:
          outage.resolvedAt === null ? null : formatMoment(outage.resolvedAt),
        durationSeconds,
        durationLabel: formatDurationShort(durationSeconds),
      }
    })
}

/** Completed published intervals overlapping the visible calendar window. */
export function publicStatusHistory(
  status: PublicStatusFile | null,
  days: readonly CalendarDay[],
): readonly PublicStatusHistoryEntry[] {
  const first = days[0]
  const last = days.at(-1)
  if (status === null || first === undefined || last === undefined) return []
  const window = { startTs: first.startTs, endTs: last.endTs }
  const entries: PublicStatusHistoryEntry[] = publicStatusOutageViews(
    status.outages.filter(
      (outage) =>
        outage.resolvedAt !== null &&
        intersects(window, outage.detectedAt, outage.resolvedAt),
    ),
    last.endTs,
  ).map((outage) => ({ ...outage, kind: 'outage' }))
  for (const period of status.maintenancePeriods) {
    if (
      period.endedAt === null ||
      !intersects(window, period.startedAt, period.endedAt)
    )
      continue
    const durationSeconds = Math.max(
      0,
      Math.floor((period.endedAt - period.startedAt) / 1000),
    )
    entries.push({
      ...period,
      kind: 'maintenance',
      startedAtLabel: formatMoment(period.startedAt),
      endedAtLabel: formatMoment(period.endedAt),
      durationSeconds,
      durationLabel: formatDurationShort(durationSeconds),
    })
  }

  return entries.sort((left, right) => {
    const leftStart = left.kind === 'outage' ? left.detectedAt : left.startedAt
    const rightStart =
      right.kind === 'outage' ? right.detectedAt : right.startedAt

    return (
      rightStart - leftStart ||
      (left.id < right.id ? -1 : left.id > right.id ? 1 : 0) ||
      (left.kind < right.kind ? -1 : left.kind > right.kind ? 1 : 0)
    )
  })
}

/** Half-open intervals: resolving at midnight does not color the following day. */
function intersects(day: CalendarDay, start: number, end: number): boolean {
  return end > start && start < day.endTs && end > day.startTs
}

/** Paint only actual published intervals, keeping catalog order and 30 as a suffix. */
export function publicStatusDayStrips(
  status: PublicStatusFile,
  days: readonly CalendarDay[],
  now: number,
): readonly PublicStatusDayStrip[] {
  return status.components.map((component) => {
    const outages = status.outages.filter(
      (outage) => outage.componentKey === component.componentKey,
    )
    const maintenance = status.maintenancePeriods.filter((period) =>
      period.componentKeys.includes(component.componentKey),
    )
    const painted = days.map((day): PublicStatusDay => {
      let state: PublicStatusDayState = 'operational'
      for (const period of maintenance) {
        if (intersects(day, period.startedAt, period.endedAt ?? now)) {
          state = 'maintenance'
        }
      }
      for (const outage of outages) {
        if (
          intersects(day, outage.detectedAt, outage.resolvedAt ?? now) &&
          DAY_PRIORITY[outage.severity] > DAY_PRIORITY[state]
        ) {
          state = outage.severity
        }
      }

      return { ...day, state }
    })

    return {
      componentKey: component.componentKey,
      days: painted,
      narrowDays: painted.slice(-30),
    }
  })
}
