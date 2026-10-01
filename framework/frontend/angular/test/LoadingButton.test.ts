// LoadingButton in a takeover that only looks (HIL-1170): in the page's area
// the button stands plainly disabled, described by the impersonation strip
// beside what describes it already, unless it only opens a window; while the
// takeover may act it is live and keeps its own description alone.
import { Component, signal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { LoadingButton } from '../src/LoadingButton.js'
import { HILOS_TAKEOVER_VIEW_ONLY } from '../src/hilosLookOnly.js'

/** A page whose one control is the tracked button, described by a reason. */
@Component({
  selector: 'test-loading-host',
  imports: [LoadingButton],
  template: `
    <button
      hilosLoadingButton
      class="btn-primary"
      aria-describedby="row-reason"
      [opensWindow]="opensWindow"
      (click)="clicks = clicks + 1"
    >
      Send
    </button>
  `,
})
class LoadingHost {
  opensWindow = false
  clicks = 0
}

/**
 * Mount the button inside the page's area of a takeover.
 *
 * @param viewOnly The provided takeover flag.
 * @param opensWindow Whether the button only opens a window.
 * @returns The host and its button.
 */
function mountInTakeover(
  viewOnly: boolean,
  opensWindow = false,
): { host: LoadingHost; button: HTMLButtonElement } {
  TestBed.configureTestingModule({
    providers: [
      {
        provide: HILOS_TAKEOVER_VIEW_ONLY,
        useValue: signal(viewOnly).asReadonly(),
      },
    ],
  })
  const fixture = TestBed.createComponent(LoadingHost)
  fixture.componentInstance.opensWindow = opensWindow
  fixture.detectChanges()

  return {
    host: fixture.componentInstance,
    button: (fixture.nativeElement as HTMLElement).querySelector(
      'button',
    ) as HTMLButtonElement,
  }
}

describe('LoadingButton in a takeover that only looks (HIL-1170)', () => {
  it('stands disabled, swallows clicks, and points at the impersonation strip beside its own', () => {
    const { host, button } = mountInTakeover(true)

    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toBe(
      'row-reason hilos-impersonation-strip-text',
    )
    expect(button.textContent?.trim()).toBe('Send')
    button.click()
    expect(host.clicks).toBe(0)
  })

  it('is live while the takeover may act, and keeps its own description', () => {
    const { host, button } = mountInTakeover(false)

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBe('row-reason')
    button.click()
    expect(host.clicks).toBe(1)
  })

  it('stays live when it only opens a window', () => {
    const { host, button } = mountInTakeover(true, true)

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBe('row-reason')
    button.click()
    expect(host.clicks).toBe(1)
  })
})
