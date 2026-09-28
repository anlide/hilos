import { describe, expect, it, vi } from 'vitest'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import {
  createHilosLegalConsentPreview,
  hilosLegalClauseIcon,
  hilosLegalConsentAcceptance,
  hilosLegalConsentDeviations,
  legalConsentTermsSchema,
  loadHilosLegalConsentTerms,
} from '../../src/legal/legalConsent.js'
import { consentTerms } from './consentFixture.js'

describe('registration consent content', () => {
  it('reads the exact displayed revisions of both documents', () => {
    const terms = legalConsentTermsSchema.parse(consentTerms())
    expect(hilosLegalConsentAcceptance(terms)).toEqual({
      terms: 'terms-v1',
      privacy: 'privacy-v1',
    })
    expect(
      legalConsentTermsSchema.safeParse({ ...terms, form: 'prechecked' })
        .success,
    ).toBe(false)
    expect(
      legalConsentTermsSchema.safeParse({ form: 'checkbox' }).success,
    ).toBe(false)
  })

  it('combines deviations in document and clause order', () => {
    const terms = consentTerms()
    const clause = {
      clauseKey: 'standard.retention',
      standardStatement: 'Standard statement',
      statement: 'Project statement',
      text: 'Project text',
      source: 'deviation' as const,
      direction: 'stricter' as const,
    }
    terms.documents[0]!.clauses = [
      clause,
      { ...clause, source: 'standard', direction: null },
    ]
    terms.documents[1]!.clauses = [
      { ...clause, clauseKey: 'standard.no_sale', direction: 'looser' },
    ]
    expect(
      hilosLegalConsentDeviations(terms).map(({ clauseKey }) => clauseKey),
    ).toEqual(['standard.retention', 'standard.no_sale'])
    expect(hilosLegalConsentDeviations(consentTerms())).toEqual([])
  })

  it('keeps icons thematic and unknown keys harmless', () => {
    expect(hilosLegalClauseIcon('standard.retention')).toBe('bi-clock-history')
    expect(hilosLegalClauseIcon('standard.no_sale')).toBe('bi-cash-coin')
    expect(hilosLegalClauseIcon('future.clause')).toBe('bi-file-earmark-text')
    expect(hilosLegalClauseIcon('toString')).toBe('bi-file-earmark-text')
  })

  it('dispatches an empty public read with its reply schema', async () => {
    const terms = consentTerms()
    const dispatch = vi.fn(() => ({ done: Promise.resolve({ reply: terms }) }))
    expect(
      await loadHilosLegalConsentTerms({
        actions: { dispatch } as unknown as ActionLifecycle,
      }),
    ).toEqual(terms)
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_legal_consent',
      {},
      { replySchema: legalConsentTermsSchema },
    )
  })

  it('refuses a success acknowledgement that contains no documents reply', async () => {
    const actions = {
      dispatch: () => ({ done: Promise.resolve({}) }),
    } as unknown as ActionLifecycle
    await expect(loadHilosLegalConsentTerms({ actions })).rejects.toThrow(
      'The terms could not be loaded.',
    )
  })
})

describe('consent preview reads', () => {
  it('drops a closed read even if it answers after a reopened preview', async () => {
    let resolveOld!: (value: { reply: ReturnType<typeof consentTerms> }) => void
    const dispatch = vi
      .fn()
      .mockReturnValueOnce({
        done: new Promise((resolve) => {
          resolveOld = resolve
        }),
      })
      .mockReturnValueOnce({
        done: Promise.resolve({ reply: consentTerms('terms-v2') }),
      })
    const preview = createHilosLegalConsentPreview({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    const old = preview.open()
    expect(preview.loading.get()).toBe(true)
    preview.close()
    await preview.open()
    resolveOld({ reply: consentTerms() })
    await old
    expect(preview.terms.get()?.documents[0]?.revision.revisionId).toBe(
      'terms-v2',
    )
    expect(preview.opened.get()).toBe(true)
    preview.close()
    expect(preview.terms.get()).toBeNull()
  })

  it('reports a failed read inline and permits another read', async () => {
    const dispatch = vi
      .fn()
      .mockReturnValueOnce({ done: Promise.reject(new Error('No reply')) })
      .mockReturnValueOnce({ done: Promise.resolve({ reply: consentTerms() }) })
    const preview = createHilosLegalConsentPreview({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    await preview.open()
    expect(preview.error.get()).toBe('The terms could not be loaded.')
    expect(preview.loading.get()).toBe(false)
    await preview.open()
    expect(preview.error.get()).toBeNull()
    expect(preview.terms.get()).not.toBeNull()
  })
})
