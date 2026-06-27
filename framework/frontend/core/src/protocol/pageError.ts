// The page-subscription error signal (`subscription_page_error`): the framework
// reply when AbstractPage::onSubscribe throws a PageSubscriptionException (the
// resource is missing, or access is denied). The subscription stays active so a
// parent update can still flow; the frontend shows an error surface for the page
// without tearing the connection down. Framework-owned like page_response, it
// rides the project-signal seam (registered in PAGE_SIGNAL_SCHEMAS) rather than a
// parse-boundary kind, because it is routed into the page scope the same way.
import { z } from 'zod'

/**
 * The page-subscription error payload (PHP `PageSubscriptionErrorSignalData`):
 * the page the error is for — so a late one for a page already left is dropped,
 * mirroring page_response — plus the HTTP status, a machine-readable code, and a
 * human-readable message.
 */
export const pageSubscriptionErrorSchema = z.looseObject({
  page: z.string().min(1),
  httpCode: z.number().int(),
  errorCode: z.string(),
  message: z.string(),
})

export type PageSubscriptionError = z.infer<typeof pageSubscriptionErrorSchema>
