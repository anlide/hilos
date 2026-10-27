import { Buffer } from 'node:buffer'
import https from 'node:https'
import { setTimeout } from 'node:timers'

export const READY_TIMEOUT_MS = 120_000
const POLL_INTERVAL_MS = 2_000

/**
 * @param {string} baseURL Front origin.
 * @param {https.Agent} agent Agent tolerating its test certificate.
 * @returns {Promise<boolean>} Whether the built artifact answers successfully.
 */
export function probeStatic(baseURL, agent) {
  return new Promise((resolve) => {
    const request = https.request(
      baseURL,
      { method: 'HEAD', agent },
      (response) => {
        response.resume()
        response.on('end', () => {
          const status = response.statusCode ?? 0
          resolve(status >= 200 && status < 400)
        })
      },
    )
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * @param {string} baseURL Front origin.
 * @param {https.Agent} agent Agent tolerating its test certificate.
 * @param {string} [master] Optional master selected through the cluster stand entry.
 * @returns {Promise<boolean>} Whether the daemon answers the upgrade.
 */
export function probeWebSocketUpgrade(baseURL, agent, master) {
  return new Promise((resolve) => {
    const request = https.request(`${baseURL}/ws`, {
      agent,
      headers: {
        ...(master ? { Cookie: `hilos_stand_master=${master}` } : {}),
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13',
        'Sec-WebSocket-Key': Buffer.from('hilos-e2e-ready!').toString('base64'),
      },
    })
    request.on('upgrade', (response, socket) => {
      socket.destroy()
      resolve(response.statusCode === 101)
    })
    request.on('response', (response) => {
      response.resume()
      resolve(false)
    })
    request.on('error', () => resolve(false))
    request.end()
  })
}

/**
 * Poll one front until its static or WebSocket response arrives.
 *
 * @param {string} what Failure-message label for the probed surface.
 * @param {number} deadline Epoch milliseconds after which readiness fails.
 * @param {() => Promise<boolean>} probe One probe attempt.
 * @returns {Promise<void>}
 */
export async function waitUntilReady(what, deadline, probe) {
  while (Date.now() < deadline) {
    if (await probe()) {
      return
    }
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS))
  }
  throw new Error(`${what} did not become ready within ${READY_TIMEOUT_MS}ms`)
}
