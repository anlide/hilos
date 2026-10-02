// LoadingButton in the admin view mode (HIL-1261), the cases of the Vue kit
// (vue/src/LoadingButton.test.ts): on an admin page a viewer stands on, the
// button is plainly disabled, described by the view-mode strip beside what
// describes it already, unless it only opens a window; outside the mode and
// outside an admin page it is untouched, and it comes alive with a grant.
//
// LoadingButton in a takeover that only looks (HIL-1170): in the page's area
// the button stands plainly disabled, described by the impersonation strip
// beside what describes it already, unless it only opens a window; while the
// takeover may act it is live and keeps its own description alone; on an admin
// page also seen in the view mode it names both strips.
import { Component, signal, type WritableSignal } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'

import { LoadingButton } from '../src/LoadingButton.js'
import {
  HILOS_ADMIN_VIEW_MODE,
  HILOS_TAKEOVER_VIEW_ONLY,
} from '../src/hilosLookOnly.js'

/** An admin page's one control, with every input a case may set. */
@Component({
  selector: 'test-view-mode-host',
  imports: [LoadingButton],
  template: `
    <button
      hilosLoadingButton
      class="btn-primary"
      data-id="person-block-open"
      [aria-describedby]="ownReason"
      [opensWindow]="opensWindow"
      [disabled]="disabled"
      [loading]="loading"
      (click)="clicks = clicks + 1"
    >
      Save
    </button>
  `,
})
class ViewModeHost {
  ownReason: string | undefined = undefined
  opensWindow = false
  disabled = false
  loading = false
  clicks = 0
}

/**
 * Mount the button on an admin page the way HilosAdminPage provides whether a
 * viewer stands there, or outside any admin page when no flag is given.
 *
 * @param viewMode The admin page's view mode, or undefined for no admin page.
 * @param inputs The inputs the caller sets on the button.
 * @returns The fixture, its host and its button.
 */
function mountInPage(
  viewMode: WritableSignal<boolean> | undefined,
  inputs: Partial<
    Pick<ViewModeHost, 'ownReason' | 'opensWindow' | 'disabled' | 'loading'>
  > = {},
) {
  if (viewMode !== undefined) {
    TestBed.configureTestingModule({
      providers: [
        { provide: HILOS_ADMIN_VIEW_MODE, useValue: viewMode.asReadonly() },
      ],
    })
  }
  const fixture = TestBed.createComponent(ViewModeHost)
  Object.assign(fixture.componentInstance, inputs)
  fixture.detectChanges()

  return {
    fixture,
    host: fixture.componentInstance,
    button: (fixture.nativeElement as HTMLElement).querySelector(
      'button',
    ) as HTMLButtonElement,
  }
}

describe('LoadingButton in the admin view mode', () => {
  it('stands disabled, swallows clicks, and points at the strip', () => {
    const { host, button } = mountInPage(signal(true))

    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toBe(
      'hilos-view-mode-strip-text',
    )
    expect(button.textContent?.trim()).toBe('Save')
    button.click()
    expect(host.clicks).toBe(0)
  })

  it("keeps the caller's own description beside the strip's", () => {
    const { button } = mountInPage(signal(true), { ownReason: 'own-reason' })

    expect(button.getAttribute('aria-describedby')).toBe(
      'own-reason hilos-view-mode-strip-text',
    )
    expect(button.classList.contains('btn')).toBe(true)
    expect(button.classList.contains('position-relative')).toBe(true)
    expect(button.classList.contains('btn-primary')).toBe(true)
    expect(button.getAttribute('data-id')).toBe('person-block-open')
  })

  it('is untouched outside the mode and outside an admin page', () => {
    for (const viewMode of [signal(false), undefined]) {
      TestBed.resetTestingModule()
      const { host, button } = mountInPage(viewMode, {
        ownReason: 'own-reason',
      })
      expect(button.disabled).toBe(false)
      expect(button.getAttribute('aria-describedby')).toBe('own-reason')
      button.click()
      expect(host.clicks).toBe(1)
    }
    TestBed.resetTestingModule()
    expect(
      mountInPage(signal(false)).button.getAttribute('aria-describedby'),
    ).toBeNull()
  })

  it('comes alive the moment the viewer is given the rights', () => {
    const viewMode = signal(true)
    const { fixture, host, button } = mountInPage(viewMode)

    viewMode.set(false)
    fixture.detectChanges()

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    button.click()
    expect(host.clicks).toBe(1)
  })

  it('stays live when marked as opening a window in the view mode', () => {
    const { host, button } = mountInPage(signal(true), { opensWindow: true })

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    button.click()
    expect(host.clicks).toBe(1)

    TestBed.resetTestingModule()
    const withOwn = mountInPage(signal(true), {
      opensWindow: true,
      ownReason: 'own-reason',
    })
    expect(withOwn.button.disabled).toBe(false)
    expect(withOwn.button.getAttribute('aria-describedby')).toBe('own-reason')
  })

  it('honors disabled and loading on an opensWindow button in the view mode', () => {
    const disabled = mountInPage(signal(true), {
      opensWindow: true,
      disabled: true,
    })
    expect(disabled.button.disabled).toBe(true)
    expect(disabled.button.getAttribute('aria-describedby')).toBeNull()
    disabled.button.click()
    expect(disabled.host.clicks).toBe(0)

    TestBed.resetTestingModule()
    const loading = mountInPage(signal(true), {
      opensWindow: true,
      loading: true,
    })
    expect(loading.button.disabled).toBe(true)
    expect(loading.button.getAttribute('aria-describedby')).toBeNull()
    loading.button.click()
    expect(loading.host.clicks).toBe(0)
  })

  it('leaves an opensWindow button untouched outside the view mode', () => {
    const { host, button } = mountInPage(signal(false), { opensWindow: true })

    expect(button.disabled).toBe(false)
    expect(button.getAttribute('aria-describedby')).toBeNull()
    button.click()
    expect(host.clicks).toBe(1)
  })
})

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
 * Mount the button inside the page's area of a takeover, and inside an admin
 * page as well when asked.
 *
 * @param viewOnly The provided takeover flag.
 * @param opensWindow Whether the button only opens a window.
 * @param viewMode The admin page's view mode, when the button stands on one.
 * @returns The host and its button.
 */
function mountInTakeover(
  viewOnly: boolean,
  opensWindow = false,
  viewMode?: boolean,
): { host: LoadingHost; button: HTMLButtonElement } {
  TestBed.configureTestingModule({
    providers: [
      {
        provide: HILOS_TAKEOVER_VIEW_ONLY,
        useValue: signal(viewOnly).asReadonly(),
      },
      ...(viewMode === undefined
        ? []
        : [
            {
              provide: HILOS_ADMIN_VIEW_MODE,
              useValue: signal(viewMode).asReadonly(),
            },
          ]),
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

  it('names both strips when the page is also seen in the admin view mode', () => {
    const { button } = mountInTakeover(true, false, true)

    expect(button.getAttribute('aria-describedby')).toBe(
      'row-reason hilos-view-mode-strip-text hilos-impersonation-strip-text',
    )
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
