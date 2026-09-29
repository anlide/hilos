// The code an authenticator app would show (HIL-494): RFC 6238 over a base32
// secret, computed in the spec the way any app computes it — HMAC-SHA1, six
// digits, thirty-second steps — so a spec connects and uses an app without one.
import { createHmac } from 'node:crypto'

/** Seconds one code lives. */
const STEP_SECONDS = 30

/**
 * Steps either side of the server's clock a code is still accepted for —
 * `Totp::WINDOW_STEPS` in `framework/backend/Auth/SecondFactor/Totp.php`. Node
 * cannot read a PHP constant, so a change there is carried here by hand.
 */
const WINDOW_STEPS = 1

/** Digits of a code. */
const DIGITS = 6

/** The base32 alphabet (RFC 4648). */
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

/**
 * The bytes a base32 secret stands for.
 *
 * @param secret The secret as the enrolment screen prints it.
 */
function base32Bytes(secret: string): Buffer {
  let bits = ''
  for (const char of secret.replace(/[\s=]/g, '').toUpperCase()) {
    const value = BASE32.indexOf(char)
    if (value < 0) {
      throw new Error(`not a base32 character: ${char}`)
    }
    bits += value.toString(2).padStart(5, '0')
  }
  const bytes: number[] = []
  for (let at = 0; at + 8 <= bits.length; at += 8) {
    bytes.push(Number.parseInt(bits.slice(at, at + 8), 2))
  }

  return Buffer.from(bytes)
}

/**
 * The time step a moment falls in.
 *
 * @param moment Epoch ms; now by default.
 */
export function totpStep(moment: number = Date.now()): number {
  return Math.floor(moment / 1000 / STEP_SECONDS)
}

/**
 * The code of one time step.
 *
 * @param secret The base32 secret.
 * @param step The time step.
 */
export function totpCode(secret: string, step: number = totpStep()): string {
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
 * @param secret The base32 secret.
 * @param spent The step last accepted.
 * @returns The next code and the step it belongs to.
 */
export async function nextTotpCode(
  secret: string,
  spent: number,
): Promise<{ code: string; step: number }> {
  const step = Math.max(spent + 1, totpStep())
  while (step - totpStep() > WINDOW_STEPS) {
    await new Promise((resolve) => setTimeout(resolve, 500))
  }

  return { code: totpCode(secret, step), step }
}
