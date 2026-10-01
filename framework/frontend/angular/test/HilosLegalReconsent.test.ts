import { afterEach, describe, expect, it } from 'vitest'
import { TestBed } from '@angular/core/testing'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../core/test/legal/reconsentFixture.js'
import { HilosLegalReconsent } from '../src/legal/HilosLegalReconsent.js'

afterEach(() => TestBed.resetTestingModule())

const PERSON = { name: 'Bob', impersonated: false }

/**
 * Draw the screen with the inputs given and hand back finders by data-id.
 *
 * @param inputs The component's inputs.
 */
async function draw(inputs: Record<string, unknown>) {
  await TestBed.configureTestingModule({
    imports: [HilosLegalReconsent],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalReconsent)
  for (const [name, value] of Object.entries(inputs)) {
    fixture.componentRef.setInput(name, value)
  }
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement

  return {
    fixture,
    one: (id: string): HTMLElement | null =>
      root.querySelector(`[data-id="${id}"]`),
    all: (id: string): HTMLElement[] =>
      Array.from(root.querySelectorAll(`[data-id="${id}"]`)),
  }
}

describe('Angular "the terms have changed" screen (HIL-500)', () => {
  it('draws a section per document with its plate, the accepted revision and the changes', async () => {
    const { one, all } = await draw({
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: PERSON,
      now: RECONSENT_NOW,
    })
    expect(
      all('legal-reconsent-document').map((item) =>
        item.getAttribute('data-document'),
      ),
    ).toEqual(['terms', 'privacy'])
    const plates = all('legal-reconsent-badge')
    expect(plates[0]!.textContent).toContain('9 days left')
    expect(plates[0]!.textContent).toContain('until 10 November 2026')
    expect(plates[1]!.textContent).toContain(
      'deadline passed 27 September 2026',
    )
    expect(
      all('legal-reconsent-change-kind').map((item) =>
        item.textContent?.trim(),
      ),
    ).toEqual(['Changed', 'Changed', 'Added'])
    expect(all('legal-reconsent-change')[1]!.textContent).toContain(
      'Before: No promise of uptime',
    )
    expect(one('legal-reconsent-person')?.textContent).toContain('Bob')
    expect(one('legal-reconsent-exits')).toBeNull()
  })

  it('reports the views asked for and draws them with a way back', async () => {
    const { fixture, one, all } = await draw({
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      now: RECONSENT_NOW,
    })
    const shown: unknown[] = []
    fixture.componentInstance.show.subscribe((view) => shown.push(view))
    all('legal-reconsent-full-text')[0]!.click()
    expect(shown).toEqual([{ kind: 'text', document: 'terms' }])
    fixture.componentRef.setInput('view', {
      kind: 'compare',
      document: 'terms',
    })
    fixture.detectChanges()
    expect(one('legal-changes')).not.toBeNull()
    expect(one('legal-reconsent-document')).toBeNull()
    one('legal-reconsent-back')!.click()
    expect(shown.at(-1)).toEqual({ kind: 'changes' })
  })

  it('words the refusal step by the setting and closes it like Later', async () => {
    const { fixture, one } = await draw({
      variant: 'window',
      content: reconsentContent('remind'),
      view: { kind: 'refuse' },
    })
    let later = 0
    fixture.componentInstance.later.subscribe(() => later++)
    expect(one('legal-reconsent-refuse-step')?.textContent).toContain(
      'Nothing changes: this reminder will keep coming back.',
    )
    expect(one('legal-reconsent-delete-link')).not.toBeNull()
    expect(one('legal-reconsent-accept')).toBeNull()
    one('legal-reconsent-close')!.click()
    expect(later).toBe(1)
  })

  it('holds Accept while nothing is read and shows a failed read with a retry', async () => {
    const { fixture, one } = await draw({
      variant: 'window',
      content: null,
      view: { kind: 'changes' },
      error: 'The terms could not be loaded.',
    })
    let retried = 0
    fixture.componentInstance.retry.subscribe(() => retried++)
    expect(one('legal-reconsent-accept')?.hasAttribute('disabled')).toBe(true)
    expect(one('legal-reconsent-error')?.textContent).toContain(
      'The terms could not be loaded.',
    )
    one('legal-reconsent-retry')!.click()
    expect(retried).toBe(1)
  })

  it('holds Accept when nothing is left to accept', async () => {
    const { one } = await draw({
      variant: 'frozen',
      content: { ...reconsentContent(), documents: [] },
      view: { kind: 'changes' },
    })
    expect(one('legal-reconsent-accept')?.hasAttribute('disabled')).toBe(true)
  })

  it('keeps only Accept on the freeze and lays the exits out as actions', async () => {
    const { one, all } = await draw({
      variant: 'frozen',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: PERSON,
      deletionScheduled: true,
      now: RECONSENT_NOW,
    })
    expect(one('legal-reconsent-heading')?.textContent?.trim()).toBe(
      'The terms were not accepted',
    )
    expect(one('legal-reconsent-later')).toBeNull()
    expect(one('legal-reconsent-refuse')).toBeNull()
    expect(one('legal-reconsent-accept')).not.toBeNull()
    expect(all('legal-reconsent-badge')[1]!.getAttribute('data-tone')).toBe(
      'info',
    )
    expect(one('legal-reconsent-data-link')).not.toBeNull()
    expect(one('legal-reconsent-keep-account')).not.toBeNull()
    expect(one('legal-reconsent-sign-out')).not.toBeNull()
  })

  it('takes the acceptance away under a takeover and says who may accept', async () => {
    const { one } = await draw({
      variant: 'frozen',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: { name: 'Bob', impersonated: true },
      deletionScheduled: true,
    })
    expect(one('legal-reconsent-accept')).toBeNull()
    expect(one('legal-reconsent-keep-account')).toBeNull()
    expect(one('legal-reconsent-impersonated')?.textContent?.trim()).toBe(
      'Only Bob can accept the terms.',
    )
  })

  it('previews through the previous holder with every button inactive', async () => {
    const plates: (string | undefined)[] = []
    for (const kind of ['window', 'lapsed', 'editorial'] as const) {
      const { one } = await draw({
        variant: 'preview',
        content: reconsentPreview(kind),
        view: { kind: 'changes' },
        now: RECONSENT_NOW,
      })
      for (const button of [
        'legal-reconsent-refuse',
        'legal-reconsent-later',
        'legal-reconsent-accept',
      ]) {
        expect(one(button)?.hasAttribute('disabled')).toBe(true)
      }
      plates.push(
        one('legal-reconsent-badge')?.textContent?.replace(/\s+/g, ' ').trim(),
      )
      TestBed.resetTestingModule()
    }
    expect(plates).toEqual([
      '9 days left · until 10 November 2026',
      'No window · in force since 27 September 2026',
      'Editorial · nobody is asked to accept it',
    ])
  })

  it('draws a line instead of the screen for a first revision', async () => {
    const { one } = await draw({
      variant: 'preview',
      content: reconsentPreview('first'),
      view: { kind: 'changes' },
    })
    expect(one('legal-reconsent-preview-first')).not.toBeNull()
    expect(one('legal-reconsent-accept')).toBeNull()
  })
})
