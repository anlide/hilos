import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  consentTerms,
  consentTermsWithClauses,
} from '../../../core/test/legal/consentFixture.js'
import HilosLegalConsent from './HilosLegalConsent.vue'

describe('the registration consent body', () => {
  it('folds thirteen standard clauses and unfolds all four differences with direction and provenance', async () => {
    const view = mount(HilosLegalConsent, {
      props: {
        terms: consentTermsWithClauses(),
        accepted: false,
        reading: null,
      },
    })
    expect(
      view.get('[data-id="legal-consent-standard-toggle"]').text(),
    ).toContain('13 clauses')
    expect(
      view.findAll('[data-id="legal-consent-standard-item"]'),
    ).toHaveLength(0)
    expect(view.findAll('[data-id="legal-consent-deviation"]')).toHaveLength(4)
    expect(
      view
        .findAll('[data-id="legal-consent-direction"]')
        .map((item) => item.text()),
    ).toEqual(['stricter', 'stricter', 'stricter', 'looser'])
    expect(view.text()).toContain('Hilos standard: Standard retention')
    expect(
      (view.get('[data-id="auth-consent-accept"]').element as HTMLInputElement)
        .checked,
    ).toBe(false)
    await view.get('[data-id="legal-consent-standard-toggle"]').trigger('click')
    expect(
      view
        .get('[data-id="legal-consent-standard-toggle"]')
        .attributes('aria-expanded'),
    ).toBe('true')
    expect(
      view.findAll('[data-id="legal-consent-standard-item"]'),
    ).toHaveLength(13)
    view.unmount()
  })

  it('reads the received full text and preserves the checkbox and fold on return', async () => {
    const view = mount(HilosLegalConsent, {
      props: {
        terms: consentTermsWithClauses(),
        accepted: true,
        reading: null,
      },
    })
    await view.get('[data-id="legal-consent-standard-toggle"]').trigger('click')
    await view
      .get('[data-id="legal-consent-read"][data-document="terms"]')
      .trigger('click')
    expect(view.emitted('update:reading')?.[0]).toEqual(['terms'])
    await view.setProps({ reading: 'terms' })
    expect(view.findAll('[data-id="legal-revision-clause"]')).toHaveLength(6)
    expect(
      view.findAll('[data-id="legal-revision-clause-deviation"]'),
    ).toHaveLength(3)
    await view.get('[data-id="legal-consent-back"]').trigger('click')
    expect(view.emitted('update:reading')?.[1]).toEqual([null])
    await view.setProps({ reading: null })
    expect(
      view
        .get('[data-id="legal-consent-standard-toggle"]')
        .attributes('aria-expanded'),
    ).toBe('true')
    expect(
      (view.get('[data-id="auth-consent-accept"]').element as HTMLInputElement)
        .checked,
    ).toBe(true)
    view.unmount()
  })

  it('states that there are no deviations and has no checkbox in the line form', async () => {
    const view = mount(HilosLegalConsent, {
      props: { terms: consentTerms(), accepted: false, reading: null },
    })
    expect(
      view.get('[data-id="legal-consent-no-deviations"]').text(),
    ).toContain('neither stricter nor looser')
    expect(view.text()).not.toContain('Below is only')
    expect(view.text()).toContain('I accept the terms')
    await view.get('[data-id="auth-consent-accept"]').setValue(true)
    expect(view.emitted('update:accepted')?.[0]).toEqual([true])
    await view.setProps({ terms: consentTerms('terms-v1', 'line') })
    expect(view.find('[data-id="auth-consent-accept"]').exists()).toBe(false)
    view.unmount()
  })
})
