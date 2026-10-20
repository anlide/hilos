// The Angular peer of vue/src/HilosToastHost.test.ts and
// react/test/HilosToastHost.test.tsx, for the service line under the stack: the
// line is written out once per SDK, and the layer without a unit is the one
// place a divergence between the three never surfaces (HIL-908) — and for the
// holds following the cards, which are written out once per SDK too (HIL-916).
// The store's own behavior — what is missed and when it comes back — is a core
// unit test.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createHilosToastStore } from '@hilos/core'
import type { HilosToastStore } from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosToastHost } from '../src/HilosToastHost.js'
import type { HilosToastCorner } from '../src/hilosToastCorner.js'

/** A host that renders the toast stack over a store of its own. */
@Component({
  selector: 'test-toast-host',
  imports: [HilosToastHost],
  template: ` <hilos-toast-host [store]="store" [corner]="corner" /> `,
})
class ToastHost {
  readonly store: HilosToastStore = createHilosToastStore()
  corner: HilosToastCorner = 'bottom-end'
}

/**
 * Fill a stack past its budget before any host is mounted.
 *
 * With no height reported yet the store counts its budget in cards, and that is
 * the only way a stack overflows in jsdom, where every box measures zero pixels.
 * The waiting error keeps it that way once the host has measured: nothing missed
 * comes back while an error still waits for a slot.
 *
 * @param store The stack to fill.
 */
function overfill(store: HilosToastStore): void {
  for (const message of ['One', 'Two', 'Three', 'Four', 'Five']) {
    store.push(message, { severity: 'info' })
  }
  store.push('The report could not be built', { severity: 'error' })
}

/**
 * Mount a host over an overfilled stack and let it measure.
 *
 * @returns The mounted fixture, already rendered.
 */
async function mountOverfilled(): Promise<ComponentFixture<ToastHost>> {
  const fixture = TestBed.createComponent(ToastHost)
  overfill(fixture.componentInstance.store)
  fixture.detectChanges()
  await fixture.whenStable()

  return fixture
}

/**
 * The first element inside the fixture carrying a `data-id`.
 *
 * @param fixture The mounted host.
 * @param id The `data-id` to look up.
 */
function byId(
  fixture: ComponentFixture<ToastHost>,
  id: string,
): HTMLElement | null {
  const root = fixture.nativeElement as HTMLElement

  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

describe('HilosToastHost', () => {
  it('makes the missed piece a control and leaves the waiting one plain text', async () => {
    const fixture = await mountOverfilled()

    const line = byId(fixture, 'hilos-toast-overflow') as HTMLElement
    expect(line.querySelectorAll('button')).toHaveLength(1)
    const control = byId(fixture, 'hilos-toast-missed') as HTMLElement
    expect(control.tagName).toBe('BUTTON')
    expect(control.textContent?.trim()).toBe('1 missed')
    expect(control.getAttribute('aria-label')).toBe('1 missed, show one')
  })

  it('asks the store for one missed notice when the control is pressed', async () => {
    const fixture = await mountOverfilled()
    const store = fixture.componentInstance.store

    byId(fixture, 'hilos-toast-missed')?.click()
    fixture.detectChanges()
    await fixture.whenStable()

    expect(store.toasts.get().map((toast) => toast.message)).toContain('Five')
    expect(store.overflow.get()).toEqual({ waiting: 1, missed: 0 })
    expect(byId(fixture, 'hilos-toast-missed')).toBeNull()
  })

  it('moves focus onto the returned card when the control disappears under the press', async () => {
    const fixture = await mountOverfilled()

    byId(fixture, 'hilos-toast-missed')?.click()
    fixture.detectChanges()
    await fixture.whenStable()

    const root = fixture.nativeElement as HTMLElement
    const returned = [
      ...root.querySelectorAll('[data-id="hilos-toast-info"]'),
    ].find((card) => card.textContent?.includes('Five'))
    expect(document.activeElement).toBe(
      returned?.querySelector('[data-id="hilos-toast-close"]'),
    )
  })

  // The corner is the wide screen's setting; what a narrow screen does with it
  // is a media query, which jsdom does not lay out — the e2e suite owns that.
  it.each<[HilosToastCorner, string, string]>([
    ['bottom-end', 'end-0', 'hilos-toast-stack-bottom'],
    ['bottom-start', 'start-0', 'hilos-toast-stack-bottom'],
    ['top-end', 'end-0', 'hilos-toast-stack-top'],
    ['top-start', 'start-0', 'hilos-toast-stack-top'],
  ])('draws the %s corner with %s and %s', (corner, horizontal, vertical) => {
    const fixture = TestBed.createComponent(ToastHost)
    fixture.componentInstance.corner = corner
    fixture.detectChanges()

    const classes = (byId(fixture, 'hilos-toasts') as HTMLElement).className
      .split(' ')
      .filter(Boolean)
    expect(classes).toContain(horizontal)
    expect(classes).toContain(vertical)
    expect(classes).not.toContain(horizontal === 'end-0' ? 'start-0' : 'end-0')
    expect(classes).not.toContain(
      vertical === 'hilos-toast-stack-bottom'
        ? 'hilos-toast-stack-top'
        : 'hilos-toast-stack-bottom',
    )
  })

  // The service line reads what the store counted: the hourglass belongs to the
  // waiting errors and stands right before their number, the missed piece is
  // the one control, and the separator stands only between two numbers.
  describe('service line', () => {
    afterEach(() => {
      vi.restoreAllMocks()
    })

    it('draws nothing while nothing waits and nothing was missed', async () => {
      const fixture = TestBed.createComponent(ToastHost)
      fixture.componentInstance.store.push('Saved', { severity: 'success' })
      fixture.detectChanges()
      await fixture.whenStable()

      expect(fixture.componentInstance.store.overflow.get()).toEqual({
        waiting: 0,
        missed: 0,
      })
      expect(byId(fixture, 'hilos-toast-overflow')).toBeNull()
      expect(
        (fixture.nativeElement as HTMLElement).querySelector(
          '.bi-hourglass-split',
        ),
      ).toBeNull()
    })

    it('puts the hourglass right before the waiting count when both are there', async () => {
      const fixture = await mountOverfilled()

      const line = byId(fixture, 'hilos-toast-overflow') as HTMLElement
      expect(line.textContent?.replace(/\s+/g, ' ').trim()).toBe(
        '1 more waiting · 1 missed',
      )
      const hourglasses = line.querySelectorAll('.bi-hourglass-split')
      expect(hourglasses).toHaveLength(1)
      const hourglass = hourglasses[0]
      expect(hourglass.getAttribute('aria-hidden')).toBe('true')
      expect(hourglass.previousSibling).toBeNull()
      expect(hourglass.nextSibling?.textContent?.trim()).toBe('1 more waiting')
      expect(hourglass.parentElement?.textContent?.trim()).toBe(
        '1 more waiting',
      )
    })

    it('says only what waits, with the hourglass, once nothing is missed', async () => {
      const fixture = await mountOverfilled()

      // The press brings the one missed notice back and leaves the queued
      // error waiting — the store's own way to that state, no fixture of its own.
      byId(fixture, 'hilos-toast-missed')?.click()
      fixture.detectChanges()
      await fixture.whenStable()
      expect(fixture.componentInstance.store.overflow.get()).toEqual({
        waiting: 1,
        missed: 0,
      })

      const line = byId(fixture, 'hilos-toast-overflow') as HTMLElement
      expect(line.textContent?.trim()).toBe('1 more waiting')
      const hourglasses = line.querySelectorAll('.bi-hourglass-split')
      expect(hourglasses).toHaveLength(1)
      expect(hourglasses[0].nextSibling?.textContent?.trim()).toBe(
        '1 more waiting',
      )
      expect(byId(fixture, 'hilos-toast-missed')).toBeNull()
      expect(line.textContent).not.toContain('·')
    })

    it('says only what was missed, without the hourglass, as the same control', async () => {
      // Two cards fill a third of jsdom's 768-pixel window; the third misses.
      // Without a height every box measures zero, and the store would take
      // every missed notice back at once.
      vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(
        new DOMRect(0, 0, 100, 100),
      )
      const fixture = TestBed.createComponent(ToastHost)
      const store = fixture.componentInstance.store
      store.push('One', { severity: 'info' })
      store.push('Two', { severity: 'info' })
      fixture.detectChanges()
      await fixture.whenStable()
      store.push('Three', { severity: 'info' })
      fixture.detectChanges()
      await fixture.whenStable()
      expect(store.overflow.get()).toEqual({ waiting: 0, missed: 1 })

      const line = byId(fixture, 'hilos-toast-overflow') as HTMLElement
      expect(line.textContent?.trim()).toBe('1 missed')
      expect(line.querySelector('.bi-hourglass-split')).toBeNull()
      expect(line.textContent).not.toContain('·')
      const control = byId(fixture, 'hilos-toast-missed') as HTMLElement
      expect(control.tagName).toBe('BUTTON')
      expect(control.getAttribute('aria-label')).toBe('1 missed, show one')

      control.click()
      fixture.detectChanges()
      await fixture.whenStable()
      expect(store.toasts.get().map((toast) => toast.message)).toContain(
        'Three',
      )
      expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
    })
  })

  // The holds follow the cards (HIL-916): taken by the engine's mouseover, given
  // back when a card that was on screen leaves, never re-read from :hover.
  describe('holds', () => {
    afterEach(() => {
      vi.restoreAllMocks()
      vi.useRealTimers()
    })

    /**
     * Mount a host over a stack the case has already filled, and let it measure.
     *
     * @param fill Puts the cards the case starts from on the stack.
     * @returns The mounted fixture, already rendered.
     */
    async function mountWith(
      fill: (store: HilosToastStore) => void,
    ): Promise<ComponentFixture<ToastHost>> {
      const fixture = TestBed.createComponent(ToastHost)
      fill(fixture.componentInstance.store)
      await render(fixture)

      return fixture
    }

    /**
     * Run change detection and the after-render hooks it schedules.
     *
     * @param fixture The mounted host.
     */
    async function render(fixture: ComponentFixture<ToastHost>): Promise<void> {
      fixture.detectChanges()
      await fixture.whenStable()
      fixture.detectChanges()
      await fixture.whenStable()
    }

    /**
     * Put the cursor on the stack.
     *
     * @param fixture The mounted host.
     */
    function mouseOver(fixture: ComponentFixture<ToastHost>): void {
      byId(fixture, 'hilos-toasts')?.dispatchEvent(
        new MouseEvent('mouseover', { bubbles: true }),
      )
    }

    /**
     * Push a notice after the stack went still and run its whole lifetime out.
     *
     * @param fixture The mounted host.
     */
    async function pushAndWaitItOut(
      fixture: ComponentFixture<ToastHost>,
    ): Promise<void> {
      fixture.componentInstance.store.push('Saved', { severity: 'success' })
      await render(fixture)
      vi.advanceTimersByTime(20_000)
    }

    it('does not take the hold back after the only card closes under a resting cursor', async () => {
      vi.useFakeTimers()
      const fixture = await mountWith((store) => {
        store.push('Backup created.', { severity: 'success' })
      })
      // The stale read of P-217: right after the patch that removed the last
      // card, :hover still answers with the state from before it.
      const stack = byId(fixture, 'hilos-toasts') as HTMLElement
      vi.spyOn(stack, 'matches').mockReturnValue(true)

      mouseOver(fixture)
      byId(fixture, 'hilos-toast-close')?.click()
      await render(fixture)
      await pushAndWaitItOut(fixture)

      expect(fixture.componentInstance.store.toasts.get()).toEqual([])
    })

    it('gives back the cursor hold when the stack is cleared under a resting cursor', async () => {
      vi.useFakeTimers()
      const fixture = await mountWith((store) => {
        store.push('Backup created.', { severity: 'success' })
      })
      const store = fixture.componentInstance.store

      mouseOver(fixture)
      store.clear()
      await render(fixture)
      await pushAndWaitItOut(fixture)

      expect(store.toasts.get()).toEqual([])
    })

    it('gives back the cursor hold when the server takes the card under the cursor away', async () => {
      vi.useFakeTimers()
      const fixture = await mountWith((store) => {
        store.syncSession([
          {
            key: 'toast-key',
            message: 'The export is ready',
            severity: 'info',
            source: 'Backup',
            destination: '/hilos/backup',
            repeats: 1,
          },
        ])
      })
      const store = fixture.componentInstance.store
      expect(store.toasts.get()[0].measured).toBe(true)

      mouseOver(fixture)
      store.syncSession([])
      await render(fixture)
      await pushAndWaitItOut(fixture)

      expect(store.toasts.get()).toEqual([])
    })

    it('keeps the cursor hold when a notice that never showed goes to the missed list', async () => {
      vi.useFakeTimers()
      // Two cards fill a third of jsdom's 768-pixel window; the third misses.
      vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(
        new DOMRect(0, 0, 100, 100),
      )
      const fixture = await mountWith((store) => {
        store.push('One', { severity: 'info' })
        store.push('Two', { severity: 'info' })
      })
      const store = fixture.componentInstance.store

      mouseOver(fixture)
      store.push('Three', { severity: 'info' })
      await render(fixture)
      expect(store.overflow.get().missed).toBe(1)
      vi.advanceTimersByTime(20_000)

      expect(store.toasts.get().map((toast) => toast.message)).toEqual([
        'One',
        'Two',
      ])
    })

    it('gives back the focus hold when the stack is cleared under keyboard focus', async () => {
      vi.useFakeTimers()
      const fixture = await mountWith((store) => {
        store.push('Backup created.', { severity: 'success' })
      })
      const store = fixture.componentInstance.store

      byId(fixture, 'hilos-toast-close')?.focus()
      expect(store.reading.get()).toBe(true)
      store.clear()
      await render(fixture)
      await pushAndWaitItOut(fixture)

      expect(store.toasts.get()).toEqual([])
    })
  })
})
