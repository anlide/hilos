// Keep the demo's readiness API while both cluster stands use one implementation.
export {
  READY_TIMEOUT_MS,
  probeStatic,
  probeWebSocketUpgrade,
  waitUntilReady,
} from '../../../../../framework/frontend/e2e/cluster.js'
