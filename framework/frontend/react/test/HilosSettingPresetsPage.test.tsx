import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { ActionError, HilosPages, createSignal } from '@hilos/core'
import type {
  ActionHandle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  HilosSettingPresetsContext,
  HilosSettingPresetsState,
  HilosSettingPresetsVocabulary,
  PageRouteMatch,
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

  it('disables every card while the action is in flight', () => {
    const { context, push } = makeContext()
    const container = mountPage(context)

    push(groupState())
    fireEvent.click(cardOf(container, 'frugal') as HTMLElement)

    for (const name of ['frugal', 'normal', 'investigation']) {
      expect(cardOf(container, name)?.disabled).toBe(true)
    }
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
