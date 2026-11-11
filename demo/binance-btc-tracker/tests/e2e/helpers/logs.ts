// The log commands of binance-btc-tracker's cluster and admin specs. The
// transport — append, rotate, the marker and the followed stream — lives in the
// framework's node-side standLogs.mjs, and the cap on a tail's arrival in the
// framework e2e toolbox; every demo's spec reaches them there. This binding keeps
// the names these specs already import, and each cluster caller names its node
// explicitly.
export {
  appendLogLines,
  FOLLOWED_STREAM,
  logMarker,
  rotateLogs,
} from '../../../../../framework/frontend/scripts/standLogs.mjs'
export { TAIL_ARRIVAL_TIMEOUT_MS } from '../../../../../framework/frontend/e2e/index.js'
