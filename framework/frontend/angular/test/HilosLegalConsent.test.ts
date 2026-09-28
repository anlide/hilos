import { afterEach, describe, expect, it } from 'vitest'
import { TestBed } from '@angular/core/testing'
import {
  consentTerms,
  consentTermsWithClauses,
} from '../../core/test/legal/consentFixture.js'
import { HilosLegalConsent } from '../src/legal/HilosLegalConsent.js'

afterEach(() => TestBed.resetTestingModule())

describe('Angular consent body', () => {
  it('keeps the expanded standard and acceptance when returning from the full text', async () => {
    await TestBed.configureTestingModule({
      imports: [HilosLegalConsent],
    }).compileComponents()
    const fixture = TestBed.createComponent(HilosLegalConsent)
    fixture.componentRef.setInput('terms', consentTermsWithClauses())
    fixture.componentRef.setInput('accepted', true)
    fixture.componentRef.setInput('reading', null)
    fixture.componentInstance.readingChange.subscribe((reading) =>
      fixture.componentRef.setInput('reading', reading),
    )
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    const byId = (id: string) =>
      root.querySelector(`[data-id="${id}"]`) as HTMLElement
    expect(
      root.querySelectorAll('[data-id="legal-consent-deviation"]'),
    ).toHaveLength(4)
    byId('legal-consent-standard-toggle').click()
    fixture.detectChanges()
    expect(
      root.querySelectorAll('[data-id="legal-consent-standard-item"]'),
    ).toHaveLength(13)
    ;(
      root.querySelector(
        '[data-id="legal-consent-read"][data-document="terms"]',
      ) as HTMLElement
    ).click()
    fixture.detectChanges()
    expect(
      root.querySelectorAll('[data-id="legal-revision-clause"]'),
    ).toHaveLength(6)
    byId('legal-consent-back').click()
    fixture.detectChanges()
    expect((byId('auth-consent-accept') as HTMLInputElement).checked).toBe(true)
    expect(
      byId('legal-consent-standard-toggle').getAttribute('aria-expanded'),
    ).toBe('true')
  })

  it('shows the no-deviations message without a checkbox for the line form', async () => {
    await TestBed.configureTestingModule({
      imports: [HilosLegalConsent],
    }).compileComponents()
    const fixture = TestBed.createComponent(HilosLegalConsent)
    fixture.componentRef.setInput('terms', consentTerms('terms-v1', 'line'))
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    expect(
      root.querySelector('[data-id="legal-consent-no-deviations"]'),
    ).not.toBeNull()
    expect(root.querySelector('[data-id="auth-consent-accept"]')).toBeNull()
    expect(root.textContent).not.toContain('Below is only')
  })
})
