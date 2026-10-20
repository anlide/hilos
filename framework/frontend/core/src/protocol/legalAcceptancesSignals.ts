// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'

export const LEGAL_ACCEPTANCES_SIGNAL =
  'subscription_page_hilos_legal_acceptances'

export const legalAcceptanceFiltersSchema = z.looseObject({
  documents: z.array(
    z.looseObject({
      document: z.string(),
      declared: z.boolean().nullable(),
      revisions: z.array(
        z.looseObject({
          revisionId: z.string(),
          declared: z.boolean().nullable(),
        }),
      ),
    }),
  ),
})

export const LEGAL_ACCEPTANCES_SIGNAL_SCHEMAS = {
  [LEGAL_ACCEPTANCES_SIGNAL]: legalAcceptanceFiltersSchema,
}

export type HilosLegalAcceptanceFilters = z.infer<
  typeof legalAcceptanceFiltersSchema
>

/**
 * The frame carrying the administrator's export after every change. Not named
 * `subscription_page_…`: the connection keeps such frames by type, and this one
 * would overwrite the filter vocabulary frame of the same page.
 */
export const LEGAL_ACCEPTANCES_EXPORT_SIGNAL =
  'hilos_legal_acceptances_export_state'

/** The filters and the search an export was ordered under; null where none was set. */
export const exportFiltersShape = {
  document: z.string().nullable(),
  revisionId: z.string().nullable(),
  search: z.string().nullable(),
}

/** An export on the wire; its moments are server epoch milliseconds. */
export const legalAcceptancesExportNodeSchema = z.discriminatedUnion('state', [
  z.object({
    state: z.literal('preparing'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.null(),
    expiresAt: z.null(),
    sizeBytes: z.null(),
    records: z.null(),
  }),
  z.object({
    state: z.literal('ready'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.number(),
    records: z.number(),
  }),
  z.object({
    state: z.literal('failed'),
    ...exportFiltersShape,
    requestedAt: z.number(),
    finishedAt: z.number(),
    expiresAt: z.number(),
    sizeBytes: z.null(),
    records: z.null(),
  }),
])

/** One administrator's export, as the wire and the store carry it. */
export type HilosLegalAcceptancesExportNode = z.infer<
  typeof legalAcceptancesExportNodeSchema
>

/** The payload of {@link LEGAL_ACCEPTANCES_EXPORT_SIGNAL}. */
export const legalAcceptancesExportStateSchema = z.object({
  legalAcceptancesExport: legalAcceptancesExportNodeSchema.nullable(),
})

/** Merged into every connection by `createHilosConnection`. */
export const LEGAL_ACCEPTANCES_EXPORT_SIGNAL_SCHEMAS = {
  [LEGAL_ACCEPTANCES_EXPORT_SIGNAL]: legalAcceptancesExportStateSchema,
}
