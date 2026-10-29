// Public file boundary; deliberately separate from the eager connection hint.
import { z } from 'zod'

const momentSchema = z.int().nonnegative()
const publicIdSchema = z.string().min(1)
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

/** The actual v1 writer had two empty lists; keep its published outage archive. */
export const publicStatusFileSchema = z.discriminatedUnion('schemaVersion', [
  publicStatusV2FileSchema.extend({
    schemaVersion: z.literal(1),
    scheduledWorks: z.array(publicStatusWorkSchema).max(0),
    maintenancePeriods: z.array(publicStatusMaintenancePeriodSchema).max(0),
  }),
  publicStatusV2FileSchema,
])

/** Only aliveAt of a coherent pair proves publisher freshness. */
export const publicStatusHeartbeatSchema = z.strictObject({
  schemaVersion: z.union([z.literal(1), z.literal(2)]),
  revision: publicIdSchema,
  aliveAt: momentSchema,
})

export type PublicStatusFile = z.infer<typeof publicStatusFileSchema>
export type PublicStatusHeartbeat = z.infer<typeof publicStatusHeartbeatSchema>
export type PublicStatusComponent = z.infer<typeof publicStatusComponentSchema>
export type PublicStatusState = PublicStatusComponent['state']
export type PublicStatusCandidate = z.infer<typeof publicStatusCandidateSchema>
export type PublicStatusOutage = z.infer<typeof publicStatusOutageSchema>
export type PublicStatusUpdate = z.infer<typeof publicStatusUpdateSchema>
export type PublicStatusWork = z.infer<typeof publicStatusWorkSchema>
export type PublicStatusMaintenancePeriod = z.infer<
  typeof publicStatusMaintenancePeriodSchema
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
