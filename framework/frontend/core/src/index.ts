// @hilos/core — the framework-agnostic Hilos frontend core.
//
// All non-visual logic lives here and never imports a UI framework: the WS
// transport, the signal/action protocol and its discriminated-union parsers,
// the subscription manager, the normalized entity store, and the headless
// table / modal / conflict state machines. Fills in across rewrite step 7;
// shipped so far: the connection machine, the signal parse boundary, the
// reactive signal primitive the stores build on, and the scope-partitioned
// entity store.

export {
  createSignal,
  computedSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
  type Unsubscribe,
} from './state/signal.js'
export {
  EntityStore,
  type EntityId,
  type EntityRef,
  type EntitySnapshot,
} from './state/EntityStore.js'
export { Scope, ScopeManager, type ScopeKind } from './state/ScopeManager.js'
export {
  SIGNAL_TYPE_HANDSHAKE,
  KEEPALIVE_TEXT_PING,
} from './protocol/constants.js'
export { assertNever } from './protocol/assertNever.js'
export {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  type SignalEnvelope,
  type HandshakeSignalData,
} from './protocol/envelope.js'
export {
  parseSignal,
  type ParsedSignal,
  type HandshakeSignal,
  type UnknownSignal,
  type ParseFailure,
  type ParseResult,
} from './protocol/parseSignal.js'
export {
  computeBackoffDelay,
  DEFAULT_RECONNECT_OPTIONS,
  type ReconnectOptions,
} from './connection/backoff.js'
export {
  HilosConnection,
  type ConnectionState,
  type WebSocketLike,
  type BuildMismatch,
  type HilosConnectionEventMap,
  type HilosConnectionOptions,
} from './connection/HilosConnection.js'
