import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosAppearancePage } from '../src/admin/appearance/HilosAppearancePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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

function harness() {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.APPEARANCE)
  const windowListeners = new Set<(frame: { data: unknown }) => void>()
  const deltaListeners = new Set<(frame: { data: unknown }) => void>()
  const sessionListeners = new Set<(signal: ProjectSignal) => void>()
  const replyListeners = new Map<string, Set<(frame: unknown) => void>>()
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const focus: Array<{ page: string; tableKey: string; rowKey: string }> = []
  const connection = {
    on(event: string, listener: (frame: unknown) => void): () => void {
      if (event === 'tableWindow')
        windowListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'tableViewportDelta')
        deltaListeners.add(listener as (frame: { data: unknown }) => void)
      if (event === 'projectSignal')
        sessionListeners.add(listener as (signal: ProjectSignal) => void)
      if (event === 'actionSuccess' || event === 'actionError') {
        const listeners = replyListeners.get(event) ?? new Set()
        listeners.add(listener)
        replyListeners.set(event, listeners)
      }

      return () => {
        windowListeners.delete(listener as (frame: { data: unknown }) => void)
        deltaListeners.delete(listener as (frame: { data: unknown }) => void)
        sessionListeners.delete(listener as (signal: ProjectSignal) => void)
        replyListeners.get(event)?.delete(listener)
      }
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
  const releaseAdmin = bindAdminAccess(scopes)

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
      act(() => {
        for (const listener of replyListeners.get(event) ?? []) {
          listener({
            kind: event,
            action: last?.action,
            requestId: last?.requestId,
            ...(outcome === 'success' ? { message } : { reason: message }),
          })
        }
      })
    },
    // A guest on a node in the admin view mode.
    viewer(): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: {
          entities: { currentUser: null },
          data: { adminViewMode: true },
        },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of sessionListeners) listener(signal)
    },
    window(
      enabled: SettingValue = true,
      defaultEnabled: SettingValue = true,
      defaultTheme: SettingValue = 'system',
      catalogDefault: SettingValue = 'system',
    ): void {
      const frame = {
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
      }
      act(() => {
        for (const listener of windowListeners) listener(frame)
      })
    },
    change(key: string, value: SettingValue, defaultValue: SettingValue): void {
      const frame = {
        data: {
          page: HilosPages.APPEARANCE,
          tableKey: HILOS_APPEARANCE_SETTINGS_TABLE,
          kind: 'row_updated',
          rowKey: key,
          row: row(key, value, defaultValue),
        },
      }
      act(() => {
        for (const listener of deltaListeners) listener(frame)
      })
    },
    dispose(): void {
      releaseAdmin()
    },
  }
}

type Harness = ReturnType<typeof harness>

const releases: Array<() => void> = []

afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
  document.body.classList.remove('modal-open')
  hilosToasts.clear()
})

function renderPage(h: Harness) {
  releases.push(() => h.dispose())

  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosAppearancePage context={h.context} />
    </HilosRouterContext.Provider>,
  )
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function tableText(id: string): string | null | undefined {
  return document.querySelector(`table [data-id="${id}"]`)?.textContent
}

function settle(): Promise<void> {
  return act(async () => {
    for (let tick = 0; tick < 4; tick += 1) await Promise.resolve()
  })
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

function pencil(key: string): HTMLButtonElement {
  return document.querySelector(
    `table [data-id="appearance-setting-edit-${key}"]`,
  ) as HTMLButtonElement
}

function openSwitching(h: Harness, enabled = true, defaultEnabled = true) {
  renderPage(h)
  h.window(enabled, defaultEnabled)
  fireEvent.click(pencil(HilosAppearanceSettingKey.switchingEnabled))
}

function openDefaultTheme(
  h: Harness,
  value: SettingValue = 'system',
  catalogDefault: SettingValue = 'system',
): HTMLButtonElement {
  renderPage(h)
  h.window(true, true, value, catalogDefault)
  const button = pencil(HilosAppearanceSettingKey.defaultTheme)
  button.focus()
  fireEvent.click(button)

  return button
}

describe('HilosAppearancePage', () => {
  it('draws the two typed settings in the core frame right under the admin header', () => {
    const h = harness()
    const { container } = renderPage(h)
    expect(byId('hilos-table-loading')).not.toBeNull()

    h.window(true, true, 'system', 'system')

    expect(byId('hilos-admin-title')?.textContent).toBe('Appearance')
    const section = byId('hilos-admin-page') as HTMLElement
    const paragraphs = Array.from(section.querySelectorAll('p')).filter(
      (paragraph) =>
        paragraph.closest('table, [data-id="hilos-table-cards"]') === null,
    )
    expect(paragraphs.map((paragraph) => paragraph.textContent)).toEqual([LEAD])
    expect(container.textContent).not.toContain(REPEATED_LEAD)

    const headers = Array.from(
      container.querySelectorAll('table thead th'),
    ).map((header) => header.textContent?.trim())
    expect(headers).toEqual([
      'Setting',
      'Current value',
      'Default',
      '',
      'Will move Will leaveRow state and controls',
    ])
    expect(container.querySelector('input[type="search"]')).toBeNull()
    expect(container.querySelector('table')?.textContent).toContain(
      'Theme switching',
    )
    expect(container.querySelector('table')?.textContent).toContain(
      'Default theme',
    )
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

    const pencils = container.querySelectorAll<HTMLButtonElement>(
      'table button[data-id^="appearance-setting-edit-"]',
    )
    expect(Array.from(pencils).map((button) => button.dataset.id)).toEqual([
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
        container.querySelector(
          `[data-id="hilos-table-cards"] button[data-id="${id}"]`,
        ),
      ).not.toBeNull()
    }
  })

  it('follows Off, On and a new default theme as they arrive', () => {
    const h = harness()
    renderPage(h)
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
    fireEvent.click(switchingInput())
    expect(switchingInput().checked).toBe(false)
    expect(switchingSave().disabled).toBe(false)

    fireEvent.click(switchingSave())
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
    fireEvent.click(switchingInput())
    fireEvent.click(switchingSave())
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
    fireEvent.click(switchingInput())
    fireEvent.click(byId('appearance-switching-cancel') as HTMLElement)
    expect(byId('modal-confirm')?.textContent).toContain('Discard changes?')
    expect(byId('modal')).not.toBeNull()
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
    fireEvent.click(themeRadio('dark'))
    expect(themeRadio('dark').checked).toBe(true)
    expect(themeSave().disabled).toBe(false)

    fireEvent.click(themeSave())
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
    fireEvent.click(themeRadio('dark'))
    fireEvent.click(themeSave())
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
      fireEvent.click(themeRadio('dark'))
      h.change(HilosAppearanceSettingKey.defaultTheme, 'light', 'system')
      expect(themeRadio('dark').checked).toBe(true)
      expect(themeSave().disabled).toBe(true)
      expect(byId('appearance-default-theme-notice')?.textContent).toContain(
        'Changed elsewhere to "Light".',
      )
      expect(byId('conflict-accept-mine')?.textContent).toContain('Keep mine')
      expect(byId('conflict-accept-theirs')?.textContent).toContain(
        'Take theirs',
      )

      fireEvent.click(themeSave())
      expect(h.sent).toEqual([])

      fireEvent.click(byId(`conflict-accept-${choice}`) as HTMLElement)
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
    fireEvent.click(themeRadio('dark'))
    fireEvent.click(byId('appearance-default-theme-cancel') as HTMLElement)
    expect(byId('modal-confirm')?.textContent).toContain('Discard changes?')
    expect(h.focus.at(-1)?.rowKey).toBe(HilosAppearanceSettingKey.defaultTheme)

    fireEvent.click(byId('modal-confirm-cancel') as HTMLElement)
    expect(themeRadio('dark').checked).toBe(true)

    fireEvent.click(byId('appearance-default-theme-cancel') as HTMLElement)
    fireEvent.click(byId('modal-confirm-discard') as HTMLElement)
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
    fireEvent.submit(document.querySelector('[data-id="modal"] form')!)
    expect(h.sent).toEqual([])
  })
})

describe('HilosAppearancePage in the admin view mode', () => {
  it('lets a viewer open both windows and move their fields, and dispatches nothing', () => {
    const h = harness()
    h.viewer()
    openSwitching(h)
    fireEvent.click(switchingInput())
    expect(switchingInput().disabled).toBe(false)
    expect(switchingInput().checked).toBe(false)
    expect(switchingSave().disabled).toBe(true)
    expect(switchingSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    fireEvent.click(switchingSave())
    expect(h.sent).toEqual([])
    const cancel = byId('appearance-switching-cancel') as HTMLButtonElement
    expect(cancel.disabled).toBe(false)
    fireEvent.click(cancel)
    fireEvent.click(byId('modal-confirm-discard') as HTMLElement)
    expect(byId('modal')).toBeNull()

    fireEvent.click(pencil(HilosAppearanceSettingKey.defaultTheme))
    fireEvent.click(themeRadio('dark'))
    expect(themeRadio('dark').disabled).toBe(false)
    expect(themeRadio('dark').checked).toBe(true)
    expect(themeSave().disabled).toBe(true)
    expect(themeSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    fireEvent.click(themeSave())
    fireEvent.submit(document.querySelector('[data-id="modal"] form')!)
    expect(h.sent).toEqual([])
    expect(
      (byId('appearance-default-theme-cancel') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('shows the hidden mark in the table and instead of the fields of a hidden value', () => {
    const h = harness()
    h.viewer()
    renderPage(h)
    h.window(HIDDEN_VALUE, true, HIDDEN_VALUE, 'system')
    expect(
      document.querySelector(
        'table [data-id="appearance-setting-value-theme.switching_enabled"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      tableText('appearance-setting-default-theme.switching_enabled'),
    ).toBe('On')

    fireEvent.click(pencil(HilosAppearanceSettingKey.switchingEnabled))
    let modal = byId('modal') as HTMLElement
    expect(modal.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(modal.querySelector('input')).toBeNull()
    expect(switchingSave().disabled).toBe(true)
    fireEvent.click(byId('appearance-switching-cancel') as HTMLElement)
    expect(byId('modal-confirm')).toBeNull()
    expect(byId('modal')).toBeNull()

    fireEvent.click(pencil(HilosAppearanceSettingKey.defaultTheme))
    modal = byId('modal') as HTMLElement
    expect(modal.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(modal.querySelector('input')).toBeNull()
    expect(themeSave().disabled).toBe(true)
    expect(h.sent).toEqual([])
  })
})
