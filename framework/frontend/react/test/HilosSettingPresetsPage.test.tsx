import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionError,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
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

import { HilosSettingPresetsPage } from '../src/admin/settings/HilosSettingPresetsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
      act(() => {
        for (const listener of listeners) {
          listener({ type: SIGNAL, data: frame })
        }
      })
    },
    dispatched,
  }
}

function mountPage(context: HilosSettingPresetsContext): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosSettingPresetsPage
        page={HilosPages.LOGS_SETTINGS}
        context={context}
        signal={SIGNAL}
        vocabulary={vocabulary}
      />
    </HilosRouterContext.Provider>,
  ).container
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

const card = (name: string): string =>
  `[data-id="hilos-setting-preset-${name}"]`

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function cardOf(
  container: HTMLElement,
  name: string,
): HTMLButtonElement | null {
  return container.querySelector(card(name))
}

function clickConfirm(): void {
  fireEvent.click(
    document.querySelector(
      '[data-id="hilos-setting-preset-apply-confirm"]',
    ) as HTMLElement,
  )
}

describe('HilosSettingPresetsPage', () => {
  // The confirmation is portalled to the document body, so a page left mounted
  // would leave its modal there for the next case to find.
  afterEach(cleanup)

  it('draws no card before the first frame arrives', () => {
    const { context } = makeContext()
    const container = mountPage(context)

    expect(cardOf(container, 'normal')).toBeNull()
  })

  it('draws the cards in the order the frame listed them', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(groupState())

    expect(
      Array.from(
        container.querySelectorAll('[data-id^="hilos-setting-preset-"]'),
      )
        .map((element) => element.getAttribute('data-id'))
        .filter((id) => id !== null && !id.includes('-settings-link')),
    ).toEqual([
      'hilos-setting-preset-frugal',
      'hilos-setting-preset-normal',
      'hilos-setting-preset-investigation',
    ])
  })

  it('marks the applied card current and leaves nothing to press on it', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(groupState())

    const applied = cardOf(container, 'normal')
    expect(applied?.getAttribute('aria-current')).toBe('true')
    expect(applied?.disabled).toBe(true)
    expect(cardOf(container, 'frugal')?.disabled).toBe(false)
    expect(byId(container, 'hilos-setting-preset-differences')).toBeNull()
  })

  it('puts the differences and the one button back inside the applied card', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )

    const drift = byId(container, 'hilos-setting-preset-differences')
    expect(drift).not.toBeNull()
    expect(drift?.textContent).toContain('drift on level')
    expect(byId(container, 'hilos-setting-preset-revert')).not.toBeNull()
  })

  it('applies at once when nothing of the person`s own is at stake', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)

    expect(dispatched).toHaveLength(1)
    expect(dispatched[0]).toMatchObject({
      action: 'setting_preset_apply',
      payload: { preset: 'frugal' },
    })
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('asks first when the click would overwrite hand-made edits', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)

    expect(dispatched).toHaveLength(0)
    expect(document.body.textContent).toContain('Overwrite your own edits?')
    expect(document.body.textContent).toContain(
      'FRUGAL writes all of its values.',
    )
  })

  it('sends the apply only once the confirmation is accepted', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    clickConfirm()

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'frugal' } },
    ])
  })

  it('raises no second question for the button that says what it will do', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    fireEvent.click(
      byId(container, 'hilos-setting-preset-revert') as HTMLElement,
    )

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'normal' } },
    ])
    expect(document.body.textContent).not.toContain('Overwrite your own edits?')
  })

  it('locks every card while the action is in flight, the clicked one by its own flight', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)

    const clicked = cardOf(container, 'frugal') as HTMLButtonElement
    expect(clicked.disabled).toBe(false)
    expect(clicked.getAttribute('aria-disabled')).toBe('true')
    expect(clicked.getAttribute('aria-busy')).toBe('true')
    for (const name of ['normal', 'investigation']) {
      expect(cardOf(container, name)?.disabled).toBe(true)
    }

    fireEvent.click(clicked)
    expect(dispatched).toHaveLength(1)
  })

  it('draws a refusal above the cards when the click applied at once', async () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    expect(container.textContent).toContain('Rule said no.')
  })

  it('draws a refusal inside the confirmation it was sent from', async () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    clickConfirm()
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    expect(document.body.textContent).toContain('Overwrite your own edits?')
    expect(document.body.textContent).toContain('Rule said no.')
  })

  it('takes the refusal with the confirmation rather than moving it over the cards', async () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    clickConfirm()
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    const cancel = [...document.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLElement
    fireEvent.click(cancel)
    await settled()

    expect(container.textContent).not.toContain('Rule said no.')
  })

  it('lights nothing and explains itself when the stored mode is unknown', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(groupState({ selected: null }))

    expect(byId(container, 'hilos-setting-preset-unknown')?.textContent).toBe(
      'The stored mode is gone.',
    )
    for (const name of ['frugal', 'normal', 'investigation']) {
      expect(cardOf(container, name)?.getAttribute('aria-current')).toBeNull()
      expect(cardOf(container, name)?.disabled).toBe(false)
    }
  })

  it('leads to the general settings of the page the vocabulary names', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(groupState())

    expect(
      byId(container, 'hilos-setting-preset-settings-link')?.getAttribute(
        'href',
      ),
    ).toBe('/hilos/settings')
  })
})

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

// Advance the fake clock and let the view render what the timers decided.
function wait(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
}

// The production LoadingButton and its 300 ms timer: the card whose click
// applied at once carries its own flight — locked at once, spinning at 300 ms —
// and the others are off natively from the first moment. An apply no card
// pressed — through the confirmation or the button inside the applied card —
// lights no card at all.
describe('HilosSettingPresetsPage apply in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    cleanup()
    vi.useRealTimers()
  })

  const DRIFTED = groupState({
    differences: [{ key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' }],
  })

  // Every card's state, by the preset it stands for.
  function cardStates(container: HTMLElement) {
    return Object.fromEntries(
      ['frugal', 'normal', 'investigation'].map((name) => [
        name,
        buttonState(cardOf(container, name) as HTMLButtonElement),
      ]),
    )
  }

  function confirmButton(): HTMLButtonElement {
    return document.querySelector(
      '[data-id="hilos-setting-preset-apply-confirm"]',
    ) as HTMLButtonElement
  }

  it('locks the clicked card at once, turns the others off and spins it only at 300 ms', async () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())
    expect(cardStates(container)).toEqual({
      frugal: RESTING,
      normal: OFF,
      investigation: RESTING,
    })

    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    expect(dispatched).toHaveLength(1)
    expect(cardStates(container)).toEqual({
      frugal: LOCKED,
      normal: OFF,
      investigation: OFF,
    })

    wait(299)
    expect(cardStates(container)).toEqual({
      frugal: LOCKED,
      normal: OFF,
      investigation: OFF,
    })
    wait(1)
    expect(cardStates(container)).toEqual({
      frugal: SPINNING,
      normal: OFF,
      investigation: OFF,
    })
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    fireEvent.click(cardOf(container, 'investigation') as HTMLElement)
    expect(dispatched).toHaveLength(1)

    dispatched[0].settle({})
    await settled()
    expect(cardStates(container)).toEqual({
      frugal: RESTING,
      normal: OFF,
      investigation: RESTING,
    })
  })

  it('lights no card for the apply the confirmation sent', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(DRIFTED)
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    clickConfirm()
    expect(dispatched).toHaveLength(1)
    expect(buttonState(confirmButton())).toEqual(LOCKED)
    expect(cardStates(container)).toEqual({
      frugal: OFF,
      normal: OFF,
      investigation: OFF,
    })

    wait(300)
    expect(buttonState(confirmButton())).toEqual(SPINNING)
    expect(cardStates(container)).toEqual({
      frugal: OFF,
      normal: OFF,
      investigation: OFF,
    })
  })

  it('lights no card for putting the applied values back', () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(DRIFTED)
    const revert = byId(
      container,
      'hilos-setting-preset-revert',
    ) as HTMLButtonElement
    fireEvent.click(revert)
    expect(dispatched).toHaveLength(1)
    expect(buttonState(revert)).toEqual(LOCKED)
    expect(cardStates(container)).toEqual({
      frugal: OFF,
      normal: OFF,
      investigation: OFF,
    })

    wait(300)
    expect(buttonState(revert)).toEqual(SPINNING)
    expect(cardStates(container)).toEqual({
      frugal: OFF,
      normal: OFF,
      investigation: OFF,
    })
  })

  it('forgets the pressed card once its apply is answered', async () => {
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    push(DRIFTED)
    fireEvent.click(
      byId(container, 'hilos-setting-preset-revert') as HTMLElement,
    )
    expect(dispatched).toHaveLength(2)
    expect(cardStates(container)).toEqual({
      frugal: OFF,
      normal: OFF,
      investigation: OFF,
    })
  })
})

describe('HilosSettingPresetsPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

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
    const container = mountPage(context)

    push({
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
    })

    for (const name of ['frugal', 'normal', 'investigation']) {
      const cardEl = cardOf(container, name) as HTMLButtonElement
      expect(cardEl.disabled).toBe(true)
      expect(cardEl.getAttribute('aria-describedby')).toBe(
        HILOS_VIEW_MODE_STRIP_TEXT_ID,
      )
      expect(cardEl.getAttribute('aria-current')).toBeNull()
      expect(cardEl.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
      fireEvent.click(cardEl)
    }
    await settled()
    expect(dispatched).toHaveLength(0)

    expect(
      byId(container, 'hilos-setting-preset-settings-link')?.getAttribute(
        'href',
      ),
    ).toBe('/hilos/settings')
  })

  it('a viewer raises no overwrite question and finds no differences displayed when hidden', async () => {
    bindSession().handshake(null, true)
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push({
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
    })

    const frugal = cardOf(container, 'frugal') as HTMLButtonElement
    expect(frugal.disabled).toBe(true)
    fireEvent.click(frugal)

    expect(document.body.textContent).not.toContain('Overwrite your own edits?')
    expect(
      document.querySelector('[data-id="hilos-setting-preset-apply-confirm"]'),
    ).toBeNull()
    expect(byId(container, 'hilos-setting-preset-revert')).toBeNull()

    await settled()
    expect(dispatched).toHaveLength(0)
  })

  it('an admin on a node in the mode applies a mode as today', async () => {
    bindSession().handshake({ id: 1, admin: true }, true)
    const { context, push, dispatched } = makeContext()
    const container = mountPage(context)

    push(groupState())

    const frugal = cardOf(container, 'frugal') as HTMLButtonElement
    expect(frugal.disabled).toBe(false)
    expect(frugal.getAttribute('aria-describedby')).toBeNull()

    fireEvent.click(frugal)
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'frugal' } },
    ])
  })
})
