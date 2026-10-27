import {
  deriveTimeoutScale,
  readHostPressure,
} from '../../../../framework/frontend/scripts/timeout-scale.mjs'

// DaemonManager::PROCESS_ROSTER_COALESCE_SECONDS (0.5s),
// DaemonNodeAgent::CHANGE_INTERVAL_SECONDS (5s), and
// DaemonCollectorAgent::FANOUT_WINDOW_SECONDS (0.5s), then two browser polls
// from docker-compose.e2e-status-fast.yml (2s each) and 2s for scheduling and
// delivery. This is a test convergence budget, not a product SLA.
const BASE_STATUS_EXPECT_TIMEOUT_MS = (0.5 + 5 + 0.5 + 2 * 2 + 2) * 1000
const { factor } = deriveTimeoutScale({
  override: process.env.HILOS_E2E_TIMEOUT_SCALE,
  ...readHostPressure(),
})

export const STATUS_EXPECT_TIMEOUT_MS = BASE_STATUS_EXPECT_TIMEOUT_MS * factor
