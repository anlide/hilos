// The Angular peer of the Vue/React setting-presets viewer tests (HIL-1272):
// what a viewer of the admin view mode finds on the screen — every card a
// LoadingButton standing disabled under the view-mode strip, the hidden mark in
// place of the values a card lists, no overwrite question and nothing sent —
// and that an administrator on a node in the mode applies a mode as before,
// the card they pressed carrying the apply in flight while the others wait.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import type {
  ActionHandle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  HilosSettingPresetsContext,
  HilosSettingPresetsState,
  HilosSettingPresetsVocabulary,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosSettingPresetsPage } from '../src/admin/settings/HilosSettingPresetsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const SIGNAL = 'subscription_page_hilos_logs_settings'

/** A group frame as the page answers a subscription with it. */
function groupState(
  overrides: Partial<HilosSettingPresetsState> = {},
): HilosSettingPresetsState {
  return {
    group: 'logs',
    selected: 'normal',
    presets: [
      { name: 'frugal', values: { level: 'WARNING' } },
      { name: 'normal', values: { level: 'INFO' } },
      { name: 'investigation', values: { level: 'DEBUG' } },
    ],
    differences: [],
    ...overrides,
  }
}

/**
 * A group frame as a viewer of the admin view mode is sent it: which mode is
 * applied, what each declares, and where the settings drifted, all hidden.
 */
function hiddenState(): HilosSettingPresetsState {
  return {
    group: 'logs',
    selected: { _hidden: true } as unknown as string,
    presets: [
      {
        name: 'frugal',
        values: { _hidden: true } as unknown as Record<string, string>,
      },
      {
        name: 'normal',
        values: { _hidden: true } as unknown as Record<string, string>,
      },
      {
        name: 'investigation',
        values: { _hidden: true } as unknown as Record<string, string>,
      },
    ],
    differences: { _hidden: true } as unknown as [],
  }
}

/**
 * A vocabulary of one made-up section, so the test reads what the screen does with
 * the words rather than what any one section's words are.
 */
const vocabulary: HilosSettingPresetsVocabulary = {
  intro: 'Intro paragraph.',
  groupHeading: 'Mode',
  differencesHeading: 'Differences:',
  revertLabel: 'Put it back',
  footnote: 'Footnote.',
  generalSettingsTitle: 'The same values elsewhere',
  generalSettingsLead: 'One at a time.',
  generalSettingsLabel: 'Open',
  generalSettingsPage: HilosPages.SETTINGS,
  unknownSelectionNote: 'The stored mode is gone.',
  confirmTitle: 'Overwrite your own edits?',
  refusalTitle: "Couldn't change the mode",
  confirmBody: (title) => `${title} writes all of its values.`,
  confirmLabel: (title) => `Apply ${title}`,
  presetTitle: (name) => name.toUpperCase(),
  presetSubtitle: (name) => `subtitle of ${name}`,
  presetIcon: () => 'bi-gear',
  valueLines: (values) => [`level ${String(values.level)}`],
  differenceLine: (difference) => `drift on ${difference.key}`,
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_SETTINGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** One dispatched action, held open so the in-flight state can be observed. */
interface Dispatched {
  action: string
  payload: Record<string, unknown>
  settle: (result: ActionResult) => void
  refuse: (error: ActionError) => void
}

/**
 * A context whose connection replays group frames and whose lifecycle hands back
 * handles the test settles by hand — the screen's whole behavior hangs on when the
 * backend answers, so the answer has to be the test's to time.
 */
function makeContext(): {
  context: HilosSettingPresetsContext
  push: (frame: HilosSettingPresetsState) => void
  dispatched: Dispatched[]
} {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const dispatched: Dispatched[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }

      return () => {}
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      let refuse: (error: ActionError) => void = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      dispatched.push({ action, payload, settle, refuse })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  }

  return {
    context: { connection, actions } as unknown as HilosSettingPresetsContext,
    push(frame: HilosSettingPresetsState): void {
      for (const listener of listeners) {
        listener({ type: SIGNAL, data: frame })
      }
    },
    dispatched,
  }
}

function mountPage(
  context: HilosSettingPresetsContext,
): ComponentFixture<HilosSettingPresetsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosSettingPresetsPage)
  fixture.componentRef.setInput('page', HilosPages.LOGS_SETTINGS)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput('signal', SIGNAL)
  fixture.componentRef.setInput('vocabulary', vocabulary)
  fixture.detectChanges()

  return fixture
}

/** Wait out the microtasks a settled action resolves through, then redraw. */
async function settled(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
  fixture.detectChanges()
}

function el(root: HTMLElement, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

function cardOf(root: HTMLElement, name: string): HTMLButtonElement {
  return el(root, `hilos-setting-preset-${name}`) as HTMLButtonElement
}

// What a card or a button tells a sighted person and a screen reader now.
function buttonState(button: HTMLButtonElement) {
  return {
    nativeDisabled: button.disabled,
    disabledLook: button.classList.contains('disabled'),
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
  }
}

const RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const OFF = { ...RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const LOCKED = { ...RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SPINNING = { ...LOCKED, disabledLook: true, spinner: true }

describe('HilosSettingPresetsPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('a viewer finds every mode disabled, with hidden marks in place of values, and applies none of them', async () => {
    bindSession().handshake(null, true)
    const { context, push, dispatched } = makeContext()
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    push(hiddenState())
    fixture.detectChanges()

    for (const name of ['frugal', 'normal', 'investigation']) {
      const cardEl = cardOf(root, name)
      expect(cardEl.disabled).toBe(true)
      expect(cardEl.getAttribute('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      expect(cardEl.getAttribute('aria-current')).toBeNull()
      expect(cardEl.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
      cardEl.click()
      fixture.detectChanges()
    }
    await settled(fixture)
    expect(dispatched).toHaveLength(0)

    expect(
      el(root, 'hilos-setting-preset-settings-link')?.getAttribute('href'),
    ).toBe('/hilos/settings')
  })

  it('a viewer raises no overwrite question and finds no differences displayed when hidden', async () => {
    bindSession().handshake(null, true)
    const { context, push, dispatched } = makeContext()
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    push(hiddenState())
    fixture.detectChanges()

    const frugal = cardOf(root, 'frugal')
    expect(frugal.disabled).toBe(true)
    frugal.click()
    fixture.detectChanges()

    expect(document.body.textContent).not.toContain('Overwrite your own edits?')
    expect(
      document.querySelector('[data-id="hilos-setting-preset-apply-confirm"]'),
    ).toBeNull()
    expect(el(root, 'hilos-setting-preset-revert')).toBeNull()

    await settled(fixture)
    expect(dispatched).toHaveLength(0)
  })

  it('an admin on a node in the mode applies a mode as today', async () => {
    bindSession().handshake({ id: 1, admin: true }, true)
    const { context, push, dispatched } = makeContext()
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    push(groupState())
    fixture.detectChanges()

    const frugal = cardOf(root, 'frugal')
    expect(frugal.disabled).toBe(false)
    expect(frugal.getAttribute('aria-describedby')).toBeNull()

    frugal.click()
    await settled(fixture)

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'frugal' } },
    ])
  })

  // The production LoadingButton and its 300 ms timer under a real admin
  // session: the card whose click applied at once carries its own flight —
  // locked at once, spinning at 300 ms — and the others are off natively from
  // the first moment. An apply no card pressed — through the confirmation or
  // the button inside the applied card — lights no card at all. The applied
  // card and the view mode stay reasons of their own, in flight as at rest.
  describe('with an apply in flight', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.useRealTimers()
      hilosToasts.clear()
    })

    const DRIFTED = groupState({
      differences: [
        { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
      ],
    })

    /** Mount the page for an administrator and draw the given group frame. */
    function mountForAdmin(frame: HilosSettingPresetsState) {
      const session = bindSession()
      session.handshake({ id: 1, admin: true }, false)
      const { context, push, dispatched } = makeContext()
      const fixture = mountPage(context)
      push(frame)
      fixture.detectChanges()

      return {
        session,
        fixture,
        root: fixture.nativeElement as HTMLElement,
        push,
        dispatched,
      }
    }

    // Every card's state, by the preset it stands for.
    function cardStates(root: HTMLElement) {
      return Object.fromEntries(
        ['frugal', 'normal', 'investigation'].map((name) => [
          name,
          buttonState(cardOf(root, name)),
        ]),
      )
    }

    const ALL_OFF = { frugal: OFF, normal: OFF, investigation: OFF }

    /** Let the fake clock run and the page take what its timers decided. */
    function wait(fixture: ComponentFixture<unknown>, ms: number): void {
      vi.advanceTimersByTime(ms)
      fixture.detectChanges()
    }

    /** Let a settled action resolve through its microtasks, then redraw. */
    async function answered(fixture: ComponentFixture<unknown>): Promise<void> {
      for (let tick = 0; tick < 6; tick += 1) await Promise.resolve()
      fixture.detectChanges()
    }

    function confirmButton(): HTMLButtonElement {
      return document.querySelector(
        '[data-id="hilos-setting-preset-apply-confirm"]',
      ) as HTMLButtonElement
    }

    it('locks the pressed card at once, turns the others off and spins it only at 300 ms', async () => {
      const { fixture, root, dispatched } = mountForAdmin(groupState())
      expect(cardStates(root)).toEqual({
        frugal: RESTING,
        normal: OFF,
        investigation: RESTING,
      })

      cardOf(root, 'frugal').click()
      // Pressed again before change detection: no second handle, and the other
      // card does not take the press over.
      cardOf(root, 'frugal').click()
      cardOf(root, 'investigation').click()
      expect(dispatched).toHaveLength(1)
      expect(dispatched[0]?.payload).toEqual({ preset: 'frugal' })

      fixture.detectChanges()
      expect(cardStates(root)).toEqual({
        frugal: LOCKED,
        normal: OFF,
        investigation: OFF,
      })
      wait(fixture, 299)
      expect(cardStates(root)).toEqual({
        frugal: LOCKED,
        normal: OFF,
        investigation: OFF,
      })
      wait(fixture, 1)
      expect(cardStates(root)).toEqual({
        frugal: SPINNING,
        normal: OFF,
        investigation: OFF,
      })
      cardOf(root, 'frugal').click()
      cardOf(root, 'investigation').click()
      expect(dispatched).toHaveLength(1)

      dispatched[0]?.settle({})
      await answered(fixture)
      expect(cardStates(root)).toEqual({
        frugal: RESTING,
        normal: OFF,
        investigation: RESTING,
      })
    })

    it('changes nothing on screen for a fast reply', async () => {
      const { fixture, root, dispatched } = mountForAdmin(groupState())

      cardOf(root, 'frugal').click()
      fixture.detectChanges()
      wait(fixture, 100)
      expect(buttonState(cardOf(root, 'frugal'))).toEqual(LOCKED)

      dispatched[0]?.settle({})
      await answered(fixture)
      expect(buttonState(cardOf(root, 'frugal'))).toEqual(RESTING)
      wait(fixture, 300)
      expect(buttonState(cardOf(root, 'frugal'))).toEqual(RESTING)
    })

    it('lights no card for the apply the confirmation sent', () => {
      const { fixture, root, dispatched } = mountForAdmin(DRIFTED)

      cardOf(root, 'frugal').click()
      fixture.detectChanges()
      confirmButton().click()
      confirmButton().click()
      expect(dispatched).toHaveLength(1)
      expect(dispatched[0]?.payload).toEqual({ preset: 'frugal' })

      fixture.detectChanges()
      expect(buttonState(confirmButton())).toEqual(LOCKED)
      expect(cardStates(root)).toEqual(ALL_OFF)
      const cancel = Array.from(
        document.querySelectorAll<HTMLButtonElement>(
          '[data-id="modal"] button',
        ),
      ).find((button) => button.textContent?.trim() === 'Cancel')
      expect(cancel?.disabled).toBe(true)

      wait(fixture, 300)
      expect(buttonState(confirmButton())).toEqual(SPINNING)
      expect(cardStates(root)).toEqual(ALL_OFF)
    })

    it('lights no card for putting the applied values back', () => {
      const { fixture, root, dispatched } = mountForAdmin(DRIFTED)
      const revert = () =>
        el(root, 'hilos-setting-preset-revert') as HTMLButtonElement

      revert().click()
      revert().click()
      expect(dispatched).toHaveLength(1)
      expect(dispatched[0]?.payload).toEqual({ preset: 'normal' })

      fixture.detectChanges()
      expect(buttonState(revert())).toEqual(LOCKED)
      expect(cardStates(root)).toEqual(ALL_OFF)

      wait(fixture, 300)
      expect(buttonState(revert())).toEqual(SPINNING)
      expect(cardStates(root)).toEqual(ALL_OFF)
    })

    it('forgets the pressed card once its apply is answered', async () => {
      const { fixture, root, push, dispatched } = mountForAdmin(groupState())

      cardOf(root, 'frugal').click()
      fixture.detectChanges()
      dispatched[0]?.refuse(
        new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
      )
      await answered(fixture)

      push(DRIFTED)
      fixture.detectChanges()
      ;(el(root, 'hilos-setting-preset-revert') as HTMLButtonElement).click()
      fixture.detectChanges()
      expect(dispatched).toHaveLength(2)
      expect(cardStates(root)).toEqual(ALL_OFF)
    })

    it('keeps the applied card and the view mode as reasons of their own in flight', () => {
      const { session, fixture, root, push, dispatched } =
        mountForAdmin(groupState())

      cardOf(root, 'frugal').click()
      fixture.detectChanges()
      expect(dispatched).toHaveLength(1)

      // The new state lands by push before the reply: the pressed card is the
      // applied one now, off natively under its own lock.
      push(groupState({ selected: 'frugal' }))
      fixture.detectChanges()
      expect(cardStates(root)).toEqual({
        frugal: { ...LOCKED, nativeDisabled: true },
        normal: OFF,
        investigation: OFF,
      })

      push(groupState())
      session.handshake(null, true)
      fixture.detectChanges()
      expect(buttonState(cardOf(root, 'frugal'))).toEqual({
        ...LOCKED,
        nativeDisabled: true,
      })
      expect(cardOf(root, 'frugal').getAttribute('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
    })
  })
})
