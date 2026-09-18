// The Angular peer of vue/src/HilosToastHost.test.ts and
// react/test/HilosToastHost.test.tsx, for the service line under the stack: the
// line is written out once per SDK, and the layer without a unit is the one
// place a divergence between the three never surfaces (HIL-908). The store's
// own behavior — what is missed and when it comes back — is a core unit test.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { createHilosToastStore } from '@hilos/core'
import type { HilosToastStore } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosToastHost } from '../src/HilosToastHost.js'

/** A host that renders the toast stack over a store of its own. */
@Component({
  selector: 'test-toast-host',
  imports: [HilosToastHost],
  template: ` <hilos-toast-host [store]="store" /> `,
})
class ToastHost {
  readonly store: HilosToastStore = createHilosToastStore()
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
})
