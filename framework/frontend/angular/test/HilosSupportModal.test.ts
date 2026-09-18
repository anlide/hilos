// The Angular peer of vue/src/public/HilosSupportModal.test.ts and
// react/test/HilosSupportModal.test.tsx: the same cases by the same names, so a
// drift between the three view layers shows up as one of them failing rather
// than as a dialog nobody compared. What is Angular's own is the mount (TestBed),
// the fact that a frame is read by running change detection, and that the modal
// renders in place rather than through a portal.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HILOS_SUPPORT_REFUSAL } from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosSupportModal } from '../src/public/HilosSupportModal.js'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

/** Mount the dialog already open, which is the only state it is read in. */
function mountOpen(): ComponentFixture<HilosSupportModal> {
  const fixture = TestBed.createComponent(HilosSupportModal)
  fixture.componentRef.setInput('open', true)
  fixture.detectChanges()

  return fixture
}

/** One tier button out of the dialog. */
function tier(
  fixture: ComponentFixture<HilosSupportModal>,
  key: string,
): HTMLButtonElement {
  const button = (
    fixture.nativeElement as HTMLElement
  ).querySelector<HTMLButtonElement>(`[data-id="hilos-about-tier-${key}"]`)
  if (button === null) {
    throw new Error(`no tier button for ${key}`)
  }

  return button
}

/**
 * Take a tier, the way a person does.
 *
 * @param fixture The mounted dialog.
 * @param key The tier to press.
 */
function pick(fixture: ComponentFixture<HilosSupportModal>, key: string): void {
  tier(fixture, key).click()
  fixture.detectChanges()
}

/** Press the primary answer — the one that raises the refusal. */
function subscribe(fixture: ComponentFixture<HilosSupportModal>): void {
  const button = (
    fixture.nativeElement as HTMLElement
  ).querySelector<HTMLButtonElement>('[data-id="hilos-about-subscribe"]')
  button?.click()
  fixture.detectChanges()
}

/** The refusal plates on screen; the count is what the assertions are about. */
function refusals(fixture: ComponentFixture<HilosSupportModal>): HTMLElement[] {
  return [
    ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      '[data-id="hilos-about-refusal"]',
    ),
  ]
}

/** The live region that does the announcing, whatever it currently holds. */
function region(
  fixture: ComponentFixture<HilosSupportModal>,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="hilos-about-live-assertive"]',
  )
}

describe('HilosSupportModal', () => {
  it('opens on the drawn default tier with the primary answer live', () => {
    const fixture = mountOpen()

    expect(tier(fixture, 'beer').getAttribute('aria-pressed')).toBe('true')
    expect(tier(fixture, 'coffee').getAttribute('aria-pressed')).toBe('false')
    // The region stands there before it has anything to say.
    expect(region(fixture)).not.toBeNull()
    expect(region(fixture)?.textContent?.trim()).toBe('')
    // A screen whose whole subject is the absence of dead buttons opens with none.
    expect(
      (fixture.nativeElement as HTMLElement)
        .querySelector('[data-id="hilos-about-subscribe"]')
        ?.hasAttribute('disabled'),
    ).toBe(false)
    expect(refusals(fixture)).toHaveLength(0)
  })

  it('answers a subscribe attempt with the refusal, in the core’s own words', () => {
    const fixture = mountOpen()

    subscribe(fixture)

    expect(refusals(fixture)).toHaveLength(1)
    expect(refusals(fixture)[0]?.textContent).toContain(HILOS_SUPPORT_REFUSAL)
    // The plate shows; the region speaks. A role arriving together with its own
    // text announces nothing, so the plate carries none (accessibility.md).
    expect(refusals(fixture)[0]?.getAttribute('role')).toBeNull()
    expect(region(fixture)?.textContent).toContain(HILOS_SUPPORT_REFUSAL)
  })

  it('re-states the same refusal on a second attempt rather than stacking one', () => {
    const fixture = mountOpen()

    subscribe(fixture)
    subscribe(fixture)

    expect(refusals(fixture)).toHaveLength(1)
  })

  it('clears the refusal when another tier is chosen', () => {
    const fixture = mountOpen()

    subscribe(fixture)
    expect(refusals(fixture)).toHaveLength(1)
    pick(fixture, 'patron')

    expect(refusals(fixture)).toHaveLength(0)
    expect(region(fixture)?.textContent?.trim()).toBe('')
    expect(tier(fixture, 'patron').getAttribute('aria-pressed')).toBe('true')
    expect(tier(fixture, 'beer').getAttribute('aria-pressed')).toBe('false')
  })

  it('starts clean when it is opened again', () => {
    const fixture = mountOpen()

    subscribe(fixture)
    pick(fixture, 'coffee')
    expect(tier(fixture, 'coffee').getAttribute('aria-pressed')).toBe('true')
    subscribe(fixture)
    expect(refusals(fixture)).toHaveLength(1)

    fixture.componentRef.setInput('open', false)
    fixture.detectChanges()
    fixture.componentRef.setInput('open', true)
    fixture.detectChanges()

    expect(refusals(fixture)).toHaveLength(0)
    expect(tier(fixture, 'beer').getAttribute('aria-pressed')).toBe('true')
  })

  it('keeps the invisible refusal twin standing in both resting and refused states', () => {
    const fixture = mountOpen()

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-about-refusal-idle"]',
      ),
    ).toHaveLength(1)

    subscribe(fixture)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-about-refusal-idle"]',
      ),
    ).toHaveLength(1)
  })

  it('shows the explanation note at rest and hides it when refused', () => {
    const fixture = mountOpen()

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-about-note"]',
      ),
    ).toHaveLength(1)
    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-id="hilos-about-note"]',
      )?.textContent,
    ).toContain('A subscription unlocks nothing')
    expect(refusals(fixture)).toHaveLength(0)

    subscribe(fixture)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-about-note"]',
      ),
    ).toHaveLength(0)
    expect(refusals(fixture)).toHaveLength(1)
  })

  it('marks the idle twin aria-hidden while the visible plate carries no role', () => {
    const fixture = mountOpen()

    const idleTwin = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-about-refusal-idle"]',
    )
    expect(idleTwin).not.toBeNull()
    expect(idleTwin?.getAttribute('aria-hidden')).toBe('true')
    expect(idleTwin?.classList.contains('invisible')).toBe(true)

    subscribe(fixture)

    const activeRefusal = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-about-refusal"]',
    )
    expect(activeRefusal).not.toBeNull()
    expect(activeRefusal?.getAttribute('role')).toBeNull()
  })
})
