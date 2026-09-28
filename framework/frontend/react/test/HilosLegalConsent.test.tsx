import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { useState } from 'react'
import { type HilosLegalDocumentKey } from '@hilos/core'
import {
  consentTerms,
  consentTermsWithClauses,
} from '../../core/test/legal/consentFixture.js'
import { HilosLegalConsent } from '../src/legal/HilosLegalConsent.js'

afterEach(cleanup)

function Body() {
  const [accepted, setAccepted] = useState(false)
  const [reading, setReading] = useState<HilosLegalDocumentKey | null>(null)
  return (
    <HilosLegalConsent
      terms={consentTermsWithClauses()}
      accepted={accepted}
      reading={reading}
      onAcceptedChange={setAccepted}
      onReadingChange={setReading}
    />
  )
}

describe('React consent body', () => {
  it('keeps the checkbox and expanded standard while reading the full text', () => {
    const { container } = render(<Body />)
    const byId = (id: string) =>
      container.querySelector(`[data-id="${id}"]`) as HTMLElement
    expect(
      container.querySelectorAll('[data-id="legal-consent-deviation"]'),
    ).toHaveLength(4)
    expect(byId('legal-consent-standard-toggle').textContent).toContain(
      '13 clauses',
    )
    fireEvent.click(byId('legal-consent-standard-toggle'))
    expect(
      container.querySelectorAll('[data-id="legal-consent-standard-item"]'),
    ).toHaveLength(13)
    fireEvent.click(byId('auth-consent-accept'))
    fireEvent.click(
      container.querySelector(
        '[data-id="legal-consent-read"][data-document="terms"]',
      )!,
    )
    expect(
      container.querySelectorAll('[data-id="legal-revision-clause"]'),
    ).toHaveLength(6)
    fireEvent.click(byId('legal-consent-back'))
    expect((byId('auth-consent-accept') as HTMLInputElement).checked).toBe(true)
    expect(
      byId('legal-consent-standard-toggle').getAttribute('aria-expanded'),
    ).toBe('true')
  })

  it('renders a plain project honestly and omits the checkbox for the line form', () => {
    const { container } = render(
      <HilosLegalConsent
        terms={consentTerms('terms-v1', 'line')}
        accepted={false}
        reading={null}
        onAcceptedChange={() => {}}
        onReadingChange={() => {}}
      />,
    )
    expect(
      container.querySelector('[data-id="legal-consent-no-deviations"]'),
    ).not.toBeNull()
    expect(
      container.querySelector('[data-id="auth-consent-accept"]'),
    ).toBeNull()
    expect(container.textContent).not.toContain('Below is only')
  })
})
