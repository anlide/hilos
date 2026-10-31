import { flushPromises } from '@vue/test-utils'
import { HIDDEN_VALUE, HilosPages } from '@hilos/core/base'
import { hilosLegalLapsedHref } from '@hilos/core/admin/legal'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { consentTermsWithClauses } from '../../../../core/test/legal/consentFixture.js'
import { reconsentPreview } from '../../../../core/test/legal/reconsentFixture.js'
import {
  documentHarness,
  legalDocument,
  revision,
} from '../../../test/legalDocumentFixture.js'
import HilosLegalDocumentPage from './HilosLegalDocumentPage.vue'

describe('Document Overview', () => {
  it('shows server aggregates and sources, keeps the adopted set and carries only settings', async () => {
    const h = documentHarness(HilosLegalDocumentPage)
    try {
      await nextTick()
      expect(h.registered).toEqual(new Set(['hilosLegalSettings']))
      expect(h.view.get('[data-id="legal-count-covered"]').text()).toBe('21')
      expect(h.view.get('[data-id="legal-count-window"]').text()).toBe('7')
      expect(h.view.get('[data-id="legal-count-lapsed"]').text()).toBe('3')
      expect(
        h.view.get('[data-id="legal-count-lapsed-link"]').attributes('href'),
      ).toBe(hilosLegalLapsedHref('terms'))
      expect(h.view.get('[data-id="legal-document-sources"]').text()).toContain(
        legalDocument.standardSetCatalogClass,
      )
      expect(h.view.get('[data-id="legal-document-sources"]').text()).toContain(
        legalDocument.projectCatalogClass,
      )
      expect(h.view.get('[data-id="legal-set-newer"]').text()).toContain(
        'Retention clarified',
      )
      expect(h.view.get('[data-id="legal-document-set-version"]').text()).toBe(
        'Set 1',
      )
      expect(h.view.find('table').exists()).toBe(false)
      expect(h.view.text()).not.toContain('The project retains its messages.')
      h.scope.data.set('legalDocument', {
        ...legalDocument,
        covered: 0,
        window: 0,
        lapsed: 0,
        projectCatalogClass: null,
      })
      await nextTick()
      for (const key of ['covered', 'window', 'lapsed'])
        expect(h.view.get(`[data-id="legal-count-${key}"]`).text()).toBe('0')
      expect(h.view.find('[data-id="legal-count-lapsed-link"]').exists()).toBe(
        false,
      )
      expect(
        h.view.get('[data-id="legal-document-sources"]').text(),
      ).not.toContain(legalDocument.projectCatalogClass)
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('changes the live lapsed caption and uses neutral words for a hidden viewer policy', async () => {
    const h = documentHarness(HilosLegalDocumentPage)
    try {
      for (const [value, label] of [
        ['freeze', 'Frozen'],
        ['remind', 'Past deadline'],
        [HIDDEN_VALUE, 'Past deadline'],
      ] as const) {
        h.policy(value)
        await nextTick()
        expect(h.view.get('[data-id="legal-document-lapsed"] h3').text()).toBe(
          label,
        )
        expect(h.view.text()).not.toContain('Freeze access')
        expect(h.view.text()).not.toContain('Keep reminding')
        expect(h.view.find('table').exists()).toBe(false)
      }
    } finally {
      h.view.unmount()
    }
  })

  it('opens the existing read-only re-consent and registration previews and frees them on departure', async () => {
    const h = documentHarness(HilosLegalDocumentPage)
    h.dispatch.mockImplementation((name: string) => ({
      done: Promise.resolve({
        reply:
          name === 'hilos_legal_consent'
            ? consentTermsWithClauses()
            : {
                ...reconsentPreview('window'),
              },
      }),
    }))
    try {
      await h.view.get('[data-id="legal-preview-consent"]').trigger('click')
      await flushPromises()
      expect(
        document.querySelector('[data-id="legal-consent-preview"]'),
      ).not.toBeNull()
      expect(document.querySelector('[data-id="auth-submit"]')).toBeNull()
      ;(
        document.querySelector(
          '[data-id="legal-consent-preview-close"]',
        ) as HTMLElement
      ).click()
      await flushPromises()
      await h.view.get('[data-id="legal-preview-reconsent"]').trigger('click')
      await flushPromises()
      expect(
        document.querySelector('[data-id="legal-reconsent-preview"]'),
      ).not.toBeNull()
      expect(
        h.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
      ).toEqual([
        ['hilos_legal_consent', {}],
        ['hilos_legal_reconsent_preview', { document: 'terms' }],
      ])
      expect(
        document
          .querySelector('[data-id="legal-reconsent-accept"]')
          ?.hasAttribute('disabled'),
      ).toBe(true)
    } finally {
      h.view.unmount()
    }
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it.each([
    ['consent', 'legal-consent-preview-retry', 'legal-consent-preview-close'],
    ['reconsent', 'legal-reconsent-retry', 'legal-reconsent-preview-close'],
  ])(
    'retries and closes the read-only %s preview',
    async (kind, retry, close) => {
      const h = documentHarness(HilosLegalDocumentPage)
      h.dispatch
        .mockReturnValueOnce({ done: Promise.reject(new Error('Unavailable')) })
        .mockReturnValueOnce({
          done: Promise.resolve({
            reply:
              kind === 'consent'
                ? consentTermsWithClauses()
                : reconsentPreview('window'),
          }),
        })
      try {
        await h.view.get(`[data-id="legal-preview-${kind}"]`).trigger('click')
        await flushPromises()
        ;(document.querySelector(`[data-id="${retry}"]`) as HTMLElement).click()
        await flushPromises()
        expect(
          h.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
        ).toEqual(
          kind === 'consent'
            ? [
                ['hilos_legal_consent', {}],
                ['hilos_legal_consent', {}],
              ]
            : [
                ['hilos_legal_reconsent_preview', { document: 'terms' }],
                ['hilos_legal_reconsent_preview', { document: 'terms' }],
              ],
        )
        expect(document.querySelector(`[data-id="${retry}"]`)).toBeNull()
        ;(document.querySelector(`[data-id="${close}"]`) as HTMLElement).click()
        await flushPromises()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
      } finally {
        h.view.unmount()
      }
    },
  )

  it('ignores a preview reply that arrives after leaving the page', async () => {
    const h = documentHarness(HilosLegalDocumentPage, HilosPages.LEGAL_DOCUMENT)
    let reply!: (value: unknown) => void
    h.dispatch.mockReturnValue({
      done: new Promise((resolve) => {
        reply = resolve
      }),
    })
    await h.view.get('[data-id="legal-preview-reconsent"]').trigger('click')
    h.view.unmount()
    reply({
      reply: {
        document: 'terms',
        standing: null,
        deadline: null,
        held: null,
        current: revision,
        changes: [],
        clauses: [],
      },
    })
    await flushPromises()
    expect(
      document.querySelector('[data-id="legal-reconsent-preview"]'),
    ).toBeNull()
  })
})
