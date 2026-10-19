import { describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
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
  it('keeps arbitrary server words verbatim', () => {
    const terms = legalConsentTermsSchema.parse({
      ...consentTerms(),
      unpublishedMessage: ' Publication is paused.\nReturn later. ',
      revisedMessage: ' A new revision is ready.\nReview it. ',
    })
    expect(terms.unpublishedMessage).toBe(
      ' Publication is paused.\nReturn later. ',
    )
    expect(terms.revisedMessage).toBe(' A new revision is ready.\nReview it. ')
  })

  describe.each(['unpublishedMessage', 'revisedMessage'])(
    'required %s',
    (field) => {
      it('rejects an absent key', () => {
        const payload: Record<string, unknown> = { ...consentTerms() }
        delete payload[field]
        expect(legalConsentTermsSchema.safeParse(payload).success).toBe(false)
      })

      it.each([undefined, null, false, 42, 1.5, [], {}])(
        'rejects a non-string value %j',
        (value) => {
          expect(
            legalConsentTermsSchema.safeParse({
              ...consentTerms(),
              [field]: value,
            }).success,
          ).toBe(false)
        },
      )
    },
  )

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
    const terms = {
      ...consentTerms(),
      unpublishedMessage: 'Publication is paused for this installation.',
      revisedMessage: 'This installation published new terms.',
    }
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
  it.each(['timeout', 'disconnected', 'invalid-reply'] as const)(
    'uses the local fallback for %s without exposing technical details',
    async (outcome) => {
      const dispatch = vi.fn(() => ({
        done: Promise.reject(
          new ActionError('hilos_legal_consent', outcome, 'Technical detail'),
        ),
      }))
      const preview = createHilosLegalConsentPreview({
        actions: { dispatch } as unknown as ActionLifecycle,
      })
      await preview.open()
      expect(preview.error.get()).toBe('The terms could not be loaded.')
      expect(preview.loading.get()).toBe(false)
      expect(preview.terms.get()).toBeNull()
    },
  )

  it('uses the local fallback when a success has no reply', async () => {
    const preview = createHilosLegalConsentPreview({
      actions: {
        dispatch: () => ({ done: Promise.resolve({}) }),
      } as unknown as ActionLifecycle,
    })
    await preview.open()
    expect(preview.error.get()).toBe('The terms could not be loaded.')
    expect(preview.loading.get()).toBe(false)
  })

  it.each([false, true])(
    'drops an old refusal after close, reopened=%s',
    async (reopened) => {
      let rejectOld!: (reason: unknown) => void
      const terms = {
        ...consentTerms('terms-v2'),
        unpublishedMessage: 'Current unpublished words.',
        revisedMessage: 'Current revised words.',
      }
      const dispatch = vi
        .fn()
        .mockReturnValueOnce({
          done: new Promise((_, reject) => {
            rejectOld = reject
          }),
        })
        .mockReturnValueOnce({ done: Promise.resolve({ reply: terms }) })
      const preview = createHilosLegalConsentPreview({
        actions: { dispatch } as unknown as ActionLifecycle,
      })
      const old = preview.open()
      preview.close()
      if (reopened) await preview.open()
      rejectOld(new ActionError('hilos_legal_consent', 'fail', 'Old refusal'))
      await old
      expect(preview.error.get()).toBeNull()
      expect(preview.loading.get()).toBe(false)
      expect(preview.opened.get()).toBe(reopened)
      expect(preview.terms.get()).toEqual(reopened ? terms : null)
    },
  )

  it('preserves the server refusal even for a familiar code', async () => {
    const dispatch = vi.fn(() => ({
      done: Promise.reject(
        new ActionError(
          'hilos_legal_consent',
          'fail',
          'Publication is paused by this project.',
          undefined,
          undefined,
          'terms_unpublished',
        ),
      ),
    }))
    const preview = createHilosLegalConsentPreview({
      actions: { dispatch } as unknown as ActionLifecycle,
    })
    await preview.open()
    expect(preview.error.get()).toBe('Publication is paused by this project.')
    expect(preview.terms.get()).toBeNull()
    expect(preview.loading.get()).toBe(false)
  })

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
