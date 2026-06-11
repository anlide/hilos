// @hilos/react — the React view layer of the Hilos frontend SDK.
//
// A thin adapter over @hilos/core: it subscribes React to the core stores via
// useSyncExternalStore and wraps the core selectors as hooks. It holds no
// protocol, store, or table logic of its own
// (docs/agents/frontend/multiframework-core.md). Fills in across rewrite
// step 7, tracking each core capability as it lands; shipped so far: the
// connection-state hook, the core-signal bridge, and the entity resolver.

export { useConnectionState } from './useConnectionState.js'
export { useSignal } from './useSignal.js'
export { useEntity } from './useEntity.js'
