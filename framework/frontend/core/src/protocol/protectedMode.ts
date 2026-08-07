// Protected mode as the client sees it: the freeze the backend announces on the
// welcome frame and on the pushed `protected_mode` frame, plus the words to show
// while it holds. The copy is authored on the backend and travels the wire (the
// Hilos i18n model); the constant below is the last resort for a frame that said
// the mode is on but carried no sentence with it.
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
})

export type ProtectedModeBlock = z.infer<typeof protectedModeBlockSchema>

/**
 * Protected-mode state held by the connection: whether the node is frozen and, if
 * it is, what to tell the visitor. The wire's nulls are normalized to `undefined`
 * so a consumer has one absent value to test rather than two.
 */
export interface ProtectedModeStatus {
  readonly active: boolean
  readonly operation: string | undefined
  readonly title: string | undefined
  readonly message: string | undefined
}

/** No freeze known: the state every connection starts in. */
export const PROTECTED_MODE_INACTIVE: ProtectedModeStatus = {
  active: false,
  operation: undefined,
  title: undefined,
  message: undefined,
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
 * Normalize a parsed wire block into the state the connection holds.
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
    first.message === second.message
  )
}
