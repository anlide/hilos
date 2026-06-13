// @hilos/core — the framework-agnostic Hilos frontend core.
//
// All non-visual logic lives here and never imports a UI framework: the WS
// transport, the signal/action protocol and its discriminated-union parsers,
// the subscription manager, the normalized entity store, and the headless
// table / modal / conflict state machines. Fills in across rewrite step 7;
// shipped so far: the connection machine, the signal parse boundary with the
// project-signal schema seam, the reactive signal primitive the stores build
// on, the scope-partitioned stores, the normalizer ingest boundary, and the
// page-subscription manager.

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
export { type Entity, type User } from './state/entity.js'
export {
  entityCollection,
  type EntityCollection,
} from './state/EntityCollection.js'
export {
  readBoolean,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from './state/fieldReaders.js'
export { Scope, ScopeManager, type ScopeKind } from './state/ScopeManager.js'
export { DataStore } from './state/DataStore.js'
export { ListStore, type ListItem } from './state/ListStore.js'
export {
  ingest,
  type EntityFragment,
  type ListItemFragment,
  type ListSection,
  type NormalizerOptions,
  type ScopePayload,
} from './state/normalizer.js'
export {
  PageSubscription,
  type PageSubscriptionConnection,
} from './subscription/PageSubscription.js'
export {
  SIGNAL_TYPE_HANDSHAKE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_ACTION,
  SIGNAL_TYPE_ACTION_ERROR,
  FIELD_TYPE,
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_ACTION,
  FIELD_DATA,
  KEEPALIVE_TEXT_PING,
} from './protocol/constants.js'
export {
  createPageRouter,
  type PageRouter,
  type PageRouteMatch,
  type PageRouterOptions,
} from './routing/PageRouter.js'
export {
  createHilosRouter,
  browserNavigationEnvironment,
  type HilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from './routing/HilosRouter.js'
export { HilosPages, HILOS_PAGE_ROUTES } from './routing/hilosPages.js'
export { assertNever } from './protocol/assertNever.js'
export {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  type SignalEnvelope,
  type HandshakeSignalData,
} from './protocol/envelope.js'
export {
  entityFragmentSchema,
  listItemSchema,
  listSectionSchema,
  scopePayloadSchema,
  pageResponseSchema,
  type ScopePayloadWire,
  type PageResponseWire,
} from './protocol/scopePayload.js'
export {
  parseSignal,
  type ParsedSignal,
  type HandshakeSignal,
  type ActionErrorSignal,
  type ProjectSignal,
  type ProjectSignalSchemas,
  type UnknownSignal,
  type ParseFailure,
  type ParseResult,
} from './protocol/parseSignal.js'
export {
  ActionErrorStore,
  type ActionErrorSource,
} from './connection/ActionErrorStore.js'
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
