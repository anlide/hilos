// The engine's shape, judged under its own path and under a neighbor's: the
// engine is left out whole, by exact path, so the neighbor reports both sites.

/** A construction the engine could hold. */
export function formatNumber(value: number): string {
  return new Intl.NumberFormat('en-US').format(value)
}

/** A numeric toLocaleString the engine could hold. */
export function formatCompact(value: number): string {
  return value.toLocaleString()
}
