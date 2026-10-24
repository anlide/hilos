// Covers the Angular Appearance page (HIL-1441) over the real core: the table
// and its frame, both windows over the core row-edit session, a real action
// lifecycle with request correlation, and the admin view mode bound the way
// bootHilos binds it. The cases are the React page's
// (react/test/HilosAppearancePage.test.tsx), read through change detection
// rather than through act(); the merge itself is the core's and is tested there.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HIDDEN_VALUE,
  HILOS_APPEARANCE_SETTINGS_TABLE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosAppearanceSettingKey,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import type {
  Hideable,
  HilosAppearanceContext,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  ThemePosition,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosAppearancePage } from '../src/admin/appearance/HilosAppearancePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

type SettingValue = Hideable<boolean | string>

/** The words of the section the admin header draws, from the page catalog. */
const LEAD = 'Theme switching and the default theme.'

/** The paragraph the Vue page repeats under the header; this page has none. */
const REPEATED_LEAD =
  'Theme switching and the default theme for this installation.'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.APPEARANCE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Appearance',
      lead: LEAD,
      breadcrumb: [],
      children: [],
    }),
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

let fixture: ComponentFixture<HilosAppearancePage> | undefined
const releases: (() => void)[] = []

/** Run change detection over the mounted page, if there is one. */
function update(): void {
  fixture?.detectChanges()
}

afterEach(() => {
  fixture?.destroy()
  fixture = undefined
  for (const release of releases.splice(0)) release()
  TestBed.resetTestingModule()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function harness() {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.APPEARANCE)
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
  const emit = (event: string, frame: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(frame as never)
    update()
  }
  const connection = {
    on(event: string, listener: (frame: never) => void): () => void {
      const callbacks = listeners.get(event) ?? new Set()
      callbacks.add(listener)
      listeners.set(event, callbacks)

      return () => callbacks.delete(listener)
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
      focus.push({ page, tableKey, rowKey })

      return true
    },
    sendAction(
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ): boolean {
      sent.push({ action, payload, requestId })

      return true
    },
  } as unknown as HilosConnection
  const actions = new ActionLifecycle(connection)
  bindSessionScope(connection, scopes)
  releases.push(bindAdminAccess(scopes))

  function row(key: string, value: SettingValue, defaultValue: SettingValue) {
    return {
      rowKey: key,
      slots: { setting: { rowKey: key, value, defaultValue } },
    }
  }

  return {
    context: { connection, scopes, actions } satisfies HilosAppearanceContext,
    sent,
    focus,
    // Answer the last action sent, the way the server replies to it.
    answer(outcome: 'success' | 'fail', message: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      emit(event, {
        kind: event,
        action: last?.action,
        requestId: last?.requestId,
        ...(outcome === 'success' ? { message } : { reason: message }),
      })
    },
    // A guest on a node in the admin view mode.
    viewer(): void {
      emit('projectSignal', {
        kind: 'project',
        type: 'handshake_response',
        data: {
          entities: { currentUser: null },
          data: { adminViewMode: true },
        },
        envelope: {},
      } as unknown as ProjectSignal)
    },
    window(
      enabled: SettingValue = true,
      defaultEnabled: SettingValue = true,
      defaultTheme: SettingValue = 'system',
      catalogDefault: SettingValue = 'system',
    ): void {
      emit('tableWindow', {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rows: [
            row(
              HilosAppearanceSettingKey.switchingEnabled,
              enabled,
              defaultEnabled,
            ),
            row(
              HilosAppearanceSettingKey.defaultTheme,
              defaultTheme,
              catalogDefault,
            ),
          ],
          totalCount: 2,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 2,
        },
      })
    },
    change(key: string, value: SettingValue, defaultValue: SettingValue): void {
      emit('tableViewportDelta', {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          kind: 'row_updated',
          rowKey: key,
          row: row(key, value, defaultValue),
        },
      })
    },
    // Another administrator removed the row.
    remove(key: string): void {
      emit('tableViewportDelta', {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          kind: 'row_removed',
          rowKey: key,
          reason: 'deleted',
        },
      })
    },
  }
}

type Harness = ReturnType<typeof harness>

function mountPage(h: Harness): HTMLElement {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  fixture = TestBed.createComponent(HilosAppearancePage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()

  return fixture.nativeElement as HTMLElement
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/** Click an element and let the page take what followed. */
function click(element: HTMLElement | null): void {
  element?.click()
  update()
}

/** Submit a form the way Enter in one of its fields does. */
function submit(form: Element | null): void {
  form?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  update()
}

function tableText(id: string): string | null | undefined {
  return document.querySelector(`table [data-id="${id}"]`)?.textContent?.trim()
}

async function settle(): Promise<void> {
  for (let tick = 0; tick < 4; tick += 1) await Promise.resolve()
  update()
}

function switchingInput(): HTMLInputElement {
  return byId('appearance-switching-input') as HTMLInputElement
}

function switchingSave(): HTMLButtonElement {
  return byId('appearance-switching-save') as HTMLButtonElement
}

function themeRadio(position: ThemePosition): HTMLInputElement {
  return byId(`appearance-default-theme-${position}`) as HTMLInputElement
}

function themeSave(): HTMLButtonElement {
  return byId('appearance-default-theme-save') as HTMLButtonElement
}

// A row's controls stand in the document twice — once in the table and once in
// the card the same row becomes on a narrow screen — so a row button is looked
// up through the table, which says which of the two is clicked.
function pencil(key: string): HTMLButtonElement {
  return document.querySelector(
    `table [data-id="appearance-setting-edit-${key}"]`,
  ) as HTMLButtonElement
}

function openSwitching(h: Harness, enabled = true, defaultEnabled = true) {
  mountPage(h)
  h.window(enabled, defaultEnabled)
  click(pencil(HilosAppearanceSettingKey.switchingEnabled))
}

function openDefaultTheme(
  h: Harness,
  value: SettingValue = 'system',
  catalogDefault: SettingValue = 'system',
): HTMLButtonElement {
  mountPage(h)
  h.window(true, true, value, catalogDefault)
  const button = pencil(HilosAppearanceSettingKey.defaultTheme)
  button.focus()
  click(button)

  return button
}

describe('HilosAppearancePage', () => {
  it('draws the two typed settings in the core frame right under the admin header', () => {
    const h = harness()
    const root = mountPage(h)
    expect(byId('hilos-table-loading')).not.toBeNull()

    h.window(true, true, 'system', 'system')

    expect(byId('hilos-admin-title')?.textContent?.trim()).toBe('Appearance')
    const section = byId('hilos-admin-page') as HTMLElement
    const paragraphs = Array.from(section.querySelectorAll('p')).filter(
      (paragraph) =>
        paragraph.closest('table, [data-id="hilos-table-cards"]') === null,
    )
    expect(
      paragraphs.map((paragraph) => paragraph.textContent?.trim()),
    ).toEqual([LEAD])
    expect(root.textContent).not.toContain(REPEATED_LEAD)

    const headers = Array.from(root.querySelectorAll('table thead th')).map(
      (header) => header.textContent?.trim(),
    )
    expect(headers.slice(0, 4)).toEqual([
      'Setting',
      'Current value',
      'Default',
      '',
    ])
    expect(headers).toHaveLength(5)
    expect(
      root
        .querySelector('table thead th:last-child .visually-hidden')
        ?.textContent?.trim(),
    ).toBe('Row state and controls')
    expect(root.querySelector('input[type="search"]')).toBeNull()
    expect(root.querySelector('table')?.textContent).toContain(
      'Theme switching',
    )
    expect(root.querySelector('table')?.textContent).toContain('Default theme')
    expect(
      tableText(
        `appearance-setting-value-${HilosAppearanceSettingKey.switchingEnabled}`,
      ),
    ).toBe('On')
    expect(
      tableText(
        `appearance-setting-default-${HilosAppearanceSettingKey.switchingEnabled}`,
      ),
    ).toBe('On')
    expect(
      tableText(
        `appearance-setting-value-${HilosAppearanceSettingKey.defaultTheme}`,
      ),
    ).toBe('System')
    expect(
      tableText(
        `appearance-setting-default-${HilosAppearanceSettingKey.defaultTheme}`,
      ),
    ).toBe('System')

    const pencils = root.querySelectorAll<HTMLButtonElement>(
      'table button[data-id^="appearance-setting-edit-"]',
    )
    expect(Array.from(pencils).map((button) => button.dataset['id'])).toEqual([
      'appearance-setting-edit-theme.switching_enabled',
      'appearance-setting-edit-theme.default',
    ])
    for (const [button, name] of [
      [pencils[0], 'Edit Theme switching'],
      [pencils[1], 'Edit Default theme'],
    ] as const) {
      expect(button?.getAttribute('aria-label')).toBe(name)
      expect(button?.getAttribute('title')).toBe(name)
      expect(button?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
      expect(button?.querySelector('span')?.textContent).toBe('Edit')
      expect(button?.querySelector('span')?.classList).toContain('d-md-none')
    }
    for (const id of [
      'appearance-setting-edit-theme.switching_enabled',
      'appearance-setting-edit-theme.default',
    ]) {
      expect(
        root.querySelector(
          `[data-id="hilos-table-cards"] button[data-id="${id}"]`,
        ),
      ).not.toBeNull()
    }
  })

  it('follows Off, On and a new default theme as they arrive', () => {
    const h = harness()
    mountPage(h)
    h.window()

    h.change(HilosAppearanceSettingKey.switchingEnabled, false, true)
    expect(
      tableText(
        `appearance-setting-value-${HilosAppearanceSettingKey.switchingEnabled}`,
      ),
    ).toBe('Off')
    expect(
      tableText(
        `appearance-setting-default-${HilosAppearanceSettingKey.switchingEnabled}`,
      ),
    ).toBe('On')

    h.change(HilosAppearanceSettingKey.switchingEnabled, true, true)
    expect(
      tableText(
        `appearance-setting-value-${HilosAppearanceSettingKey.switchingEnabled}`,
      ),
    ).toBe('On')

    h.change(HilosAppearanceSettingKey.defaultTheme, 'dark', 'system')
    expect(
      tableText(
        `appearance-setting-value-${HilosAppearanceSettingKey.defaultTheme}`,
      ),
    ).toBe('Dark')
    expect(
      tableText(
        `appearance-setting-default-${HilosAppearanceSettingKey.defaultTheme}`,
      ),
    ).toBe('System')
  })
})

describe('HilosAppearancePage Theme switching window', () => {
  it.each([true, false])(
    'opens with the saved value %s, its default, the room of its messages and Save locked',
    (enabled) => {
      const h = harness()
      openSwitching(h, enabled, enabled)
      expect(switchingInput().checked).toBe(enabled)
      expect(switchingInput().getAttribute('role')).toBe('switch')
      expect(switchingInput().hasAttribute('data-autofocus')).toBe(true)
      expect(switchingInput().disabled).toBe(false)
      expect(switchingSave().disabled).toBe(true)
      const modal = byId('modal') as HTMLElement
      expect(modal.getAttribute('aria-label')).toBe('Edit · Theme switching')
      expect(modal.textContent).toContain('Edit · Theme switching')
      expect(modal.textContent).toContain(`default: ${enabled ? 'On' : 'Off'}`)
      expect(
        modal.querySelector('[data-id="hilos-action-error-slot"]'),
      ).not.toBeNull()
      expect(
        modal.querySelector('[data-id="appearance-switching-notice-idle"]'),
      ).not.toBeNull()
      expect(h.focus).toEqual([
        {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rowKey: HilosAppearanceSettingKey.switchingEnabled,
        },
      ])
      expect(h.sent).toEqual([])
    },
  )

  it('sends the changed boolean, waits for success, releases focus and toasts the server sentence', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    expect(switchingInput().checked).toBe(false)
    expect(switchingSave().disabled).toBe(false)

    click(switchingSave())
    await settle()
    expect(h.sent).toEqual([
      {
        action: 'appearance_theme_switching_set',
        payload: { enabled: false },
        requestId: expect.any(String),
      },
    ])
    expect(byId('modal')).not.toBeNull()
    expect(switchingSave().getAttribute('aria-disabled')).toBe('true')
    expect(tableText('appearance-setting-value-theme.switching_enabled')).toBe(
      'On',
    )

    h.answer('success', 'Theme switching saved.')
    await settle()
    expect(byId('modal')).toBeNull()
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Theme switching saved.']])
  })

  it('keeps a refused write and its draft in the window', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    click(switchingSave())
    await settle()

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(byId('modal')).not.toBeNull()
    expect(switchingInput().checked).toBe(false)
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The setting could not be saved.',
    )
    expect(switchingSave().disabled).toBe(false)
  })

  it('follows a pristine update from another administrator through the focused row', () => {
    const h = harness()
    openSwitching(h)
    h.change(HilosAppearanceSettingKey.switchingEnabled, false, true)
    expect(switchingInput().checked).toBe(false)
    expect(byId('appearance-switching-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(switchingSave().disabled).toBe(true)
  })

  it('asks to discard a dirty draft when Cancel is pressed', () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    click(byId('appearance-switching-cancel'))
    expect(byId('modal-confirm')?.textContent).toContain('Discard changes?')
    expect(byId('modal')).not.toBeNull()
    expect(h.sent).toEqual([])
  })

  it('saves a changed draft on Enter in the form, through the same door', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    submit(document.querySelector('[data-id="modal"] form'))
    await settle()
    expect(h.sent).toEqual([
      {
        action: 'appearance_theme_switching_set',
        payload: { enabled: false },
        requestId: expect.any(String),
      },
    ])
  })
})

// What a window's Save tells a sighted person and a screen reader now.
function saveState(save: HTMLButtonElement) {
  return {
    nativeDisabled: save.disabled,
    disabledLook: save.classList.contains('disabled'),
    spinner: save.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: save.getAttribute('aria-busy'),
    ariaDisabled: save.getAttribute('aria-disabled'),
  }
}

const SAVE_RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const SAVE_OFF = { ...SAVE_RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const SAVE_LOCKED = { ...SAVE_RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SAVE_SPINNING = { ...SAVE_LOCKED, disabledLook: true, spinner: true }

/** Enter in the open window: the submit of its form, before the view catches up. */
function enter(): void {
  document
    .querySelector('[data-id="modal"] form')
    ?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}

/** Let the fake clock run and the page take what its timers decided. */
function wait(ms: number): void {
  vi.advanceTimersByTime(ms)
  update()
}

// The production LoadingButton, ConflictActions and ActionLifecycle together,
// Save fed by the window's saveAvailable: the button's own 300 ms timer is the
// only delay, so the spinner never waits for the lifecycle's deferred loading
// on top of it, and the own save in flight never turns Save off natively —
// only a reason of its own does.
describe('HilosAppearancePage Save in flight', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('locks Save at once on the first send and greys it only with its spinner at 300 ms', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    expect(saveState(switchingSave())).toEqual(SAVE_RESTING)

    switchingSave().click()
    expect(h.sent.map((entry) => entry.action)).toEqual([
      'appearance_theme_switching_set',
    ])
    // Pressed again before the view has caught up: the window's own door holds.
    switchingSave().click()
    enter()
    expect(h.sent).toHaveLength(1)

    update()
    expect(saveState(switchingSave())).toEqual(SAVE_LOCKED)
    click(switchingSave())
    enter()
    expect(h.sent).toHaveLength(1)

    wait(299)
    expect(saveState(switchingSave())).toEqual(SAVE_LOCKED)
    wait(1)
    expect(saveState(switchingSave())).toEqual(SAVE_SPINNING)
    click(switchingSave())
    enter()
    expect(h.sent).toHaveLength(1)

    h.answer('success', 'Theme switching saved.')
    await settle()
    expect(byId('modal')).toBeNull()
  })

  it('returns Save to rest on a refusal and lets the next press send', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    click(switchingSave())
    wait(300)
    expect(saveState(switchingSave())).toEqual(SAVE_SPINNING)

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(byId('hilos-action-error')).not.toBeNull()
    expect(saveState(switchingSave())).toEqual(SAVE_RESTING)

    click(switchingSave())
    expect(h.sent).toHaveLength(2)
  })

  it('turns Save off at once for a draft with nothing to save', () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    click(switchingInput())
    expect(saveState(switchingSave())).toEqual(SAVE_OFF)

    click(switchingSave())
    wait(300)
    expect(saveState(switchingSave())).toEqual(SAVE_OFF)
    enter()
    expect(h.sent).toEqual([])
  })

  it('turns Save off at once for a choice over a value the catalog does not know', () => {
    const h = harness()
    openDefaultTheme(h, 'sepia')
    click(themeRadio('dark'))
    expect(saveState(themeSave())).toEqual(SAVE_OFF)

    click(themeSave())
    wait(300)
    expect(saveState(themeSave())).toEqual(SAVE_OFF)
    enter()
    expect(h.sent).toEqual([])
  })

  it('turns Save off for a removal that comes while the save flies', async () => {
    const h = harness()
    openSwitching(h)
    click(switchingInput())
    click(switchingSave())
    expect(saveState(switchingSave())).toEqual(SAVE_LOCKED)

    h.remove(HilosAppearanceSettingKey.switchingEnabled)
    expect(saveState(switchingSave())).toEqual({
      ...SAVE_LOCKED,
      nativeDisabled: true,
    })
    expect(switchingSave().textContent?.trim()).toBe('Deleted')

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(saveState(switchingSave())).toEqual(SAVE_OFF)
    expect(switchingSave().textContent?.trim()).toBe('Deleted')
  })

  it('turns Save off for a conflict that comes while the save flies', async () => {
    const h = harness()
    openDefaultTheme(h)
    click(themeRadio('dark'))
    click(themeSave())
    expect(saveState(themeSave())).toEqual(SAVE_LOCKED)

    h.change(HilosAppearanceSettingKey.defaultTheme, 'light', 'system')
    expect(byId('conflict-accept-mine')).not.toBeNull()
    expect(saveState(themeSave())).toEqual({
      ...SAVE_LOCKED,
      nativeDisabled: true,
    })

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(saveState(themeSave())).toEqual(SAVE_OFF)
  })

  it('turns Save off at once in the admin view mode', () => {
    const h = harness()
    h.viewer()
    openSwitching(h)
    click(switchingInput())
    expect(saveState(switchingSave())).toEqual(SAVE_OFF)

    click(switchingSave())
    wait(300)
    expect(saveState(switchingSave())).toEqual(SAVE_OFF)
    enter()
    expect(h.sent).toEqual([])
  })
})

describe('HilosAppearancePage Default theme window', () => {
  it.each<ThemePosition>(['light', 'dark', 'system'])(
    'opens with %s checked, named radios, the catalog default and Save locked',
    (position) => {
      const h = harness()
      openDefaultTheme(h, position)
      expect(document.activeElement).toBe(themeRadio(position))
      const modal = byId('modal') as HTMLElement
      expect(modal.getAttribute('aria-label')).toBe('Edit · Default theme')
      expect(modal.textContent).toContain('Edit · Default theme')
      expect(modal.querySelector('legend')?.textContent).toBe('Default theme')
      expect(modal.querySelectorAll('input[type="radio"]')).toHaveLength(3)
      for (const [value, label, icon] of [
        ['light', 'Light', 'bi-sun'],
        ['dark', 'Dark', 'bi-moon-stars'],
        ['system', 'System', 'bi-circle-half'],
      ] as const) {
        const radio = themeRadio(value)
        expect(radio.checked).toBe(value === position)
        expect(radio.disabled).toBe(false)
        expect(radio.name).toBe('appearance-default-theme')
        const named = modal.querySelector(`label[for="${radio.id}"]`)!
        expect(named.textContent).toContain(label)
        expect(
          named.querySelector(`i.${icon}`)?.getAttribute('aria-hidden'),
        ).toBe('true')
        expect(named.querySelector('.badge') !== null).toBe(value === 'system')
      }
      expect(
        modal
          .querySelector('legend')
          ?.closest('fieldset')
          ?.getAttribute('aria-describedby'),
      ).toBe('appearance-default-theme-hint')
      expect(
        modal.querySelector('[data-id="appearance-default-theme-notice-idle"]'),
      ).not.toBeNull()
      expect(themeSave().disabled).toBe(true)
      expect(h.focus).toEqual([
        {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          rowKey: HilosAppearanceSettingKey.defaultTheme,
        },
      ])
      expect(h.sent).toEqual([])
    },
  )

  it('waits for success, keeps the table until its echo, returns focus and toasts the server sentence', async () => {
    const h = harness()
    const button = openDefaultTheme(h)
    click(themeRadio('dark'))
    expect(themeRadio('dark').checked).toBe(true)
    expect(themeSave().disabled).toBe(false)

    click(themeSave())
    await settle()
    expect(h.sent).toEqual([
      {
        action: 'appearance_default_theme_set',
        payload: { defaultTheme: 'dark' },
        requestId: expect.any(String),
      },
    ])
    expect(byId('modal')).not.toBeNull()
    expect(tableText('appearance-setting-value-theme.default')).toBe('System')

    h.change(HilosAppearanceSettingKey.defaultTheme, 'dark', 'system')
    expect(byId('modal')).not.toBeNull()
    h.answer('success', 'Default theme saved.')
    await settle()
    expect(byId('modal')).toBeNull()
    expect(tableText('appearance-setting-value-theme.default')).toBe('Dark')
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(document.activeElement).toBe(button)
    expect(
      hilosToasts.toasts.get().map((toast) => [toast.severity, toast.message]),
    ).toEqual([['success', 'Default theme saved.']])
  })

  it('keeps a refused choice and shows the server error', async () => {
    const h = harness()
    openDefaultTheme(h)
    click(themeRadio('dark'))
    click(themeSave())
    await settle()

    h.answer('fail', 'The setting could not be saved.')
    await settle()
    expect(byId('modal')).not.toBeNull()
    expect(themeRadio('dark').checked).toBe(true)
    expect(byId('hilos-action-error')?.textContent).toContain(
      'The setting could not be saved.',
    )
    expect(themeSave().disabled).toBe(false)
  })

  it.each(['mine', 'theirs'] as const)(
    'blocks a conflict, offers both values and accepts %s',
    (choice) => {
      const h = harness()
      openDefaultTheme(h)
      click(themeRadio('dark'))
      h.change(HilosAppearanceSettingKey.defaultTheme, 'light', 'system')
      expect(themeRadio('dark').checked).toBe(true)
      expect(themeSave().disabled).toBe(true)
      expect(byId('conflict-badge')).not.toBeNull()
      expect(byId('appearance-default-theme-notice')?.textContent).toContain(
        'Changed elsewhere to "Light".',
      )
      expect(byId('conflict-accept-mine')?.textContent).toContain('Keep mine')
      expect(byId('conflict-accept-theirs')?.textContent).toContain(
        'Take theirs',
      )

      click(themeSave())
      submit(document.querySelector('[data-id="modal"] form'))
      expect(h.sent).toEqual([])

      click(byId(`conflict-accept-${choice}`))
      expect(byId('conflict-choices')).toBeNull()
      expect(themeRadio(choice === 'mine' ? 'dark' : 'light').checked).toBe(
        true,
      )
      expect(themeSave().disabled).toBe(choice === 'theirs')
    },
  )

  it('asks to discard a dirty choice and releases focus only after confirmation', () => {
    const h = harness()
    const button = openDefaultTheme(h)
    click(themeRadio('dark'))
    click(byId('appearance-default-theme-cancel'))
    expect(byId('modal-confirm')?.textContent).toContain('Discard changes?')
    expect(h.focus.at(-1)?.rowKey).toBe(HilosAppearanceSettingKey.defaultTheme)

    click(byId('modal-confirm-cancel'))
    expect(themeRadio('dark').checked).toBe(true)

    click(byId('appearance-default-theme-cancel'))
    click(byId('modal-confirm-discard'))
    expect(byId('modal')).toBeNull()
    expect(h.focus.at(-1)?.rowKey).toBe('')
    expect(document.activeElement).toBe(button)
    expect(h.sent).toEqual([])
  })

  it('never checks a fallback radio or writes an unknown value', () => {
    const h = harness()
    openDefaultTheme(h, 'sepia')
    for (const position of ['light', 'dark', 'system'] as const) {
      expect(themeRadio(position).checked).toBe(false)
    }
    expect(themeSave().disabled).toBe(true)
    submit(document.querySelector('[data-id="modal"] form'))
    expect(h.sent).toEqual([])
  })
})

describe('HilosAppearancePage in the admin view mode', () => {
  it('asks a viewer before Escape discards a moved field', () => {
    const h = harness()
    h.viewer()
    openSwitching(h)
    click(switchingInput())
    expect(switchingInput().checked).toBe(false)
    switchingInput().dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    update()
    expect(byId('modal-confirm')?.textContent).toContain('Discard changes?')
    click(byId('modal-confirm-discard'))
    expect(byId('modal')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('lets a viewer open both windows and move their fields, and dispatches nothing', () => {
    const h = harness()
    h.viewer()
    openSwitching(h)
    click(switchingInput())
    expect(switchingInput().disabled).toBe(false)
    expect(switchingInput().checked).toBe(false)
    expect(switchingSave().disabled).toBe(true)
    expect(switchingSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    click(switchingSave())
    submit(document.querySelector('[data-id="modal"] form'))
    expect(h.sent).toEqual([])
    const cancel = byId('appearance-switching-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    click(cancel)
    click(byId('modal-confirm-discard'))
    expect(byId('modal')).toBeNull()

    click(pencil(HilosAppearanceSettingKey.defaultTheme))
    click(themeRadio('dark'))
    expect(themeRadio('dark').disabled).toBe(false)
    expect(themeRadio('dark').checked).toBe(true)
    expect(themeSave().disabled).toBe(true)
    expect(themeSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    click(themeSave())
    submit(document.querySelector('[data-id="modal"] form'))
    expect(h.sent).toEqual([])
    expect(
      (byId('appearance-default-theme-cancel') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('shows the hidden mark in the table and instead of the fields of a hidden value', () => {
    const h = harness()
    h.viewer()
    mountPage(h)
    h.window(HIDDEN_VALUE, true, HIDDEN_VALUE, 'system')
    expect(
      document.querySelector(
        'table [data-id="appearance-setting-value-theme.switching_enabled"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      tableText('appearance-setting-default-theme.switching_enabled'),
    ).toBe('On')

    click(pencil(HilosAppearanceSettingKey.switchingEnabled))
    let modal = byId('modal') as HTMLElement
    expect(modal.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(modal.querySelector('input')).toBeNull()
    expect(switchingSave().disabled).toBe(true)
    click(byId('appearance-switching-cancel'))
    expect(byId('modal-confirm')).toBeNull()
    expect(byId('modal')).toBeNull()

    click(pencil(HilosAppearanceSettingKey.defaultTheme))
    modal = byId('modal') as HTMLElement
    expect(modal.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(modal.querySelector('input')).toBeNull()
    expect(themeSave().disabled).toBe(true)
    expect(h.sent).toEqual([])
  })
})
