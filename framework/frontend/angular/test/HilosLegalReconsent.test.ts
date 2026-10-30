import { afterEach, describe, expect, it, vi } from 'vitest'
import { TestBed } from '@angular/core/testing'
import { createSignal, type HilosRouter } from '@hilos/core'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../core/test/legal/reconsentFixture.js'
import { HilosLegalReconsent } from '../src/legal/HilosLegalReconsent.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.useRealTimers()
})

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

  it.each([
    { kind: 'changes', buttons: ['full-text', 'compare', 'refuse', 'later'] },
    { kind: 'text', buttons: ['back', 'refuse', 'later'] },
    { kind: 'compare', buttons: ['back', 'refuse', 'later'] },
    { kind: 'refuse', buttons: ['back', 'close'] },
    { kind: 'failed', buttons: ['retry', 'later'] },
  ])(
    'holds the $kind view and its outputs until window busy clears',
    async ({ kind, buttons }) => {
      const { fixture, one } = await draw({
        variant: 'window',
        content: kind === 'failed' ? null : reconsentContent('remind'),
        view: { kind: kind === 'failed' ? 'changes' : kind, document: 'terms' },
        error: 'The terms changed. Review them again.',
        person: PERSON,
      })
      const shown = vi.fn()
      const later = vi.fn()
      const retry = vi.fn()
      fixture.componentInstance.show.subscribe(shown)
      fixture.componentInstance.later.subscribe(later)
      fixture.componentInstance.retry.subscribe(retry)
      const nodes = buttons.map((id) => one(`legal-reconsent-${id}`)!)
      const error = one('legal-reconsent-error')!
      const content = one('legal-reconsent')!.querySelector('fieldset')!
      const text = content.textContent

      // The input is already live while the native disabled bindings still lag.
      fixture.componentRef.setInput('busy', true)
      for (const node of [...nodes, one('legal-reconsent-not-you')!]) {
        node.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      }
      fixture.detectChanges()
      for (const node of [...nodes, one('legal-reconsent-not-you')!]) {
        expect(node.matches(':disabled')).toBe(true)
        node.click()
      }
      expect(shown).not.toHaveBeenCalled()
      expect(later).not.toHaveBeenCalled()
      expect(retry).not.toHaveBeenCalled()
      expect(content.textContent).toBe(text)
      expect(one('legal-reconsent-error')).toBe(error)
      expect(error.textContent).toContain(
        'The terms changed. Review them again.',
      )

      fixture.componentRef.setInput('busy', false)
      fixture.detectChanges()
      for (const [index, id] of buttons.entries()) {
        expect(one(`legal-reconsent-${id}`)).toBe(nodes[index])
        expect(nodes[index]!.matches(':disabled')).toBe(false)
        nodes[index]!.click()
      }
      expect(
        shown.mock.calls.length +
          later.mock.calls.length +
          retry.mock.calls.length,
      ).toBe(buttons.length)
      if (kind === 'failed') {
        expect(one('legal-reconsent-refuse')!.matches(':disabled')).toBe(true)
        expect(one('legal-reconsent-accept')!.matches(':disabled')).toBe(true)
      }
    },
  )

  it.each(['data', 'delete'])(
    'guards the refusal %s link before navigation, including modified and auxiliary clicks',
    async (link) => {
      const navigate = vi.fn()
      TestBed.configureTestingModule({
        providers: [
          {
            provide: HILOS_ROUTER,
            useValue: {
              currentPath: createSignal('/'),
              navigate,
            } as unknown as HilosRouter,
          },
        ],
      })
      const { fixture, one } = await draw({
        variant: 'window',
        content: reconsentContent('remind'),
        view: { kind: 'refuse' },
      })
      const later = vi.fn()
      fixture.componentInstance.later.subscribe(later)
      const anchor = one(`legal-reconsent-${link}-link`)!
      const refusal = one('legal-reconsent-refuse-step')!
      fixture.componentRef.setInput('busy', true)
      for (const init of [
        {},
        { ctrlKey: true },
        { metaKey: true },
        { shiftKey: true },
        { button: 1 },
      ]) {
        const event = new MouseEvent(init.button === 1 ? 'auxclick' : 'click', {
          ...init,
          bubbles: true,
          cancelable: true,
        })
        anchor.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
      }
      expect(navigate).not.toHaveBeenCalled()
      expect(later).not.toHaveBeenCalled()
      fixture.detectChanges()
      expect(anchor.getAttribute('aria-disabled')).toBe('true')
      expect(anchor.getAttribute('tabindex')).toBe('-1')
      expect(one('legal-reconsent-refuse-step')).toBe(refusal)
      expect(refusal.textContent).toContain(
        'Nothing changes: this reminder will keep coming back.',
      )
      fixture.componentRef.setInput('busy', false)
      fixture.detectChanges()
      expect(one(`legal-reconsent-${link}-link`)).toBe(anchor)
      expect(anchor.hasAttribute('aria-disabled')).toBe(false)
      expect(anchor.hasAttribute('tabindex')).toBe(false)
      expect(navigate).not.toHaveBeenCalled()
      expect(later).not.toHaveBeenCalled()
      anchor.click()
      expect(navigate).toHaveBeenCalledOnce()
      expect(later).toHaveBeenCalledOnce()
    },
  )

  it.each(['frozen', 'preview'])(
    'keeps %s navigation available while busy and preserves independent prohibitions',
    async (variant) => {
      const { fixture, one } = await draw({
        variant,
        content:
          variant === 'preview'
            ? reconsentPreview('window')
            : reconsentContent(),
        view: { kind: 'changes' },
        person: PERSON,
        deletionScheduled: true,
        busy: true,
      })
      const shown = vi.fn()
      fixture.componentInstance.show.subscribe(shown)
      const fullText = one('legal-reconsent-full-text')!
      expect(fullText.matches(':disabled')).toBe(false)
      fullText.click()
      expect(shown).toHaveBeenCalledWith({ kind: 'text', document: 'terms' })
      if (variant === 'frozen') {
        expect(
          one('legal-reconsent-data-link')!.hasAttribute('aria-disabled'),
        ).toBe(false)
        expect(one('legal-reconsent-sign-out')!.matches(':disabled')).toBe(
          false,
        )
        expect(one('legal-reconsent-keep-account')!.matches(':disabled')).toBe(
          false,
        )
      } else {
        for (const id of ['refuse', 'later', 'accept']) {
          expect(one(`legal-reconsent-${id}`)!.matches(':disabled')).toBe(true)
        }
        fixture.componentRef.setInput('busy', false)
        fixture.detectChanges()
        for (const id of ['refuse', 'later', 'accept']) {
          expect(one(`legal-reconsent-${id}`)!.matches(':disabled')).toBe(true)
        }
      }
    },
  )

  it('blocks Accept by its own acceptance at once and keeps its look until the spinner, while reading the text turns it off (HIL-1768)', async () => {
    vi.useFakeTimers()
    const { fixture, one } = await draw({
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      now: RECONSENT_NOW,
      busy: false,
    })
    let accepted = 0
    fixture.componentInstance.accept.subscribe(() => accepted++)
    const accept = one('legal-reconsent-accept') as HTMLButtonElement
    const spinner = (): Element | null =>
      accept.querySelector('[data-id="loading-button-spinner"]')
    accept.focus()
    const content = one('legal-reconsent-document')!
    fixture.componentRef.setInput('busy', true)
    fixture.detectChanges()
    expect(accept.getAttribute('aria-busy')).toBe('true')
    expect(accept.getAttribute('aria-disabled')).toBe('true')
    expect(accept.disabled).toBe(false)
    expect(accept.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(accept)
    expect(accept.classList.contains('disabled')).toBe(false)
    expect(spinner()).toBeNull()
    accept.click()
    expect(accepted).toBe(0)

    vi.advanceTimersByTime(299)
    fixture.detectChanges()
    expect(spinner()).toBeNull()
    expect(accept.matches(':disabled')).toBe(false)
    expect(accept.classList.contains('disabled')).toBe(false)
    expect(document.activeElement).toBe(accept)
    expect(one('legal-reconsent-document')).toBe(content)
    vi.advanceTimersByTime(1)
    fixture.detectChanges()
    expect(spinner()).not.toBeNull()
    expect(
      accept.querySelectorAll('[data-id="loading-button-spinner"]'),
    ).toHaveLength(1)
    expect(accept.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(accept)
    expect(accept.classList.contains('disabled')).toBe(true)

    fixture.componentRef.setInput('busy', false)
    fixture.detectChanges()
    expect(accept.getAttribute('aria-disabled')).toBeNull()
    accept.click()
    expect(accepted).toBe(1)

    fixture.componentRef.setInput('loading', true)
    fixture.detectChanges()
    expect(accept.disabled).toBe(true)
    expect(accept.getAttribute('aria-busy')).toBeNull()
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

  it('draws the confirmed address under the name when present and omits it otherwise', async () => {
    const withAddress = await draw({
      variant: 'window',
      content: reconsentContent('freeze', 'bob@example.com'),
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(
      withAddress.one('legal-reconsent-address')?.textContent?.trim(),
    ).toBe('bob@example.com')
    TestBed.resetTestingModule()

    const loading = await draw({
      variant: 'window',
      content: null,
      loading: true,
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(loading.one('legal-reconsent-address')).toBeNull()
    TestBed.resetTestingModule()

    const noAddress = await draw({
      variant: 'window',
      content: reconsentContent('freeze', null),
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(noAddress.one('legal-reconsent-address')).toBeNull()
    TestBed.resetTestingModule()

    const impersonated = await draw({
      variant: 'frozen',
      content: reconsentContent('freeze', null),
      view: { kind: 'changes' },
      person: { name: 'Bob', impersonated: true },
    })
    expect(impersonated.one('legal-reconsent-address')).toBeNull()
    TestBed.resetTestingModule()

    const preview = await draw({
      variant: 'preview',
      content: reconsentPreview('window'),
      view: { kind: 'changes' },
    })
    expect(preview.one('legal-reconsent-person')).toBeNull()
  })
})
