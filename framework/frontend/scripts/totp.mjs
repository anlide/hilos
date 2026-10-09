// The code an authenticator app would show (HIL-494): RFC 6238 over a base32
// secret, computed the way any app computes it — HMAC-SHA1, six digits,
// thirty-second steps — so a spec or script connects and uses an app without one.

import { Buffer } from 'node:buffer'
import { createHmac } from 'node:crypto'
import { setTimeout } from 'node:timers'

/** Seconds one code lives. */
const STEP_SECONDS = 30

/**
 * Steps either side of the server's clock a code is still accepted for —
 * `Totp::WINDOW_STEPS` in `framework/backend/Auth/SecondFactor/Totp.php`. Node
 * cannot read a PHP constant, so a change there is carried here by hand.
 */
export const WINDOW_STEPS = 1

/** Digits of a code. */
const DIGITS = 6

/** The base32 alphabet (RFC 4648). */
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

/**
 * The bytes a base32 secret stands for.
 *
 * @param {string} secret The secret as the enrolment screen prints it.
 * @returns {Buffer}
 */
function base32Bytes(secret) {
  let bits = ''
  for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
    const value = BASE32.indexOf(char)
    if (value < 0) {
      throw new Error(`not a base32 character: ${char}`)
    }
    bits += value.toString(2).padStart(5, '0')
  }
  const bytes = []
  for (let at = 0; at + 8 <= bits.length; at += 8) {
    bytes.push(Number.parseInt(bits.slice(at, at + 8), 2))
  }

  return Buffer.from(bytes)
}

/**
 * The time step a moment falls in.
 *
 * @param {number} [moment] Epoch ms; now by default.
 * @returns {number}
 */
export function totpStep(moment = Date.now()) {
  return Math.floor(moment / 1000 / STEP_SECONDS)
}

/**
 * The code of one time step.
 *
 * @param {string} secret The base32 secret.
 * @param {number} [step] The time step.
 * @returns {string}
 */
export function totpCode(secret, step = totpStep()) {
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(step))
  const hash = createHmac('sha1', base32Bytes(secret)).update(counter).digest()
  const offset = (hash[hash.length - 1] ?? 0) & 0x0f
  const binary = hash.readUInt32BE(offset) & 0x7fffffff

  return String(binary % 10 ** DIGITS).padStart(DIGITS, '0')
}

/**
 * The code of the first time step after one already spent.
 *
 * The server takes a step of an app once (`acceptStep()` in
 * `framework/backend/Database/Object/Item/SecondFactor.php`) and accepts a code
 * `WINDOW_STEPS` steps either side of its own clock (`Totp::verify()`). So the
 * step after the spent one is taken at once, even while the clock still stands
 * in the spent one: it is the code of a phone whose clock runs fast. This waits
 * only when the step wanted is further ahead than the server reaches — a code
 * needed for the second time inside one window.
 *
 * @param {string} secret The base32 secret.
 * @param {number} spent The step last accepted.
 * @returns {Promise<{ code: string, step: number }>} The next code and the step it belongs to.
 */
export async function nextTotpCode(secret, spent) {
  const step = Math.max(spent + 1, totpStep())
  while (step - totpStep() > WINDOW_STEPS) {
    await new Promise((resolve) => setTimeout(resolve, 500))
  }

  return { code: totpCode(secret, step), step }
}
