// @hilos/angular — the Angular view layer of the Hilos frontend SDK.
//
// A thin adapter over @hilos/core: it bridges the core stores into Angular
// signals and effects. It holds no protocol, store, or table logic of its own
// (docs/agents/frontend/multiframework-core.md). Fills in across rewrite
// step 7, tracking each core capability as it lands; shipped so far: the
// connection-state signal, the core-signal bridge, and the entity resolver.

export {
  connectionStateSignal,
  type ConnectionStateSignalOptions,
} from './connectionStateSignal.js'
export { hilosSignal, type HilosSignalOptions } from './hilosSignal.js'
export { entitySignal } from './entitySignal.js'
