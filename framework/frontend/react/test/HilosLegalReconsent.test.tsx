import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { createSignal, type HilosRouter } from '@hilos/core'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../../core/test/legal/reconsentFixture.js'
import {
  HilosLegalReconsent,
  type HilosLegalReconsentProps,
} from '../src/legal/HilosLegalReconsent.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

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

  it('blocks Accept by its own acceptance at once and keeps its look until the spinner, while reading the text turns it off (HIL-1767)', () => {
    vi.useFakeTimers()
    const onAccept = vi.fn()
    const props: HilosLegalReconsentProps = {
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      now: RECONSENT_NOW,
      onAccept,
    }
    const { container, rerender } = render(<HilosLegalReconsent {...props} />)
    const accept = container.querySelector(
      '[data-id="legal-reconsent-accept"]',
    ) as HTMLButtonElement
    const spinner = (): Element | null =>
      accept.querySelector('[data-id="loading-button-spinner"]')
    accept.focus()
    rerender(<HilosLegalReconsent {...props} busy />)
    expect(accept.getAttribute('aria-busy')).toBe('true')
    expect(accept.getAttribute('aria-disabled')).toBe('true')
    expect(accept.disabled).toBe(false)
    expect(accept.matches(':disabled')).toBe(false)
    expect(document.activeElement).toBe(accept)
    expect(accept.classList.contains('disabled')).toBe(false)
    expect(spinner()).toBeNull()
    fireEvent.click(accept)
    expect(onAccept).not.toHaveBeenCalled()

    act(() => {
      vi.advanceTimersByTime(299)
    })
    expect(spinner()).toBeNull()
    expect(accept.classList.contains('disabled')).toBe(false)
    expect(document.activeElement).toBe(accept)
    act(() => vi.advanceTimersByTime(1))
    expect(spinner()).not.toBeNull()
    expect(accept.classList.contains('disabled')).toBe(true)

    rerender(<HilosLegalReconsent {...props} busy={false} />)
    expect(accept.getAttribute('aria-disabled')).toBeNull()
    fireEvent.click(accept)
    expect(onAccept).toHaveBeenCalledOnce()

    rerender(<HilosLegalReconsent {...props} loading />)
    expect(accept.disabled).toBe(true)
    expect(accept.matches(':disabled')).toBe(true)
    expect(accept.getAttribute('aria-busy')).toBeNull()
  })

  it('holds the window content and ordinary actions in place until acceptance settles', () => {
    const onShow = vi.fn()
    const onLater = vi.fn()
    const props: HilosLegalReconsentProps = {
      variant: 'window',
      content: reconsentContent(),
      view: { kind: 'changes' },
      person: PERSON,
      error: 'These terms have changed.',
      now: RECONSENT_NOW,
      onShow,
      onLater,
    }
    const { container, rerender } = render(<HilosLegalReconsent {...props} />)
    const fieldset = container.querySelector('fieldset')!
    const documentSection = container.querySelector(
      '[data-id="legal-reconsent-document"]',
    )!
    const error = container.querySelector('[data-id="legal-reconsent-error"]')!
    const buttons = [
      ...container.querySelectorAll<HTMLButtonElement>('button'),
    ].filter((button) => button.dataset.id !== 'legal-reconsent-accept')
    expect(buttons).toHaveLength(8)
    expect(fieldset.disabled).toBe(false)

    rerender(<HilosLegalReconsent {...props} busy />)
    expect(container.querySelector('fieldset')).toBe(fieldset)
    expect(
      container.querySelector('[data-id="legal-reconsent-document"]'),
    ).toBe(documentSection)
    expect(container.querySelector('[data-id="legal-reconsent-error"]')).toBe(
      error,
    )
    expect(error.textContent).toContain('These terms have changed.')
    for (const button of buttons) {
      expect(button.matches(':disabled')).toBe(true)
      button.click()
    }
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(onShow).not.toHaveBeenCalled()
    expect(onLater).not.toHaveBeenCalled()

    rerender(<HilosLegalReconsent {...props} />)
    expect(container.querySelector('fieldset')).toBe(fieldset)
    for (const button of buttons)
      expect(button.matches(':disabled')).toBe(false)
    fireEvent.click(
      buttons.find(
        (button) => button.dataset.id === 'legal-reconsent-full-text',
      )!,
    )
    fireEvent.click(
      buttons.find(
        (button) => button.dataset.id === 'legal-reconsent-compare',
      )!,
    )
    fireEvent.click(
      buttons.find((button) => button.dataset.id === 'legal-reconsent-refuse')!,
    )
    fireEvent.click(
      buttons.find((button) => button.dataset.id === 'legal-reconsent-later')!,
    )
    expect(onShow.mock.calls).toEqual([
      [{ kind: 'text', document: 'terms' }],
      [{ kind: 'compare', document: 'terms' }],
      [{ kind: 'refuse' }],
    ])
    expect(onLater).toHaveBeenCalledOnce()
  })

  it.each(['text', 'compare', 'refuse'] as const)(
    'holds the %s step and its Back/Close actions without remounting it',
    (kind) => {
      const onShow = vi.fn()
      const onLater = vi.fn()
      const props: HilosLegalReconsentProps = {
        variant: 'window',
        content: reconsentContent(),
        view: kind === 'refuse' ? { kind } : { kind, document: 'terms' },
        onShow,
        onLater,
      }
      const { container, rerender } = render(<HilosLegalReconsent {...props} />)
      const body = container.querySelector('fieldset')!
      const text = body.textContent
      const back = container.querySelector('[data-id="legal-reconsent-back"]')!
      rerender(<HilosLegalReconsent {...props} busy />)
      expect(container.querySelector('fieldset')).toBe(body)
      expect(body.textContent).toBe(text)
      expect(back.matches(':disabled')).toBe(true)
      fireEvent.click(back)
      const close = container.querySelector('[data-id="legal-reconsent-close"]')
      if (close) {
        expect(close.matches(':disabled')).toBe(true)
        fireEvent.click(close)
      }
      expect(onShow).not.toHaveBeenCalled()
      expect(onLater).not.toHaveBeenCalled()
      rerender(<HilosLegalReconsent {...props} />)
      fireEvent.click(back)
      expect(onShow).toHaveBeenCalledExactlyOnceWith({ kind: 'changes' })
      if (close) {
        fireEvent.click(close)
        expect(onLater).toHaveBeenCalledOnce()
      }
    },
  )

  it.each([true, false])(
    'holds refusal links before their callbacks and router (router=%s)',
    (routed) => {
      const navigate = vi.fn()
      const onLater = vi.fn()
      const router = {
        currentPath: createSignal('/'),
        navigate,
      } as unknown as HilosRouter
      const props: HilosLegalReconsentProps = {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'refuse' },
        onLater,
      }
      const draw = (busy: boolean) => (
        <HilosRouterContext.Provider value={routed ? router : null}>
          <HilosLegalReconsent {...props} busy={busy} />
        </HilosRouterContext.Provider>
      )
      const { container, rerender } = render(draw(false))
      const links = [...container.querySelectorAll('a')]
      expect(links).toHaveLength(2)
      rerender(draw(true))
      for (const link of links) {
        expect(link.getAttribute('aria-disabled')).toBe('true')
        expect(link.tabIndex).toBe(-1)
        for (const gesture of [
          new MouseEvent('click', { bubbles: true, cancelable: true }),
          new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            ctrlKey: true,
          }),
          new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            metaKey: true,
          }),
          new MouseEvent('auxclick', {
            bubbles: true,
            cancelable: true,
            button: 1,
          }),
        ]) {
          expect(fireEvent(link, gesture)).toBe(false)
          expect(gesture.defaultPrevented).toBe(true)
        }
      }
      expect(onLater).not.toHaveBeenCalled()
      expect(navigate).not.toHaveBeenCalled()
      rerender(draw(false))
      for (const link of links) {
        expect(link.getAttribute('aria-disabled')).toBeNull()
        expect(link.hasAttribute('tabindex')).toBe(false)
        if (routed) fireEvent.click(link)
      }
      if (routed) {
        expect(onLater).toHaveBeenCalledTimes(2)
        expect(navigate.mock.calls).toEqual(
          links.map((link) => [link.getAttribute('href')]),
        )
      }
    },
  )

  it('holds retry during acceptance and releases it after busy', () => {
    const onRetry = vi.fn()
    const props: HilosLegalReconsentProps = {
      variant: 'window',
      content: null,
      view: { kind: 'changes' },
      error: 'Read failed.',
      onRetry,
    }
    const { container, rerender } = render(
      <HilosLegalReconsent {...props} busy />,
    )
    const retry = container.querySelector('[data-id="legal-reconsent-retry"]')!
    expect(retry.matches(':disabled')).toBe(true)
    fireEvent.click(retry)
    expect(onRetry).not.toHaveBeenCalled()
    rerender(<HilosLegalReconsent {...props} />)
    fireEvent.click(retry)
    expect(onRetry).toHaveBeenCalledOnce()
    expect(
      container
        .querySelector('[data-id="legal-reconsent-accept"]')!
        .matches(':disabled'),
    ).toBe(true)
    expect(
      container
        .querySelector('[data-id="legal-reconsent-refuse"]')!
        .matches(':disabled'),
    ).toBe(true)
  })

  it('keeps frozen exits and preview navigation independent of acceptance busy', () => {
    const onShow = vi.fn()
    const { container, rerender } = render(
      <HilosLegalReconsent
        variant="frozen"
        content={reconsentContent()}
        view={{ kind: 'changes' }}
        person={PERSON}
        deletionScheduled
        busy
        onShow={onShow}
      />,
    )
    for (const id of [
      'legal-reconsent-not-you',
      'legal-reconsent-keep-account',
      'legal-reconsent-sign-out',
    ]) {
      expect(
        container.querySelector(`[data-id="${id}"]`)!.matches(':disabled'),
      ).toBe(false)
    }
    const link = container.querySelector(
      '[data-id="legal-reconsent-data-link"]',
    )!
    expect(link.getAttribute('aria-disabled')).toBeNull()
    expect(link.hasAttribute('tabindex')).toBe(false)
    fireEvent.click(
      container.querySelector('[data-id="legal-reconsent-full-text"]')!,
    )
    expect(onShow).toHaveBeenCalledExactlyOnceWith({
      kind: 'text',
      document: 'terms',
    })

    rerender(
      <HilosLegalReconsent
        variant="preview"
        content={reconsentPreview('window')}
        view={{ kind: 'changes' }}
        busy
        onShow={onShow}
      />,
    )
    for (const id of [
      'legal-reconsent-accept',
      'legal-reconsent-refuse',
      'legal-reconsent-later',
    ]) {
      expect(
        container.querySelector(`[data-id="${id}"]`)!.matches(':disabled'),
      ).toBe(true)
    }
    fireEvent.click(
      container.querySelector('[data-id="legal-reconsent-compare"]')!,
    )
    expect(onShow).toHaveBeenLastCalledWith({
      kind: 'compare',
      document: 'terms',
    })
    expect(container.querySelector('fieldset')!.disabled).toBe(false)
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

  it('draws the confirmed address under the name when present and omits it otherwise', () => {
    const withAddress = draw({
      variant: 'window',
      content: reconsentContent('freeze', 'bob@example.com'),
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(withAddress.one('legal-reconsent-address')?.textContent).toBe(
      'bob@example.com',
    )
    cleanup()

    const loading = draw({
      variant: 'window',
      content: null,
      loading: true,
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(loading.one('legal-reconsent-address')).toBeNull()
    cleanup()

    const noAddress = draw({
      variant: 'window',
      content: reconsentContent('freeze', null),
      view: { kind: 'changes' },
      person: PERSON,
    })
    expect(noAddress.one('legal-reconsent-address')).toBeNull()
    cleanup()

    const impersonated = draw({
      variant: 'frozen',
      content: reconsentContent('freeze', null),
      view: { kind: 'changes' },
      person: { name: 'Bob', impersonated: true },
    })
    expect(impersonated.one('legal-reconsent-address')).toBeNull()
    cleanup()

    const preview = draw({
      variant: 'preview',
      content: reconsentPreview('window'),
      view: { kind: 'changes' },
    })
    expect(preview.one('legal-reconsent-person')).toBeNull()
  })
})
