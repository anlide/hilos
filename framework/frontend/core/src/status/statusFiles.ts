// Public file boundary; deliberately separate from the eager connection hint.
import { z } from 'zod'

const momentSchema = z.int().nonnegative()
const publicIdSchema = z.string().min(1)
// JS $ also matches before a final newline, so require the exact length.
const silenceIdSchema = z
  .string()
  .length(32)
  .regex(/^[a-f0-9]{32}$/)
const componentKeysSchema = z
  .array(z.string().min(1))
  .refine((keys) => new Set(keys).size === keys.length)

/** Current public component state, including honest absence of knowledge. */
export const publicStatusComponentSchema = z.strictObject({
  componentKey: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  state: z.enum([
    'operational',
    'checking',
    'partial_outage',
    'major_outage',
    'maintenance',
    'unknown',
  ]),
})

/** A current, unpublished observation; never an archived incident. */
export const publicStatusCandidateSchema = z.strictObject({
  componentKey: z.string(),
  detectedAt: momentSchema,
})

/** Published plain text, never interpreted as markup by the reader. */
export const publicStatusUpdateSchema = z.strictObject({
  at: momentSchema,
  source: z.enum(['automatic', 'team']),
  text: z.string(),
})

/** A published interval, beginning at detection rather than publication. */
export const publicStatusOutageSchema = z.strictObject({
  id: z.string(),
  componentKey: z.string(),
  severity: z.enum(['partial_outage', 'major_outage']),
  detectedAt: momentSchema,
  publishedAt: momentSchema,
  resolvedAt: momentSchema.nullable(),
  updates: z.array(publicStatusUpdateSchema),
})

/** Announced work; its schedule alone does not color historical days. */
export const publicStatusWorkSchema = z
  .strictObject({
    id: publicIdSchema,
    title: z.string().min(1),
    description: z.string().nullable(),
    componentKeys: componentKeysSchema,
    startsAt: momentSchema,
    endsAt: momentSchema,
  })
  .refine((work) => work.endsAt > work.startsAt)

/** Actual maintenance, including an unannounced period with no work id. */
export const publicStatusMaintenancePeriodSchema = z
  .strictObject({
    id: publicIdSchema,
    workId: publicIdSchema.nullable(),
    announcementKnown: z.boolean(),
    title: z.string().min(1),
    componentKeys: componentKeysSchema,
    startedAt: momentSchema,
    lastObservedAt: momentSchema,
    endedAt: momentSchema.nullable(),
    completionObservedAt: momentSchema.nullable(),
  })
  .refine(
    (period) =>
      (period.announcementKnown || period.workId === null) &&
      period.lastObservedAt >= period.startedAt &&
      (period.completionObservedAt === null ||
        period.completionObservedAt >= period.lastObservedAt) &&
      (period.endedAt === null ||
        (period.completionObservedAt !== null &&
          period.endedAt === period.completionObservedAt)),
  )

/** A closed publication gap; signed epoch milliseconds preserve its real start. */
export const publicStatusSilencePeriodSchema = z
  .strictObject({
    id: silenceIdSchema,
    startedAt: z.int(),
    endedAt: z.int(),
  })
  .refine((period) => period.endedAt > period.startedAt)

/** v4 component: the moment its observation began, signed like a gap start. */
const publicStatusObservedComponentSchema = publicStatusComponentSchema.extend({
  observedSince: z.int(),
})

/** Literal v2: required keys, explicit nulls and no private/extra fields. */
const publicStatusV2FileSchema = z.strictObject({
  schemaVersion: z.literal(2),
  revision: publicIdSchema,
  generatedAt: momentSchema,
  config: z.strictObject({
    pollIntervalSeconds: z.int().positive(),
    staleAfterSeconds: z.int().positive(),
  }),
  components: z.array(publicStatusComponentSchema),
  candidates: z.array(publicStatusCandidateSchema),
  outages: z.array(publicStatusOutageSchema),
  scheduledWorks: z.array(publicStatusWorkSchema),
  maintenancePeriods: z.array(publicStatusMaintenancePeriodSchema),
})

/** Literal v3: v2 plus closed publication gaps in canonical order. */
const publicStatusV3FileSchema = publicStatusV2FileSchema.extend({
  schemaVersion: z.literal(3),
  silencePeriods: z
    .array(publicStatusSilencePeriodSchema)
    .refine(
      (periods) =>
        new Set(periods.map((period) => period.id)).size === periods.length,
    )
    .refine((periods) =>
      periods.every((period, index) => {
        const previous = periods[index - 1]
        return (
          previous === undefined ||
          period.startedAt > previous.startedAt ||
          (period.startedAt === previous.startedAt && period.id >= previous.id)
        )
      }),
    ),
})

/**
 * Keep literal legacy boundaries: v1 and v2 carry no publication gaps, and only
 * v4 says when each component came under observation.
 */
export const publicStatusFileSchema = z.discriminatedUnion('schemaVersion', [
  publicStatusV2FileSchema.extend({
    schemaVersion: z.literal(1),
    scheduledWorks: z.array(publicStatusWorkSchema).max(0),
    maintenancePeriods: z.array(publicStatusMaintenancePeriodSchema).max(0),
  }),
  publicStatusV2FileSchema,
  publicStatusV3FileSchema,
  publicStatusV3FileSchema.extend({
    schemaVersion: z.literal(4),
    components: z.array(publicStatusObservedComponentSchema),
  }),
])

/** Only aliveAt of a coherent pair proves publisher freshness. */
export const publicStatusHeartbeatSchema = z.strictObject({
  schemaVersion: z.union([
    z.literal(1),
    z.literal(2),
    z.literal(3),
    z.literal(4),
  ]),
  revision: publicIdSchema,
  aliveAt: momentSchema,
})

export type PublicStatusFile = z.infer<typeof publicStatusFileSchema>
export type PublicStatusHeartbeat = z.infer<typeof publicStatusHeartbeatSchema>
/** A v1–v3 component, or a v4 one with its start of observation; `'observedSince' in` tells them apart. */
export type PublicStatusComponent =
  | z.infer<typeof publicStatusComponentSchema>
  | z.infer<typeof publicStatusObservedComponentSchema>
export type PublicStatusState = PublicStatusComponent['state']
export type PublicStatusCandidate = z.infer<typeof publicStatusCandidateSchema>
export type PublicStatusOutage = z.infer<typeof publicStatusOutageSchema>
export type PublicStatusUpdate = z.infer<typeof publicStatusUpdateSchema>
export type PublicStatusWork = z.infer<typeof publicStatusWorkSchema>
export type PublicStatusMaintenancePeriod = z.infer<
  typeof publicStatusMaintenancePeriodSchema
>
export type PublicStatusSilencePeriod = z.infer<
  typeof publicStatusSilencePeriodSchema
>

/** Two independently fetched files accepted as one generation. */
export interface PublicStatusPair {
  readonly status: PublicStatusFile
  readonly heartbeat: PublicStatusHeartbeat
}

/** Injectable HTTP boundary; production uses the browser's same-origin fetch. */
export type PublicStatusFetch = (
  url: string,
  init: RequestInit,
) => Promise<Pick<Response, 'ok' | 'json'>>

/** Only a publication race warrants an immediate reread. */
export type PublicStatusReadResult =
  | { readonly kind: 'valid'; readonly pair: PublicStatusPair }
  | { readonly kind: 'revision-mismatch' }
  | { readonly kind: 'unavailable' }

/**
 * Read exact public addresses without caches, redirects or daemon APIs. Failures
 * are neutral; neither HTTP success nor generatedAt is a substitute heartbeat.
 */
export async function readPublicStatusFiles(
  signal: AbortSignal,
  fetchFile: PublicStatusFetch = (url, init) => globalThis.fetch(url, init),
): Promise<PublicStatusReadResult> {
  const init: RequestInit = {
    signal,
    cache: 'no-store',
    mode: 'same-origin',
    credentials: 'same-origin',
    redirect: 'error',
  }
  // Settle both parts even on failure: the next pass cannot overlap a leftover
  // request from this one when just one of the two endpoints fails early.
  const files = await Promise.allSettled(
    ['/status-data/status.json', '/status-data/heartbeat.json'].map(
      async (url): Promise<unknown> => {
        const response = await fetchFile(url, init)
        if (!response.ok) throw new Error('Public Status file unavailable')

        return response.json()
      },
    ),
  )
  const [statusResult, heartbeatResult] = files
  if (
    signal.aborted ||
    statusResult?.status !== 'fulfilled' ||
    heartbeatResult?.status !== 'fulfilled'
  ) {
    return { kind: 'unavailable' }
  }
  const status = publicStatusFileSchema.safeParse(statusResult.value)
  const heartbeat = publicStatusHeartbeatSchema.safeParse(heartbeatResult.value)
  if (!status.success || !heartbeat.success) return { kind: 'unavailable' }
  if (
    status.data.revision !== heartbeat.data.revision ||
    status.data.schemaVersion !== heartbeat.data.schemaVersion
  ) {
    return { kind: 'revision-mismatch' }
  }

  return {
    kind: 'valid',
    pair: { status: status.data, heartbeat: heartbeat.data },
  }
}
