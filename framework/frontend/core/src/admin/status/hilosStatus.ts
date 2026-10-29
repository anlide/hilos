// Status reads the one page_response. Only presentation time lives in this
// closure; health, publication and incident identity always belong to the server.
import { z } from 'zod'
import { type ActionLifecycle } from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatCountdown } from '../../format/duration.js'
import { formatMoment } from '../../format/moment.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { toLocal } from '../../session/serverClock.js'
import { hideable } from '../../state/hideableSchema.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { statusSubscriberCountsSchema } from './hilosStatusSubscribers.js'
import {
  statusPublicWorkSchema,
  statusScheduledWorksSchema,
} from './hilosStatusWorks.js'

/** Page-data key in the existing page_response payload. */
export const HILOS_STATUS_DATA = 'status'

export const statusComponentStateSchema = z.enum([
  'operational',
  'checking',
  'partial_outage',
  'major_outage',
  'maintenance',
  'unknown',
])
export type HilosStatusComponentState = z.infer<
  typeof statusComponentStateSchema
>
const severitySchema = z.enum(['partial_outage', 'major_outage'])
const momentSchema = z.number().int()
const countSchema = z.number().int().nonnegative()

export const statusIncidentUpdateSchema = z
  .strictObject({
    id: z.number().int().positive(),
    kind: z.enum(['published', 'severity_changed', 'resolved', 'team']),
    severity: severitySchema,
    text: hideable(z.string()),
    at: momentSchema,
  })
  .readonly()

export const statusIncidentSchema = z
  .strictObject({
    incidentId: z.number().int().positive(),
    componentKey: z.string(),
    componentName: z.string(),
    agentType: z.string(),
    severity: severitySchema,
    detectedAt: momentSchema,
    publishAt: momentSchema,
    publishedAt: momentSchema.nullable(),
    suppressedAt: momentSchema.nullable(),
    expectedInstances: countSchema.nullable(),
    liveInstances: countSchema.nullable(),
    observedAt: momentSchema.nullable(),
    updates: z.array(statusIncidentUpdateSchema).readonly(),
  })
  .readonly()

export const statusOverviewSchema = z
  .strictObject({
    healthKnown: z.boolean(),
    historyCount: countSchema.nullable(),
    components: z
      .strictObject({
        operationalCount: countSchema,
        totalCount: z.number().int().positive(),
      })
      .refine(
        ({ operationalCount, totalCount }) => operationalCount <= totalCount,
      )
      .readonly(),
    publishDelaySeconds: hideable(countSchema.nullable()),
    maintenance: z
      .strictObject({
        phase: z.string(),
        operation: z.string().nullable(),
        startedAt: momentSchema.nullable(),
        announcement: z
          .strictObject({
            known: z.boolean(),
            work: statusPublicWorkSchema.nullable(),
          })
          .readonly()
          .nullable(),
      })
      .readonly()
      .nullable(),
    incidents: z.array(statusIncidentSchema).readonly().nullable(),
    subscribers: statusSubscriberCountsSchema.nullable(),
    scheduledWorks: statusScheduledWorksSchema.nullable(),
  })
  .readonly()

export type HilosStatusOverviewData = z.infer<typeof statusOverviewSchema>
export type HilosStatusIncident = z.infer<typeof statusIncidentSchema>
export type HilosStatusIncidentUpdate = z.infer<
  typeof statusIncidentUpdateSchema
>

/** Status pages bind the project's existing singletons. */
export interface HilosStatusContext {
  readonly scopes: ScopeManager
  readonly connection: HilosConnection
  readonly actions: ActionLifecycle
}

/** Shared labels, including neutral knowledge and suppressed publication. */
export const HILOS_STATUS_COPY = {
  tabsLabel: 'Status pages',
  operational: 'Operational',
  checking: 'Checking',
  partial_outage: 'Partial outage',
  major_outage: 'Major outage',
  maintenance: 'Maintenance',
  unknown: 'Status is being checked',
  allOperational: 'All systems operational',
  suppressed: 'Publication canceled manually',
  awaitingPublication: 'Waiting for the server to decide publication.',
  components: 'Components',
  componentsLink: 'View component health',
  settings: 'Settings',
  publishDelay: 'Publication delay',
  detected: 'Detected',
  publishAt: 'Publication scheduled',
  published: 'Published',
  slots: 'Live / expected instances',
  noIncidents: 'No open incidents',
  incidentsUnavailable: 'Incident data is unavailable.',
  delayUnavailable: 'Publication delay is unavailable.',
  publicPage: 'Public status page',
  updateKinds: {
    published: 'Published',
    severity_changed: 'Severity changed',
    resolved: 'Resolved',
    team: 'Team update',
  },
} as const

/** An incident card preserves every server fact and adds presentation only. */
export interface HilosStatusIncidentCard {
  readonly incident: HilosStatusIncident
  readonly state: 'checking' | HilosStatusIncident['severity']
  readonly label: string
  readonly suppressed: boolean
  readonly countdown: string | null
  readonly waitingForPublication: boolean
  readonly canCancelPublication: boolean
  readonly canPublishNow: boolean
  readonly canAddUpdate: boolean
  readonly detectedAtLabel: string | null
  readonly publishAtLabel: string | null
  readonly publishedAtLabel: string | null
  readonly observedAtLabel: string | null
  readonly updates: readonly (HilosStatusIncidentUpdate & {
    readonly atLabel: string | null
  })[]
}

/** Presentation never treats an expired countdown as a published incident. */
export function hilosStatusIncidentCard(
  incident: HilosStatusIncident,
  now: number,
): HilosStatusIncidentCard {
  const suppressed = incident.suppressedAt !== null
  const candidate = incident.publishedAt === null
  const countdown =
    candidate && !suppressed
      ? formatCountdown(toLocal(incident.publishAt), now)
      : null
  const state = candidate ? 'checking' : incident.severity
  return {
    incident,
    state,
    label: HILOS_STATUS_COPY[state],
    suppressed,
    countdown,
    waitingForPublication: candidate && !suppressed && countdown === null,
    canCancelPublication: candidate && !suppressed,
    canPublishNow: candidate && !suppressed,
    canAddUpdate: !candidate && !suppressed,
    detectedAtLabel: formatMoment(incident.detectedAt),
    publishAtLabel: formatMoment(incident.publishAt),
    publishedAtLabel:
      incident.publishedAt === null ? null : formatMoment(incident.publishedAt),
    observedAtLabel:
      incident.observedAt === null ? null : formatMoment(incident.observedAt),
    updates: incident.updates.map((update) => ({
      ...update,
      atLabel: formatMoment(update.at),
    })),
  }
}

export type HilosStatusSummary =
  | 'loading'
  | 'maintenance'
  | 'unknown'
  | 'operational'
  | 'incidents'
  | 'checking'

/** Complete page data and timed card projections, owned by this mount. */
export interface HilosStatusState {
  readonly overview: ReadonlySignal<HilosStatusOverviewData | null>
  readonly cards: ReadonlySignal<readonly HilosStatusIncidentCard[]>
  readonly summary: ReadonlySignal<HilosStatusSummary>
  readonly maintenance: ReadonlySignal<
    | (NonNullable<HilosStatusOverviewData['maintenance']> & {
        readonly startedAtLabel: string | null
      })
    | null
  >
  start(): void
  dispose(): void
}

/** Bind without fetching: bindPageScope already owns ingestion of status. */
export function createHilosStatusState(
  context: HilosStatusContext,
): HilosStatusState {
  const source = context.scopes.pageDataSignal(HILOS_STATUS_DATA)
  const overview = createSignal<HilosStatusOverviewData | null>(null)
  const now = createSignal(Date.now())
  let off: (() => void) | undefined
  let timer: ReturnType<typeof setInterval> | undefined
  let disposed = false
  const stopTimer = (): void => {
    if (timer !== undefined) clearInterval(timer)
    timer = undefined
  }
  const update = (): void => {
    const parsed = statusOverviewSchema.safeParse(source.get())
    const data =
      context.scopes.page()?.key === HilosPages.STATUS && parsed.success
        ? parsed.data
        : null
    now.set(Date.now())
    overview.set(data)
    stopTimer()
    if (
      data?.incidents?.some(
        (incident) =>
          incident.publishedAt === null && incident.suppressedAt === null,
      )
    ) {
      timer = setInterval(() => now.set(Date.now()), 1000)
    }
  }
  return {
    overview,
    cards: computedSignal(() =>
      (overview.get()?.incidents ?? []).map((incident) =>
        hilosStatusIncidentCard(incident, now.get()),
      ),
    ),
    summary: computedSignal(() => {
      const data = overview.get()
      if (data === null) return 'loading'
      if (data.maintenance !== null) return 'maintenance'
      if (!data.healthKnown || data.incidents === null) return 'unknown'
      if (data.incidents.length > 0) return 'incidents'
      return data.components.operationalCount === data.components.totalCount
        ? 'operational'
        : 'checking'
    }),
    maintenance: computedSignal(() => {
      const maintenance = overview.get()?.maintenance
      return maintenance == null
        ? null
        : {
            ...maintenance,
            startedAtLabel:
              maintenance.startedAt === null
                ? null
                : formatMoment(maintenance.startedAt),
          }
    }),
    start() {
      if (disposed || off !== undefined) return
      off = subscribeSignal(
        { get: () => ({ scope: context.scopes.page(), data: source.get() }) },
        update,
      )
      update()
    },
    dispose() {
      disposed = true
      off?.()
      off = undefined
      stopTimer()
      overview.set(null)
    },
  }
}
