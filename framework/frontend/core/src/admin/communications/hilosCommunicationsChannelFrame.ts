import { z } from 'zod'
import { hideable } from '../../state/hideableSchema.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type HilosCommunicationsContext } from './hilosCommunications.js'

/** The page-data key of the email channel's effective letter frame. */
export const LETTER_FRAME_DATA = 'letterFrame'

/** The channel field the existing set and reset actions address. */
export const LETTER_FRAME_FIELD = 'frame'

/** Effective frame and server-rendered preview, including viewer masking. */
export const letterFrameSchema = z.strictObject({
  channel: z.literal('email'),
  html: hideable(z.string()),
  source: z.enum(['settings', 'project', 'default']),
  previewHtml: hideable(z.string()),
})

export type HilosLetterFrame = z.infer<typeof letterFrameSchema>

/**
 * Read the effective frame only from the current page's data. Missing or
 * malformed data and scope clearing yield undefined, never the previous frame.
 *
 * @param context The project's page scopes.
 */
export function createHilosChannelFrame(
  context: Pick<HilosCommunicationsContext, 'scopes'>,
): ReadonlySignal<HilosLetterFrame | undefined> {
  const source = context.scopes.pageDataSignal(LETTER_FRAME_DATA)

  return computedSignal(() => {
    const parsed = letterFrameSchema.safeParse(source.get())

    return parsed.success ? parsed.data : undefined
  })
}
