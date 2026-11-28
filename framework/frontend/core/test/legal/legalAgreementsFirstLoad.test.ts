// The legal agreements module as the first product module of a fresh module
// graph — the way a Playwright worker meets it when its first spec imports
// legal/legalAgreements.js directly. Nothing the module reaches while it is
// still evaluating may read its exports back: Playwright's loader throws on
// such a read, this runner hands the reader undefined.
import { describe, expect, it } from 'vitest'
import { legalDocumentSchema } from '../../src/legal/legalAgreements.js'
import { readHilosAccountStanding } from '../../src/session/sessionScope.js'

describe('legal agreements loaded first', () => {
  it('leaves the session able to read the lapsed document of a frozen standing', () => {
    const lapse = {
      document: legalDocumentSchema.enum.privacy,
      deadline: '2026-10-09',
    }

    const standing = readHilosAccountStanding({
      shown: 'frozen',
      blocked: false,
      frozen: true,
      deletionEffectiveAt: null,
      lapsed: [lapse],
      window: [],
      mergedInto: null,
      mergedIntoName: null,
    })

    expect(standing?.lapsed).toEqual([lapse])
  })
})
