import https from 'node:https'

const READY_TIMEOUT_MS = 120_000
const POLL_INTERVAL_MS = 2_000

/**
 * Blocks until the test nginx serves the built artifact, so tests never race
 * a stack that `test:e2e-up` brought up moments ago. Plain https instead of a
 * Playwright request fixture: the probe must tolerate the self-signed cert
 * before any browser context exists.
 */
async function globalSetup(): Promise<void> {
  const baseURL = process.env.BASE_URL ?? 'https://localhost:8446'
  const agent = new https.Agent({ rejectUnauthorized: false })
  const deadline = Date.now() + READY_TIMEOUT_MS

  while (Date.now() < deadline) {
    try {
      const status = await new Promise<number>((resolve, reject) => {
        const request = https.request(baseURL, { method: 'HEAD', agent }, (response) => {
          response.resume()
          response.on('end', () => resolve(response.statusCode ?? 0))
        })
        request.on('error', reject)
        request.end()
      })
      if (status >= 200 && status < 400) {
        return
      }
    } catch {
      // Stack still starting; poll again.
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS))
  }

  throw new Error(`App at ${baseURL} did not become ready within ${READY_TIMEOUT_MS}ms`)
}

// Playwright loads this module by the `globalSetup` string path in
// playwright.config.ts and calls the default export — that field only accepts
// a path, so no static import of this symbol can exist and an IDE may report
// the export as unused.
export default globalSetup
