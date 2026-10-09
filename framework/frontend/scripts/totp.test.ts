import { describe, expect, it } from 'vitest'

import { totpCode, totpStep } from './totp.mjs'

describe('totp', () => {
  it('computes RFC 6238 vector code 287082 at step 1 for moment 59000 ms', () => {
    const secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
    const step = totpStep(59_000)
    expect(step).toBe(1)
    expect(totpCode(secret, step)).toBe('287082')
  })

  it('computes totpStep across step boundaries', () => {
    expect(totpStep(0)).toBe(0)
    expect(totpStep(29_999)).toBe(0)
    expect(totpStep(30_000)).toBe(1)
    expect(totpStep(59_999)).toBe(1)
    expect(totpStep(60_000)).toBe(2)
  })
})
