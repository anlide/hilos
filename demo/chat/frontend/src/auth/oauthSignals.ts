// The two inbound OAuth login signals the daemon delivers WS_USER to the
// initiating connection (HIL-281), declared as project signal schemas so the
// parse boundary validates them before a reaction runs. Kept pure (schemas +
// names only, no connection import) so `bootstrap/connection` can merge the
// schemas without a cycle through `oauthLogin`, which drives the flow over them.
//
// - `hilos_oauth_authorize` is the `oauth_start` reply: `action_success` carries
//   no domain payload, so the provider authorize URL rides this signal instead,
//   and the surface navigates the browser to it (the "client action = loading +
//   signal, never fire-forget" pattern applied to the redirect start).
// - `hilos_oauth_result` is the async login's failure/timeout arm; success has no
//   signal of its own — it rides the current-user handshake fan-out (HIL-161).
//
// Both names are byte-equal to the backend `HilosSignalConstants` constants.
import { z, type ProjectSignalSchemas } from '@hilos/core'

/** Signal `type` for the OAuth start reply (PHP `HilosSignalConstants::HILOS_OAUTH_AUTHORIZE`). */
export const OAUTH_AUTHORIZE_SIGNAL = 'hilos_oauth_authorize'

/** Signal `type` for the OAuth login failure/timeout (PHP `HilosSignalConstants::HILOS_OAUTH_RESULT`). */
export const OAUTH_RESULT_SIGNAL = 'hilos_oauth_result'

/**
 * `reason` value marking an email collision that needs re-auth before linking (PHP
 * `OAuthResultSignalData::REASON_REAUTH_REQUIRED`). The provider email matched an
 * existing verified account, so instead of failing, the callback arms a pending
 * link (the payload's `email` + `linkToken`) and sends the user to re-authenticate.
 */
export const OAUTH_REASON_REAUTH_REQUIRED = 'reauth_required'

/**
 * `reason` marking a successful profile account link (PHP
 * `OAuthResultSignalData::REASON_LINK_OK`, HIL-401). A link-mode exchange never
 * touches the session, so success is signalled explicitly rather than riding a
 * current-user update; the callback route returns the user to their profile.
 */
export const OAUTH_REASON_LINK_OK = 'oauth_link_ok'

/**
 * `reason` marking a refused profile link because the provider identity already
 * belongs to some account (PHP `OAuthResultSignalData::REASON_LINK_DUPLICATE`,
 * HIL-401). The link is never transferred; the callback route shows a message.
 */
export const OAUTH_REASON_LINK_DUPLICATE = 'oauth_link_duplicate'

/**
 * `reason` marking a failed profile link (the exchange or the identity write did
 * not complete; PHP `OAuthResultSignalData::REASON_LINK_FAILED`, HIL-401). The
 * callback route shows a generic message.
 */
export const OAUTH_REASON_LINK_FAILED = 'oauth_link_failed'

/**
 * The authorize-reply payload: the absolute provider URL to navigate to. The
 * wire also carries the targeting `acceptKey`, kept here for validation fidelity
 * though the reaction ignores it (WS_USER already targets this connection).
 */
export const oauthAuthorizeSignalSchema = z.object({
  acceptKey: z.string(),
  authorizeUrl: z.string().min(1),
})

/**
 * The login-failure / re-auth-to-link payload: the provider the attempt was for
 * and a stable, non-sensitive reason code (network/provider detail stays in the
 * agent log). On {@link OAUTH_REASON_REAUTH_REQUIRED} it also carries the colliding
 * account `email` (to pre-fill the re-auth form) and the signed `linkToken` to
 * redeem after; both are null on the arms that carry neither.
 */
export const oauthResultSignalSchema = z.object({
  acceptKey: z.string(),
  provider: z.string(),
  reason: z.string(),
  email: z.string().nullable().default(null),
  linkToken: z.string().nullable().default(null),
})

/** Typed authorize-reply payload (the schema's output). */
export type OAuthAuthorizeSignalData = z.infer<
  typeof oauthAuthorizeSignalSchema
>

/** Typed login-failure payload (the schema's output). */
export type OAuthResultSignalData = z.infer<typeof oauthResultSignalSchema>

/**
 * The project signal schemas `createHilosConnection` merges so the two OAuth
 * signals parse at the boundary before {@link oauthLogin} reacts to them.
 */
export const OAUTH_SIGNAL_SCHEMAS: ProjectSignalSchemas = {
  [OAUTH_AUTHORIZE_SIGNAL]: oauthAuthorizeSignalSchema,
  [OAUTH_RESULT_SIGNAL]: oauthResultSignalSchema,
}
