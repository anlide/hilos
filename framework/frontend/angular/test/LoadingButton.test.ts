// LoadingButton in flight, the cases of the Vue kit (vue/src/LoadingButton.test.ts)
// and of the React one: its own action locks it at once — busy, blocked, a press
// swallowed before the caller's (click), the form's submit, and the ancestors
// hear it — while it keeps its look and its focus until the spinner comes at
// 300 ms; only an independent reason turns it off natively.
//
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
import { DEFAULT_SPINNER_DELAY_MS } from '@hilos/core/base'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { LoadingButton } from '../src/LoadingButton.js'
import {
  HILOS_ADMIN_VIEW_MODE,
  HILOS_TAKEOVER_VIEW_ONLY,
} from '../src/hilosLookOnly.js'

/** A form whose one control is the tracked button, every press on it counted. */
@Component({
  selector: 'test-flight-host',
  imports: [LoadingButton],
  template: `
    <form (submit)="submitted($event)" (click)="ancestorClicked()">
      <button
        hilosLoadingButton
        class="btn-primary"
        data-id="profile-save"
        aria-describedby="own-reason"
        [type]="type()"
        [loading]="loading()"
        [disabled]="disabled()"
        [loadingDelay]="loadingDelay()"
        (click)="clicked()"
      >
        <span data-id="profile-save-label" (click)="labelClicked()">Save</span>
      </button>
    </form>
  `,
})
class FlightHost {
  readonly type = signal<'button' | 'submit'>('submit')
  readonly loading = signal(false)
  readonly disabled = signal(false)
  readonly loadingDelay = signal(DEFAULT_SPINNER_DELAY_MS)
  readonly clicked = vi.fn()
  readonly labelClicked = vi.fn()
  readonly ancestorClicked = vi.fn()
  readonly submitted = vi.fn((event: Event) => {
    event.preventDefault()
  })
}

/** The inputs a case sets on the button in its form. */
interface FlightInputs {
  type?: 'button' | 'submit'
  loading?: boolean
  disabled?: boolean
  loadingDelay?: number
}

/** A form that sends once and holds every submit while its send flies. */
@Component({
  selector: 'test-guarded-form',
  imports: [LoadingButton],
  template: `
    <form (submit)="send($event)">
      <button hilosLoadingButton type="submit" [loading]="busy()">Save</button>
    </form>
  `,
})
class GuardedForm {
  readonly busy = signal(false)
  readonly sent = vi.fn()

  /**
   * Send the form unless its send is already out.
   *
   * @param event The submit event, kept from navigating.
   */
  send(event: Event): void {
    event.preventDefault()
    if (this.busy()) {
      return
    }
    this.busy.set(true)
    this.sent()
  }
}

/** A button standing where an admin view mode and a takeover may come. */
@Component({
  selector: 'test-region-host',
  imports: [LoadingButton],
  template: `
    <button hilosLoadingButton [loading]="loading()" (click)="clicked()">
      Save
    </button>
  `,
})
class RegionHost {
  readonly loading = signal(true)
  readonly clicked = vi.fn()
}

/**
 * What the button tells a sighted person and a screen reader right now.
 *
 * @param button The rendered button.
 */
function stateOf(button: HTMLButtonElement) {
  return {
    nativeDisabled: button.disabled,
    disabledLook: button.classList.contains('disabled'),
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    labelHidden:
      button.firstElementChild?.classList.contains('invisible') ?? false,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
  }
}

const resting = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  labelHidden: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const lockedLookingTheSame = {
  ...resting,
  ariaBusy: 'true',
  ariaDisabled: 'true',
}
const spinning = {
  ...lockedLookingTheSame,
  disabledLook: true,
  spinner: true,
  labelHidden: true,
}

/**
 * Press an element the way a mouse, Enter, or Space activates a button: one
 * click event, which is all a key press on a button turns into. jsdom does not
 * turn a key into that click itself, so no key is pressed here.
 *
 * @param target The button or an element inside it.
 * @returns The click event, to read whether it was canceled.
 */
function press(target: Element): MouseEvent {
  const event = new MouseEvent('click', { bubbles: true, cancelable: true })
  target.dispatchEvent(event)

  return event
}

describe('LoadingButton in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    TestBed.resetTestingModule()
    vi.useRealTimers()
  })

  /**
   * Mount the button in its form, with a way to pass it new inputs and to let
   * the clock run.
   *
   * @param inputs The inputs the caller sets first.
   */
  function mountFlight(inputs: FlightInputs = {}) {
    const fixture = TestBed.createComponent(FlightHost)
    const host = fixture.componentInstance

    /**
     * Set the given inputs of the button and let the view catch up.
     *
     * @param next The inputs to change.
     */
    function update(next: FlightInputs): void {
      if (next.type !== undefined) {
        host.type.set(next.type)
      }
      if (next.loading !== undefined) {
        host.loading.set(next.loading)
      }
      if (next.disabled !== undefined) {
        host.disabled.set(next.disabled)
      }
      if (next.loadingDelay !== undefined) {
        host.loadingDelay.set(next.loadingDelay)
      }
      fixture.detectChanges()
    }

    update(inputs)
    const root = fixture.nativeElement as HTMLElement

    return {
      fixture,
      host,
      button: root.querySelector('button') as HTMLButtonElement,
      label: root.querySelector(
        '[data-id="profile-save-label"]',
      ) as HTMLElement,
      update,
      wait(ms: number): void {
        vi.advanceTimersByTime(ms)
        fixture.detectChanges()
      },
    }
  }

  it('keeps its look until the spinner comes at 300 ms, then looks disabled', () => {
    const { host, button, update, wait } = mountFlight({ type: 'button' })
    expect(stateOf(button)).toEqual(resting)

    update({ loading: true })
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(299)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)
    expect(button.textContent).toContain('Save')

    update({ loading: false })
    expect(stateOf(button)).toEqual(resting)
    button.click()
    expect(host.clicked).toHaveBeenCalledTimes(1)
  })

  it('never shows the spinner when the reply comes before 300 ms', () => {
    const { button, update, wait } = mountFlight()

    update({ loading: true })
    wait(299)
    update({ loading: false })
    expect(stateOf(button)).toEqual(resting)
    wait(1000)
    expect(stateOf(button)).toEqual(resting)
  })

  it('gives a new request a full delay of its own', () => {
    const { button, update, wait } = mountFlight()

    update({ loading: true })
    wait(200)
    update({ loading: false })
    update({ loading: true })
    wait(200)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(100)
    expect(stateOf(button)).toEqual(spinning)
  })

  it('reads loadingDelay when each request starts', () => {
    const { button, update, wait } = mountFlight({ loadingDelay: 500 })

    update({ loading: true })
    wait(499)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)

    update({ loading: false, loadingDelay: 100 })
    update({ loading: true })
    wait(99)
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    wait(1)
    expect(stateOf(button)).toEqual(spinning)
  })

  it('cancels the pending spinner and stops guarding presses when destroyed', () => {
    const { fixture, button, update } = mountFlight({ type: 'button' })

    update({ loading: true })
    expect(vi.getTimerCount()).toBe(1)
    expect(press(button).defaultPrevented).toBe(true)
    fixture.destroy()
    expect(vi.getTimerCount()).toBe(0)
    expect(press(button).defaultPrevented).toBe(false)
  })

  it("cancels a blocked press before the caller's (click), the form's submit, or an ancestor hears it", () => {
    const outsideClicked = vi.fn()
    document.body.addEventListener('click', outsideClicked)
    try {
      const { host, button, label, update, wait } = mountFlight({
        loading: true,
      })

      // The label hears a press on itself before the button's bubble phase, so
      // only a guard in the capture phase keeps it silent. The caller's (click)
      // alone would not tell: Angular adds the component's listener first.
      expect(press(button).defaultPrevented).toBe(true)
      expect(press(label).defaultPrevented).toBe(true)
      button.click()
      wait(300)
      expect(press(button).defaultPrevented).toBe(true)
      expect(press(label).defaultPrevented).toBe(true)
      button.click()
      expect(host.clicked).not.toHaveBeenCalled()
      expect(host.labelClicked).not.toHaveBeenCalled()
      expect(host.ancestorClicked).not.toHaveBeenCalled()
      expect(outsideClicked).not.toHaveBeenCalled()
      expect(host.submitted).not.toHaveBeenCalled()

      update({ loading: false })
      expect(press(button).defaultPrevented).toBe(false)
      expect(host.clicked).toHaveBeenCalledTimes(1)
      expect(host.ancestorClicked).toHaveBeenCalledTimes(1)
      expect(outsideClicked).toHaveBeenCalledTimes(1)
      expect(host.submitted).toHaveBeenCalledTimes(1)
      press(label)
      expect(host.labelClicked).toHaveBeenCalledTimes(1)
      expect(host.clicked).toHaveBeenCalledTimes(2)
      expect(host.submitted).toHaveBeenCalledTimes(2)
    } finally {
      document.body.removeEventListener('click', outsideClicked)
    }
  })

  it("leaves a direct submit to the form's own guard", () => {
    const fixture = TestBed.createComponent(GuardedForm)
    fixture.detectChanges()
    const root = fixture.nativeElement as HTMLElement
    const button = root.querySelector('button') as HTMLButtonElement
    const form = root.querySelector('form') as HTMLFormElement

    press(button)
    expect(fixture.componentInstance.sent).toHaveBeenCalledTimes(1)
    // Pressed again before the view has caught up: the button does not know
    // yet, and the form's own guard is what holds.
    press(button)
    expect(fixture.componentInstance.sent).toHaveBeenCalledTimes(1)

    fixture.detectChanges()
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    expect(press(button).defaultPrevented).toBe(true)
    form.requestSubmit(button)
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    expect(fixture.componentInstance.sent).toHaveBeenCalledTimes(1)
  })

  it('keeps the focus on the button through its own request', () => {
    const { button, update, wait } = mountFlight()
    button.focus()
    expect(document.activeElement).toBe(button)

    update({ loading: true })
    expect(document.activeElement).toBe(button)
    wait(300)
    expect(document.activeElement).toBe(button)
    update({ loading: false })
    expect(document.activeElement).toBe(button)
  })

  it('turns off at once for a reason that comes and goes while it loads', () => {
    const { host, button, update, wait } = mountFlight({ loading: true })

    update({ disabled: true })
    expect(stateOf(button)).toEqual({
      ...lockedLookingTheSame,
      nativeDisabled: true,
    })
    update({ disabled: false })
    expect(stateOf(button)).toEqual(lockedLookingTheSame)
    expect(press(button).defaultPrevented).toBe(true)
    expect(host.clicked).not.toHaveBeenCalled()

    update({ disabled: true })
    wait(300)
    expect(stateOf(button)).toEqual({ ...spinning, nativeDisabled: true })
    update({ loading: false })
    expect(stateOf(button)).toEqual({
      ...resting,
      nativeDisabled: true,
      ariaDisabled: 'true',
    })
    button.click()
    expect(host.clicked).not.toHaveBeenCalled()
  })

  it.each([
    [
      'the admin view mode',
      HILOS_ADMIN_VIEW_MODE,
      'hilos-view-mode-strip-text',
    ],
    [
      'a takeover that only looks',
      HILOS_TAKEOVER_VIEW_ONLY,
      'hilos-impersonation-strip-text',
    ],
  ])(
    'turns off at once when %s comes and goes while it loads',
    (_mode, token, strip) => {
      const locked = signal(false)
      TestBed.configureTestingModule({
        providers: [{ provide: token, useValue: locked.asReadonly() }],
      })
      const fixture = TestBed.createComponent(RegionHost)
      fixture.detectChanges()
      const button = (fixture.nativeElement as HTMLElement).querySelector(
        'button',
      ) as HTMLButtonElement
      expect(stateOf(button)).toEqual(lockedLookingTheSame)

      locked.set(true)
      fixture.detectChanges()
      expect(stateOf(button)).toEqual({
        ...lockedLookingTheSame,
        nativeDisabled: true,
      })
      expect(button.getAttribute('aria-describedby')).toBe(strip)

      locked.set(false)
      fixture.detectChanges()
      expect(stateOf(button)).toEqual(lockedLookingTheSame)
      expect(button.getAttribute('aria-describedby')).toBeNull()
      expect(press(button).defaultPrevented).toBe(true)
      expect(fixture.componentInstance.clicked).not.toHaveBeenCalled()

      locked.set(true)
      fixture.componentInstance.loading.set(false)
      fixture.detectChanges()
      expect(stateOf(button)).toEqual({
        ...resting,
        nativeDisabled: true,
        ariaDisabled: 'true',
      })
      expect(button.getAttribute('aria-describedby')).toBe(strip)
    },
  )

  it("keeps the caller's class, data-id, type, and description through its own flight", () => {
    const { button, update, wait } = mountFlight({ loading: true })

    expect(button.getAttribute('type')).toBe('submit')
    wait(300)
    expect(Array.from(button.classList)).toEqual(
      expect.arrayContaining([
        'btn',
        'position-relative',
        'btn-primary',
        'disabled',
      ]),
    )
    expect(button.getAttribute('data-id')).toBe('profile-save')
    expect(button.getAttribute('aria-describedby')).toBe('own-reason')
    expect(button.getAttribute('type')).toBe('submit')

    update({ loading: false })
    expect(Array.from(button.classList)).toEqual(
      expect.arrayContaining(['btn', 'position-relative', 'btn-primary']),
    )
    expect(button.classList.contains('disabled')).toBe(false)
    expect(button.getAttribute('data-id')).toBe('profile-save')
    expect(button.getAttribute('aria-describedby')).toBe('own-reason')
  })
})

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
    expect(loading.button.disabled).toBe(false)
    expect(loading.button.getAttribute('aria-busy')).toBe('true')
    expect(loading.button.getAttribute('aria-disabled')).toBe('true')
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
