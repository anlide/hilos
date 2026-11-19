// The page-moved signal (`page_moved`): the framework's third answer to a page
// subscription, sent in place of page_response when the record the address
// names is now reached by another address — opened at the old one, or renamed
// while a window stood on it. Framework-owned like page_response, it rides the
// project-signal seam (registered in PAGE_SIGNAL_SCHEMAS) rather than a
// parse-boundary kind, because it is routed into the page scope the same way.
import { z } from 'zod'

/**
 * The page-moved payload (PHP `PageMovedSignalData`): the page and route
 * params the subscription was made with — so a late one for an address already
 * left is dropped, mirroring page_resubscribe — plus the page and route params
 * of the address the record lives at now. Every target param is a non-empty
 * string, the shape a path slot is filled from.
 */
export const pageMovedSchema = z.looseObject({
  page: z.string().min(1),
  params: z.record(z.string(), z.unknown()),
  targetPage: z.string().min(1),
  targetParams: z.record(z.string(), z.string().min(1)),
})

export type PageMoved = z.infer<typeof pageMovedSchema>
