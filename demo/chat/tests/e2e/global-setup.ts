/**
 * Waits for the app to be reachable before running tests.
 * Frontend container may still be building when E2E stack starts.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL || 'http://localhost:5174'
  const timeout = 120_000
  const interval = 2000
  const start = Date.now()

  while (Date.now() - start < timeout) {
    try {
      const res = await fetch(baseURL, { method: 'HEAD' })
      if (res.ok) return
    } catch {
      // Not ready yet
    }
    await new Promise((r) => setTimeout(r, interval))
  }

  throw new Error(`App at ${baseURL} did not become ready within ${timeout}ms`)
}

export default globalSetup
