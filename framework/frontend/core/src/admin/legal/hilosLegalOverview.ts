import { z } from 'zod'
import { type HilosLegalContext } from '../../legal/legalAgreements.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'

/** The page-data key of the legal overview. */
export const LEGAL_OVERVIEW_SECTION = 'legalOverview'

const legalOverviewDocumentSchema = z.strictObject({
  document: z.string(),
  documentLabel: z.string(),
  window: z.number().int().nonnegative(),
  lapsed: z.number().int().nonnegative(),
})

/** The complete, nonpersonal legal overview page data. */
export const legalOverviewSchema = z
  .strictObject({
    documentCount: z.number().int().nonnegative(),
    documents: z.array(legalOverviewDocumentSchema),
    newerSetCount: z.number().int().nonnegative(),
  })
  .refine(
    ({ documentCount, documents, newerSetCount }) =>
      documentCount === documents.length &&
      new Set(documents.map(({ document }) => document)).size ===
        documents.length &&
      newerSetCount <= documentCount,
  )

/** The validated legal overview for the current page scope. */
export type HilosLegalOverview = z.infer<typeof legalOverviewSchema>

/**
 * Read the complete overview from the current legal page response.
 * Missing or malformed data clears the selector instead of retaining old counts.
 *
 * @param context The project's legal page context.
 */
export function createHilosLegalOverview(
  context: HilosLegalContext,
): ReadonlySignal<HilosLegalOverview | null> {
  const source = context.scopes.pageDataSignal(LEGAL_OVERVIEW_SECTION)
  return computedSignal(() => {
    const parsed = legalOverviewSchema.safeParse(source.get())
    return parsed.success ? parsed.data : null
  })
}
