import https from 'node:https'

/**
 * Waits for the app to be reachable before running tests.
 * Frontend container may still be starting when E2E stack starts.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL || 'https://localhost:443'
  const timeout = 120_000
  const interval = 2000
  const start = Date.now()
  const agent = new https.Agent({ rejectUnauthorized: false })

  while (Date.now() - start < timeout) {
    try {
      const res = await new Promise<{ statusCode?: number }>((resolve, reject) => {
        const req = https.request(baseURL, { method: 'HEAD', agent }, (res) => {
          res.resume()
          res.on('end', () => resolve(res))
        })
        req.on('error', reject)
        req.end()
      })
      if (res.statusCode && res.statusCode >= 200 && res.statusCode < 400) return
    } catch {
      // Not ready yet
    }
    await new Promise((r) => setTimeout(r, interval))
  }

  throw new Error(`App at ${baseURL} did not become ready within ${timeout}ms`)
}

export default globalSetup
