import { formatDurationShort } from '../format/duration.js'
import { formatMoment } from '../format/moment.js'
import { type CalendarDay } from '../timeZone/calendarPeriods.js'
import {
  type PublicStatusComponent,
  type PublicStatusFile,
  type PublicStatusMaintenancePeriod,
  type PublicStatusOutage,
  type PublicStatusState,
  type PublicStatusWork,
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

/** Only a proven link or a proven absence of one classifies an announcement. */
export type PublicStatusAnnouncement = 'announced' | 'unannounced' | 'unknown'

/** Public announcement words and planned bounds, formatted in the reader zone. */
export interface PublicStatusWorkView extends PublicStatusWork {
  readonly componentNames: readonly string[]
  readonly startsAtLabel: string | null
  readonly endsAtLabel: string | null
}

/** Actual maintenance; an observed completion never supplies an exact end. */
export interface PublicStatusMaintenanceView extends PublicStatusMaintenancePeriod {
  readonly announcement: PublicStatusAnnouncement
  readonly componentNames: readonly string[]
  /** Absent when the accepted file no longer retains this exact public work id. */
  readonly plannedWork: PublicStatusWorkView | null
  readonly startedAtLabel: string | null
  readonly endedAtLabel: string | null
  readonly completionObservedAtLabel: string | null
  readonly durationSeconds: number | null
  readonly durationLabel: string | null
}

/** An accepted active fact, retained even when current publisher knowledge is lost. */
export interface PublicStatusCurrentMaintenanceView extends PublicStatusMaintenanceView {
  /** False means a retained observation, without proof that it is still current. */
  readonly confirmedCurrent: boolean
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
  if (
    status?.maintenancePeriods.some(
      (period) => period.completionObservedAt === null,
    )
  ) {
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
  currentMaintenance: readonly PublicStatusCurrentMaintenanceView[] = [],
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

  const period = currentMaintenance[0]

  return {
    kind: overall,
    title:
      overall === 'maintenance' &&
      period?.confirmedCurrent &&
      period.announcement === 'announced'
        ? `${BANNER_TITLES.maintenance}: ${period.title}`
        : BANNER_TITLES[overall],
    since: null,
  }
}

/**
 * Keep authoritative, unused announcements until their half-open window ends.
 * A window beginning by the clock is still an announcement, never an actual fact.
 * Equal starts retain canonical publisher order; opaque ids carry no sort number.
 */
export function publicStatusUpcomingWorks(
  status: PublicStatusFile | null,
  now: number,
): readonly PublicStatusWorkView[] {
  if (status === null) return []
  const consumed = new Set(
    status.maintenancePeriods.map((period) => period.workId),
  )

  return status.scheduledWorks
    .filter((work) => work.endsAt > now && !consumed.has(work.id))
    .sort((left, right) => left.startsAt - right.startsAt)
    .map((work) => workView(work, status.components))
}

/**
 * Preserve accepted active periods independently of heartbeat freshness. Only a
 * fresh pair confirms they are current; schedule clocks never complete them.
 */
export function publicStatusCurrentMaintenance(
  status: PublicStatusFile | null,
  fresh: boolean,
): readonly PublicStatusCurrentMaintenanceView[] {
  if (status === null) return []

  return status.maintenancePeriods
    .filter((period) => period.completionObservedAt === null)
    .sort((left, right) => right.startedAt - left.startedAt)
    .map((period) => ({
      ...maintenanceView(period, status),
      confirmedCurrent: fresh,
    }))
}

/** Exact public catalog names, falling back to the published key after removal. */
function componentNames(
  keys: readonly string[],
  components: readonly PublicStatusComponent[],
): readonly string[] {
  return keys.map(
    (key) =>
      components.find((component) => component.componentKey === key)?.name ??
      key,
  )
}

/** Format only public words and absolute planned moments, without a hidden clock. */
function workView(
  work: PublicStatusWork,
  components: readonly PublicStatusComponent[],
): PublicStatusWorkView {
  return {
    ...work,
    componentNames: componentNames(work.componentKeys, components),
    startsAtLabel: formatMoment(work.startsAt),
    endsAtLabel: formatMoment(work.endsAt),
  }
}

/** Never infer a link from a title, nor turn completion detection into an end. */
function maintenanceView(
  period: PublicStatusMaintenancePeriod,
  status: PublicStatusFile,
): PublicStatusMaintenanceView {
  const work = status.scheduledWorks.find((work) => work.id === period.workId)
  const durationSeconds =
    period.endedAt === null
      ? null
      : Math.max(0, Math.floor((period.endedAt - period.startedAt) / 1000))

  return {
    ...period,
    announcement: !period.announcementKnown
      ? 'unknown'
      : period.workId === null
        ? 'unannounced'
        : 'announced',
    componentNames: componentNames(period.componentKeys, status.components),
    plannedWork: work === undefined ? null : workView(work, status.components),
    startedAtLabel: formatMoment(period.startedAt),
    endedAtLabel: period.endedAt === null ? null : formatMoment(period.endedAt),
    completionObservedAtLabel:
      period.completionObservedAt === null
        ? null
        : formatMoment(period.completionObservedAt),
    durationSeconds,
    durationLabel:
      durationSeconds === null ? null : formatDurationShort(durationSeconds),
  }
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
      period.completionObservedAt === null ||
      !intersects(
        window,
        period.startedAt,
        period.endedAt ?? period.completionObservedAt,
      )
    )
      continue
    entries.push({
      ...maintenanceView(period, status),
      kind: 'maintenance',
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
    const painted = days.map((day): PublicStatusDay => {
      let state: PublicStatusDayState = 'operational'
      // Actual maintenance is a full freeze. Announced component keys are words
      // about planned work, not a boundary on which components were closed.
      for (const period of status.maintenancePeriods) {
        if (
          intersects(
            day,
            period.startedAt,
            Math.min(now, period.endedAt ?? period.lastObservedAt),
          )
        ) {
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
