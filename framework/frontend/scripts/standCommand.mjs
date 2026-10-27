// The portable command endpoint of the current stand. A shared wrapper may keep
// an explicit cluster host while using the same validated command port.

import process from 'node:process'

import { createCommandChannel } from './commandChannel.mjs'

/** Invalid configuration: no command was sent, so there is no outcome to re-ask. */
export class StandCommandEndpointError extends Error {}

/**
 * Returns a request function that reads the stand address only when called.
 *
 * @param {object} options
 * @param {number} options.timeoutMs How long one reply may take.
 * @param {(message: string) => Error} [options.refuse] Builds a refusal error.
 * @param {string} [options.host] Explicit cluster host instead of COMMAND_HOST.
 * @returns {(command: string, payload: Record<string, unknown>) => Promise<Record<string, unknown>>}
 * @throws {StandCommandEndpointError} At the call, when COMMAND_HOST or COMMAND_PORT is absent or invalid.
 */
export function createStandCommandChannel({ timeoutMs, refuse, host }) {
  return async function send(command, payload) {
    const commandHost = host ?? requiredEnv('COMMAND_HOST')
    const portText = requiredEnv('COMMAND_PORT')
    const port = Number(portText)
    if (
      !/^\d+$/.test(portText) ||
      !Number.isInteger(port) ||
      port < 1 ||
      port > 65535
    ) {
      throw new StandCommandEndpointError(
        'COMMAND_PORT must be an integer TCP port from 1 to 65535',
      )
    }

    return createCommandChannel({
      host: commandHost,
      port,
      timeoutMs,
      refuse,
    })(command, payload)
  }
}

/**
 * Reads a required stand address component without inventing a fallback.
 *
 * @param {string} name Environment variable to read.
 * @returns {string} Its nonempty value.
 * @throws {StandCommandEndpointError} When the variable is missing or empty.
 */
function requiredEnv(name) {
  const value = process.env[name]
  if (value === undefined || value.trim() === '') {
    throw new StandCommandEndpointError(
      `${name} is not set: the e2e runner names its stand command endpoint`,
    )
  }

  return value
}
