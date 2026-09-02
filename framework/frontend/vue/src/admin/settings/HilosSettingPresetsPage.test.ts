import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { ActionError, createSignal, HilosPages } from '@hilos/core'
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

import HilosSettingPresetsPage from './HilosSettingPresetsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
      for (const listener of listeners) {
        listener({ type: SIGNAL, data: frame })
      }
    },
    dispatched,
  }
}

// The confirmation is teleported to the document body, so a wrapper left mounted
// would leave its modal there for the next case to find.
const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

function mountPage(context: HilosSettingPresetsContext) {
  const wrapper = mount(HilosSettingPresetsPage, {
    props: {
      page: HilosPages.LOGS_SETTINGS,
      context,
      signal: SIGNAL,
      vocabulary,
    },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)

  return wrapper
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

const card = (name: string): string =>
  `[data-id="hilos-setting-preset-${name}"]`

describe('HilosSettingPresetsPage', () => {
  it('draws no card before the first frame arrives', () => {
    const { context } = makeContext()
    const wrapper = mountPage(context)

    expect(wrapper.find(card('normal')).exists()).toBe(false)
  })

  it('draws the cards in the order the frame listed them', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()

    expect(
      wrapper
        .findAll('[data-id^="hilos-setting-preset-"]')
        .map((element) => element.attributes('data-id'))
        .filter(
          (id) =>
            id?.startsWith('hilos-setting-preset-') &&
            !id.includes('-settings-link'),
        ),
    ).toEqual([
      'hilos-setting-preset-frugal',
      'hilos-setting-preset-normal',
      'hilos-setting-preset-investigation',
    ])
  })

  it('marks the applied card current and leaves nothing to press on it', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()

    const applied = wrapper.find(card('normal'))
    expect(applied.attributes('aria-current')).toBe('true')
    expect(applied.attributes('disabled')).toBeDefined()
    expect(wrapper.find(card('frugal')).attributes('disabled')).toBeUndefined()
    expect(
      wrapper.find('[data-id="hilos-setting-preset-differences"]').exists(),
    ).toBe(false)
  })

  it('puts the differences and the one button back inside the applied card', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()

    const drift = wrapper.find('[data-id="hilos-setting-preset-differences"]')
    expect(drift.exists()).toBe(true)
    expect(drift.text()).toContain('drift on level')
    expect(
      wrapper.find('[data-id="hilos-setting-preset-revert"]').exists(),
    ).toBe(true)
  })

  it('applies at once when nothing of the person`s own is at stake', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')

    expect(dispatched).toHaveLength(1)
    expect(dispatched[0]).toMatchObject({
      action: 'setting_preset_apply',
      payload: { preset: 'frugal' },
    })
    expect(wrapper.find('.modal').exists()).toBe(false)
  })

  it('asks first when the click would overwrite hand-made edits', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')

    expect(dispatched).toHaveLength(0)
    expect(document.body.textContent).toContain('Overwrite your own edits?')
    expect(document.body.textContent).toContain(
      'FRUGAL writes all of its values.',
    )
  })

  it('sends the apply only once the confirmation is accepted', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')
    await nextTick()

    const confirm = document.querySelector(
      '[data-id="hilos-setting-preset-apply-confirm"]',
    ) as HTMLElement
    confirm.click()
    await nextTick()

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'frugal' } },
    ])
  })

  it('raises no second question for the button that says what it will do', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()
    await wrapper
      .find('[data-id="hilos-setting-preset-revert"]')
      .trigger('click')

    expect(dispatched).toMatchObject([
      { action: 'setting_preset_apply', payload: { preset: 'normal' } },
    ])
    expect(document.body.textContent).not.toContain('Overwrite your own edits?')
  })

  it('disables every card while the action is in flight', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')
    await nextTick()

    for (const name of ['frugal', 'normal', 'investigation']) {
      expect(wrapper.find(card(name)).attributes('disabled')).toBeDefined()
    }
  })

  it('draws a refusal above the cards when the click applied at once', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    expect(wrapper.text()).toContain('Rule said no.')
  })

  it('draws a refusal inside the confirmation it was sent from', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')
    await nextTick()
    const confirm = document.querySelector(
      '[data-id="hilos-setting-preset-apply-confirm"]',
    ) as HTMLElement
    confirm.click()
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    expect(document.body.textContent).toContain('Overwrite your own edits?')
    expect(document.body.textContent).toContain('Rule said no.')
  })

  it('takes the refusal with the confirmation rather than moving it over the cards', async () => {
    const { context, push, dispatched } = makeContext()
    const wrapper = mountPage(context)

    push(
      groupState({
        differences: [
          { key: 'level', presetValue: 'INFO', currentValue: 'DEBUG' },
        ],
      }),
    )
    await nextTick()
    await wrapper.find(card('frugal')).trigger('click')
    await nextTick()
    const confirm = document.querySelector(
      '[data-id="hilos-setting-preset-apply-confirm"]',
    ) as HTMLElement
    confirm.click()
    dispatched[0].refuse(
      new ActionError('setting_preset_apply', 'fail', 'Rule said no.'),
    )
    await settled()

    const cancel = [...document.querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'Cancel',
    ) as HTMLElement
    cancel.click()
    await settled()

    expect(wrapper.text()).not.toContain('Rule said no.')
  })

  it('lights nothing and explains itself when the stored mode is unknown', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(groupState({ selected: null }))
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-setting-preset-unknown"]').text(),
    ).toBe('The stored mode is gone.')
    for (const name of ['frugal', 'normal', 'investigation']) {
      expect(
        wrapper.find(card(name)).attributes('aria-current'),
      ).toBeUndefined()
      expect(wrapper.find(card(name)).attributes('disabled')).toBeUndefined()
    }
  })

  it('leads to the general settings of the page the vocabulary names', async () => {
    const { context, push } = makeContext()
    const wrapper = mountPage(context)

    push(groupState())
    await nextTick()

    expect(
      wrapper
        .find('[data-id="hilos-setting-preset-settings-link"]')
        .attributes('href'),
    ).toBe('/hilos/settings')
  })
})
