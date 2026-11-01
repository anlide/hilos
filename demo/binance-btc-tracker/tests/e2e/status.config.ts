import {
  deriveTimeoutScale,
  readHostPressure,
} from '../../../../framework/frontend/scripts/timeout-scale.mjs'

// The ceiling of a Status wait, not the time it is meant to take: a spec brings
// the Daemon node report to now with test:daemon:node:report (reportDaemonNode,
// reportDaemonNodeUntil) rather than waiting
// DaemonNodeAgent::CHANGE_INTERVAL_SECONDS (5s) out. The figure is the chain it
// was sized on (HIL-1856): DaemonManager::PROCESS_ROSTER_COALESCE_SECONDS
// (0.5s), that interval, and DaemonCollectorAgent::FANOUT_WINDOW_SECONDS
// (0.5s), then 2s for scheduling and delivery. This is a test convergence
// budget, not a product SLA.
const BASE_STATUS_EXPECT_TIMEOUT_MS = (0.5 + 5 + 0.5 + 2) * 1000
const { factor } = deriveTimeoutScale({
  override: process.env.HILOS_E2E_TIMEOUT_SCALE,
  ...readHostPressure(),
})

export const STATUS_EXPECT_TIMEOUT_MS = BASE_STATUS_EXPECT_TIMEOUT_MS * factor
