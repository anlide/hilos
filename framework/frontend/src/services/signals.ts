import type { WebSocketOutcome } from '../types/websocket-messages'

/**
 * Parser for the `data` payload of an incoming signal.
 *
 * Returns the typed payload on success, or `null` if the raw value does not
 * match the expected shape. Router logs and skips on `null` — never throws.
 *
 * For signals with no payload body, use `D = undefined` and a parser that
 * returns `undefined` (see {@link parseEmptyPayload}).
 */
export type SignalParser<D> = (raw: unknown) => D | null

/**
 * Canonical parser for signals with no payload body.
 * Always succeeds; returns `undefined` as the typed payload.
 */
export const parseEmptyPayload: SignalParser<undefined> = () => undefined

/**
 * Definition of a single incoming signal.
 *
 * Combines in one object:
 * - the wire signal name (`type`),
 * - a runtime parser that validates the raw `data` payload,
 * - an optional {@link WebSocketOutcome} marker for action-ack signals.
 *
 * Used by {@link VueSignalRouter} to register typed handlers:
 *
 *   router.on(someSignal, (data) => { ... })  // data is typed as D
 *
 * Designed to be subclassed by downstream projects (e.g. `ChatSignalDefinition`)
 * to add domain-specific helpers and factory methods.
 */
export class SignalDefinition<
  T extends string = string,
  D = undefined,
  O extends WebSocketOutcome | null = null,
> {
  /**
   * @param type    Wire signal name (matches backend signal constant).
   * @param parser  Runtime validator/narrower of the raw `data` payload.
   * @param outcome Optional outcome marker for action-ack signals.
   *                `null` for regular broadcast signals.
   */
  constructor(
    public readonly type: T,
    public readonly parser: SignalParser<D>,
    public readonly outcome: O = null as O,
  ) {}

  /**
   * Parse a raw incoming `data` payload using the stored parser.
   * Returns `null` on invalid input (caller should log + skip).
   */
  parse(raw: unknown): D | null {
    return this.parser(raw)
  }

  /**
   * Dispatch key used by the router to index this definition.
   *
   * - For regular signals: just `type`.
   * - For outcome-marked signals: `type::success` or `type::fail` — this lets
   *   two definitions share the same wire `type` but differ by outcome
   *   (e.g. a single signal name emitted with both outcomes).
   */
  get dispatchKey(): string {
    return this.outcome === null ? this.type : `${this.type}::${this.outcome}`
  }
}

/**
 * Compute the dispatch key for a raw incoming message.
 * Mirrors {@link SignalDefinition.dispatchKey} logic but on the wire side.
 *
 * Used by the router during dispatch to look up the matching definition.
 */
export const computeDispatchKey = (type: string, outcome: WebSocketOutcome | undefined): string => {
  return outcome === undefined ? type : `${type}::${outcome}`
}
