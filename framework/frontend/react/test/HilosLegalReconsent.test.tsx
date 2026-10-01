import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../core/test/legal/reconsentFixture.js'
import {
  HilosLegalReconsent,
  type HilosLegalReconsentProps,
} from '../src/legal/HilosLegalReconsent.js'

afterEach(cleanup)

const PERSON = { name: 'Bob', impersonated: false }

/**
 * Draw the screen and hand back a finder by data-id.
 *
 * @param props The component's props.
 */
function draw(props: HilosLegalReconsentProps) {
  const { container } = render(<HilosLegalReconsent {...props} />)
  const one = (id: string): HTMLElement | null =>
    container.querySelector(`[data-id="${id}"]`)
  const all = (id: string): HTMLElement[] =>
    Array.from(container.querySelectorAll(`[data-id="${id}"]`))

  return { one, all }
}

describe('React "the terms have changed" screen (HIL-500)', () => {
  it('draws a section per document with its plate, the accepted revision and the changes', () => {
    const { one, all } = draw({
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
    expect(all('legal-reconsent-document')[0]!.textContent).toContain(
      'Since then 2 clauses changed',
    )
    expect(
      all('legal-reconsent-change-kind').map((item) => item.textContent),
    ).toEqual(['Changed', 'Changed', 'Added'])
    expect(all('legal-reconsent-change')[1]!.textContent).toContain(
      'Before: No promise of uptime',
    )
    expect(one('legal-reconsent-person')?.textContent).toContain('Bob')
    expect(one('legal-reconsent-exits')).toBeNull()
  })

  it('reports the views asked for and draws them with a way back', () => {
    const onShow = vi.fn()
    const first = draw({
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      now: RECONSENT_NOW,
      onShow,
    })
    fireEvent.click(first.all('legal-reconsent-full-text')[0]!)
    expect(onShow).toHaveBeenCalledWith({ kind: 'text', document: 'terms' })
    cleanup()
    const text = draw({
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'compare', document: 'terms' },
      onShow,
    })
    expect(text.one('legal-changes')).not.toBeNull()
    expect(text.one('legal-reconsent-document')).toBeNull()
    fireEvent.click(text.one('legal-reconsent-back')!)
    expect(onShow).toHaveBeenLastCalledWith({ kind: 'changes' })
  })

  it('words the refusal step by the setting and closes it like Later', () => {
    const onLater = vi.fn()
    const { one } = draw({
      variant: 'window',
      content: reconsentContent('remind'),
      view: { kind: 'refuse' },
      onLater,
    })
    expect(one('legal-reconsent-refuse-step')?.textContent).toContain(
      'Nothing changes: this reminder will keep coming back.',
    )
    expect(one('legal-reconsent-delete-link')).not.toBeNull()
    expect(one('legal-reconsent-accept')).toBeNull()
    fireEvent.click(one('legal-reconsent-close')!)
    expect(onLater).toHaveBeenCalledOnce()
  })

  it('holds Accept while the content is read and shows a failed read with a retry', () => {
    const onRetry = vi.fn()
    const { one } = draw({
      variant: 'window',
      content: null,
      view: { kind: 'changes' },
      error: 'The terms could not be loaded.',
      onRetry,
    })
    expect(one('legal-reconsent-accept')?.hasAttribute('disabled')).toBe(true)
    expect(one('legal-reconsent-error')?.textContent).toContain(
      'The terms could not be loaded.',
    )
    fireEvent.click(one('legal-reconsent-retry')!)
    expect(onRetry).toHaveBeenCalledOnce()
  })

  it('holds Accept when nothing is left to accept', () => {
    const { one } = draw({
      variant: 'frozen',
      content: { ...reconsentContent(), documents: [] },
      view: { kind: 'changes' },
    })
    expect(one('legal-reconsent-accept')?.hasAttribute('disabled')).toBe(true)
  })

  it('keeps only Accept on the freeze and lays the exits out as actions', () => {
    const { one, all } = draw({
      variant: 'frozen',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: PERSON,
      deletionScheduled: true,
      now: RECONSENT_NOW,
    })
    expect(one('legal-reconsent-heading')?.textContent).toBe(
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

  it('takes the acceptance away under a takeover and says who may accept', () => {
    const { one } = draw({
      variant: 'frozen',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: { name: 'Bob', impersonated: true },
      deletionScheduled: true,
    })
    expect(one('legal-reconsent-accept')).toBeNull()
    expect(one('legal-reconsent-keep-account')).toBeNull()
    expect(one('legal-reconsent-impersonated')?.textContent).toBe(
      'Only Bob can accept the terms.',
    )
  })

  it('previews through the previous holder with every button inactive, and a first revision as a line', () => {
    const plates = (['window', 'lapsed', 'editorial'] as const).map((kind) => {
      const { one } = draw({
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
      const text = one('legal-reconsent-badge')?.textContent
      cleanup()
      return text
    })
    expect(plates).toEqual([
      '9 days left · until 10 November 2026',
      'No window · in force since 27 September 2026',
      'Editorial · nobody is asked to accept it',
    ])
    const { one } = draw({
      variant: 'preview',
      content: reconsentPreview('first'),
      view: { kind: 'changes' },
    })
    expect(one('legal-reconsent-preview-first')).not.toBeNull()
    expect(one('legal-reconsent-accept')).toBeNull()
  })
})
