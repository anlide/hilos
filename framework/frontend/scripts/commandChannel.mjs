// One command-channel request/reply round trip for the demo e2e suites. The
// caller keeps the demo-specific address, command names, reply window and
// refusal type; this module owns only the newline-delimited socket mechanics.
//
// Nothing here drives a browser or imports Playwright. Keeping this node:net
// utility beside the other frontend scripts gives the shared mechanic a unit
// test without turning the e2e toolbox into a node-side grab bag.

import { randomBytes } from 'node:crypto'
import net from 'node:net'
import { clearTimeout, setTimeout } from 'node:timers'

/**
 * Binds one command-channel address and returns its request function.
 *
 * @param {object} options
 * @param {string} options.host Command-channel host.
 * @param {number} options.port Command-channel port.
 * @param {number} options.timeoutMs How long one reply may take.
 * @param {(message: string) => Error} [options.refuse] Builds a refusal error.
 * @returns {(command: string, payload: Record<string, unknown>) => Promise<Record<string, unknown>>}
 */
export function createCommandChannel({
  host,
  port,
  timeoutMs,
  refuse = (message) => new Error(message),
}) {
  return function sendCommand(command, payload) {
    return new Promise((resolve, reject) => {
      const request =
        JSON.stringify({
          correlationId: randomBytes(8).toString('hex'),
          command,
          payload,
        }) + '\n'

      const socket = net.connect(port, host)
      let buffer = ''

      const timer = setTimeout(() => {
        clearTimeout(timer)
        socket.destroy()
        reject(
          new Error(
            `No command-channel reply to ${command} within ${timeoutMs}ms`,
          ),
        )
      }, timeoutMs)

      socket.on('connect', () => {
        socket.write(request)
      })
      socket.on('data', (chunk) => {
        buffer += chunk.toString()
        const newline = buffer.indexOf('\n')
        if (newline === -1) {
          return
        }
        clearTimeout(timer)
        socket.destroy()

        const reply = JSON.parse(buffer.slice(0, newline))
        if (reply.status === 'ok') {
          resolve(reply.payload ?? {})
          return
        }

        const message = reply.payload?.message ?? 'unknown error'
        reject(refuse(`${command} failed: ${message}`))
      })
      socket.on('error', (error) => {
        clearTimeout(timer)
        reject(error)
      })
    })
  }
}
