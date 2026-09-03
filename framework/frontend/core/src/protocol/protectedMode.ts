// Protected mode as the client sees it: the freeze the backend announces on the
// welcome frame and on the pushed `protected_mode` frame, plus the words to show
// while it holds and whether a verifier may present a pass to be let through it.
// The copy is authored on the backend and travels the wire (the Hilos i18n
// model); the constant below is the last resort for a frame that said the mode is
// on but carried no sentence with it.
import { z } from 'zod'

/**
 * The protected-mode block as it rides the wire (PHP `ProtectedModeStateSignalData`,
 * and the `protectedMode` object of the welcome frame). Loose and forgiving on the
 * copy fields — they are absent on the lift frame and null when the backend registry
 * had no entry — but `active` is required, because a block that cannot say whether
 * the mode is on carries no information at all.
 */
export const protectedModeBlockSchema = z.looseObject({
  active: z.boolean(),
  operation: z.string().nullish(),
  title: z.string().nullish(),
  message: z.string().nullish(),
  bannerMessage: z.string().nullish(),
  acceptsPass: z.boolean().nullish(),
  passIssued: z.boolean().nullish(),
})

export type ProtectedModeBlock = z.infer<typeof protectedModeBlockSchema>

/**
 * Protected-mode state held by the connection: whether the node is frozen and, if
 * it is, what to tell the visitor. The wire's nulls are normalized to `undefined`
 * so a consumer has one absent value to test rather than two.
 *
 * `active` describes THIS connection — whether the freeze locks it out — while
 * `acceptsPass` describes the node: whether the verification window is open at all.
 * They come apart for exactly one client, and that is what the pair is for. An
 * admitted verifier is told `active: false`, in the same words a lifted mode is
 * announced with, and only `acceptsPass` still standing says the freeze is on and
 * this connection is simply inside it. A surface renders the code field on both
 * together; the reload on the way out waits for both to fall.
 *
 * `passIssued` describes the node too, and is the narrower question: whether at
 * least one code is standing, so the field has something it could take. The window
 * opens before any code is minted, and between the two the field would be a lie —
 * so the surface shows a sentence saying to wait instead. It is a second bit rather
 * than a narrowed `acceptsPass` because that flag also decides when this client
 * calls the mode over and when it drops a presented key; narrowed, it would reload
 * an admitted verifier out of the window the moment nobody held a code.
 *
 * `passRejected` is the one field the wire does not carry: the backend answers a
 * wrong pass by simply leaving the connection locked out, which is the only answer
 * a frozen node can give without an agent to compose one. The connection turns
 * that silence into a bit — it presented a key and came back still frozen — so the
 * surface can say so instead of looking like it did nothing.
 */
export interface ProtectedModeStatus {
  readonly active: boolean
  readonly operation: string | undefined
  readonly title: string | undefined
  readonly message: string | undefined
  readonly bannerMessage: string | undefined
  readonly acceptsPass: boolean
  readonly passIssued: boolean
  readonly passRejected: boolean
}

/** No freeze known: the state every connection starts in. */
export const PROTECTED_MODE_INACTIVE: ProtectedModeStatus = {
  active: false,
  operation: undefined,
  title: undefined,
  message: undefined,
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

/**
 * Last-resort copy: shown only when the backend said the mode is on but sent no
 * words for it. The frontend does not otherwise author this sentence — a project
 * changes it by registering its own entry in the backend stub registry. Kept word
 * for word in step with the framework default there (`Hilos::PROTECTED_MODE_STUB`),
 * so which of the two spoke is nothing the visitor can tell.
 */
export const PROTECTED_MODE_FALLBACK_COPY = {
  title: 'Maintenance in progress',
  message:
    'The application is briefly unavailable while a maintenance operation finishes.' +
    ' It will come back on its own.',
} as const

/**
 * Last-resort sentence of the banner: shown only when the frame said this client
 * is inside a mode that still holds the node, but carried no words for it. Kept
 * word for word in step with the framework default in the backend stub registry
 * (`Hilos::PROTECTED_MODE_STUB`), for the same reason the surface copy above is —
 * which of the two spoke is nothing the reader can tell.
 */
export const PROTECTED_MODE_BANNER_FALLBACK_MESSAGE =
  'Maintenance has finished and is being verified.' +
  ' The system is still closed to everyone else.'

/**
 * The sentence the application shell puts on its banner, or nothing when there is
 * no banner to raise.
 *
 * One function rather than a predicate beside the words, so the three view
 * packages cannot drift into three different answers about when the banner is up:
 * a shell renders it exactly when this returns something. The condition is the
 * pair coming apart — the mode does not hold THIS client, and the verification
 * window on the node is still open — which is the one state in which somebody is
 * inside an application the mode still owns.
 *
 * @param status Protected-mode state the connection currently holds.
 */
export function protectedModeBannerCopy(
  status: ProtectedModeStatus,
): string | undefined {
  if (status.active || !status.acceptsPass) {
    return undefined
  }

  return status.bannerMessage ?? PROTECTED_MODE_BANNER_FALLBACK_MESSAGE
}

/**
 * The words the surface puts around the code field.
 *
 * Authored on the frontend, unlike the copy above it, and that is a decision
 * rather than an oversight: a second entry in the backend stub registry would make
 * every project owe another mandatory string (startup already refuses when the
 * default entry goes missing) in order to reword one screen. Kept in the core so
 * the three view packages cannot drift into three different sentences.
 */
export const PROTECTED_MODE_PASS_COPY = {
  prompt: 'Verifying this system? Enter the code you were given.',
  submit: 'Continue',
  rejected: 'That code was not accepted. Check it and try again.',
  pending:
    'No code has been issued yet - ask the operator running this maintenance for one.',
} as const

/**
 * Normalize a parsed wire block into the state the connection holds.
 *
 * `passRejected` starts false here and stays the connection's business: a frame
 * describes the node, not this client's attempts at getting into it.
 *
 * @param block The parsed block, or undefined when the frame carried none.
 */
export function toProtectedModeStatus(
  block: ProtectedModeBlock | undefined,
): ProtectedModeStatus {
  if (block === undefined) {
    return PROTECTED_MODE_INACTIVE
  }

  return {
    active: block.active,
    operation: block.operation ?? undefined,
    title: block.title ?? undefined,
    message: block.message ?? undefined,
    bannerMessage: block.bannerMessage ?? undefined,
    acceptsPass: block.acceptsPass ?? false,
    passIssued: block.passIssued ?? false,
    passRejected: false,
  }
}

/**
 * Whether two states say the same thing — the connection emits its change event
 * only when they do not, so a reconnect that re-announces an unchanged freeze
 * stays silent.
 *
 * @param first One state.
 * @param second The other state.
 */
export function isSameProtectedModeStatus(
  first: ProtectedModeStatus,
  second: ProtectedModeStatus,
): boolean {
  return (
    first.active === second.active &&
    first.operation === second.operation &&
    first.title === second.title &&
    first.message === second.message &&
    first.bannerMessage === second.bannerMessage &&
    first.acceptsPass === second.acceptsPass &&
    first.passIssued === second.passIssued &&
    first.passRejected === second.passRejected
  )
}
