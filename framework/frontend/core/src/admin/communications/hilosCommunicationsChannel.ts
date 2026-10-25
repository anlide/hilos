import { z } from 'zod'
import { hideable } from '../../state/hideableSchema.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type HilosCommunicationsContext } from './hilosCommunications.js'

/** The page-data key of a channel's summary across its three pages. */
export const CHANNEL_SUMMARY_DATA = 'channelSummary'

/** The complete summary sent by PHP, without configuration values or secrets. */
export const channelSummarySchema = z.strictObject({
  channel: z.string(),
  label: z.string(),
  enabled: hideable(z.boolean()),
  configured: z.boolean(),
  driver: z.string().nullable(),
  missingFields: z.number().int().nonnegative(),
  fieldCount: z.number().int().nonnegative(),
  secretFieldCount: z.number().int().nonnegative(),
  overriddenFieldCount: z.number().int().nonnegative(),
})

export type HilosChannelSummary = z.infer<typeof channelSummarySchema>

/**
 * Read the channel summary from the current page's answer and data-only updates.
 * Missing or invalid data and a cleared page scope yield no summary.
 *
 * @param context The project's page scopes.
 */
export function createHilosChannelSummary(
  context: Pick<HilosCommunicationsContext, 'scopes'>,
): ReadonlySignal<HilosChannelSummary | null> {
  const source = context.scopes.pageDataSignal(CHANNEL_SUMMARY_DATA)

  return computedSignal(() => {
    const parsed = channelSummarySchema.safeParse(source.get())

    return parsed.success ? parsed.data : null
  })
}
